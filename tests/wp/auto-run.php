<?php
/**
 * The features of 1.10.0, against the fake services of the dev fixture (dev/test.sh): Google and DeepL with a real
 * glossary, the register of a language, the translation in the background, the estimate, the terms proposed by
 * machines and accepted by a person, the right-to-left languages and the strings of Complianz through the WPML API.
 */

add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Auto;
use LinguaBridge\Languages;
use LinguaBridge\MachineTranslation;
use LinguaBridge\Memory;
use LinguaBridge\Queue;
use LinguaBridge\Routing;
use LinguaBridge\Storage;
use LinguaBridge\Strings;
use LinguaBridge\Terms;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$saved_mt = get_option( MachineTranslation::OPTION );
$saved_settings = get_option( Languages::OPTION );
$settings = static function ( array $changes ) use ( $saved_settings ) {
	update_option( Languages::OPTION, array_merge( (array) $saved_settings, $changes ) );
};
$mock = 'http://wordpress/wp-json/lb-mock/v1';
$p = static fn( $text ) => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$created = array();
$terms = array();
$categories_before = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ) );
$sent = array();
$spy = static function ( $pre, $args, $url ) use ( &$sent ) {
	if ( false !== strpos( (string) $url, 'lb-mock' ) ) {
		$sent[] = array( 'url' => (string) $url, 'body' => json_decode( (string) $args['body'], true ) );
	}
	return $pre;
};
add_filter( 'pre_http_request', $spy, 10, 3 );
try {
	// C27: Google Cloud Translation with the owner's key in a header.
	add_filter( 'lingua_bridge_mt_google_url', static fn() => $mock . '/google' );
	MachineTranslation::save_settings( array( 'provider' => 'google', 'key' => 'lb-test-key' ) );
	$check( 'google' === MachineTranslation::settings()['provider'] && MachineTranslation::configured(), 'C27: Google is a service of its own' );
	$check( array( '[g en] Buongiorno', '[g en] Ciao' ) === MachineTranslation::translate_texts( array( 'Buongiorno', 'Ciao' ), 'it', 'en' ), 'C27: texts translated by Google, in order' );

	// C27 and C28: DeepL gets the site's glossary as a real glossary, made once, and the register of the language.
	add_filter( 'lingua_bridge_mt_deepl_url', static fn() => $mock . '/deepl/v2/translate' );
	MachineTranslation::save_settings( array( 'provider' => 'deepl', 'key' => 'lb-test-key' ) );
	$settings( array( 'translation_glossary' => array( array( 'source_language' => 'it', 'target_language' => 'en', 'source_term' => 'Chiostro', 'target_term' => 'Cloister' ) ), 'formality' => array( 'en' => 'informal' ) ) );
	delete_option( MachineTranslation::DEEPL_GLOSSARIES_OPTION );
	update_option( 'lb_mock_deepl_glossaries', 0, false );
	$first = MachineTranslation::translate_texts( array( 'Il chiostro' ), 'it', 'en' );
	$second = MachineTranslation::translate_texts( array( 'Il chiostro grande' ), 'it', 'en' );
	$check( is_array( $first ) && 1 === preg_match( '/^\[d gl-[0-9a-f]{6} prefer_less\] Il chiostro$/', (string) ( $first[0] ?? '' ) ), 'C27/C28: DeepL translates with the glossary id and the informal register: ' . wp_json_encode( $first ) );
	global $wpdb;
	$made_glossaries = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'lb_mock_deepl_glossaries'" ); // the fake service writes it in another process
	$check( is_array( $second ) && 1 === $made_glossaries, 'C27: the glossary is made once and reused for the same entries' );
	$settings( array( 'formality' => array() ) );
	$plain = MachineTranslation::translate_texts( array( 'Il chiostro' ), 'it', 'en' );
	$check( is_array( $plain ) && false === strpos( (string) $plain[0], 'prefer_' ), 'C28: without a register DeepL chooses' );

	// C28: the register in the prompt of the service compatible with OpenAI, and in the one of the agent.
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => $mock, 'model' => 'mock', 'key' => 'lb-test-key' ) );
	$settings( array( 'formality' => array( 'en' => 'formal' ) ) );
	$sent = array();
	MachineTranslation::translate_texts( array( 'Ciao' ), 'it', 'en' );
	$system = (string) ( $sent[0]['body']['messages'][0]['content'] ?? '' );
	$check( false !== strpos( $system, 'Registro in English: formale' ), 'C28: the prompt names the formal register' );
	$check( false !== strpos( Languages::formality_instruction( 'en' ), 'formale' ) && '' === Languages::formality_instruction( 'it' ), 'C28: one sentence per language, none when not chosen' );
	$settings( array() );

	// C34: the languages written from right to left, also without the language pack.
	$check( Languages::is_rtl( 'ar' ) && Languages::is_rtl( 'fa-af' ) && Languages::is_rtl( 'he' ) && ! Languages::is_rtl( 'en' ) && ! Languages::is_rtl( 'it' ), 'C34: ar, fa-af and he are right to left, en and it are not' );

	// C23: an original published goes to the queue of the service, as the administrator who turned it on.
	$settings( array( 'auto_translate' => array( 'en' => array( 'post' ) ), 'auto_user' => 1, 'auto_daily' => 2, 'auto_from' => '' ) );
	delete_option( Queue::OPTION );
	delete_option( Auto::COUNT_OPTION );
	wp_set_current_user( 0 );
	$one = wp_insert_post( wp_slash( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 1, 'post_title' => 'LB auto uno', 'post_content' => $p( 'LB auto frase uno' ) ) ) );
	$created[] = $one;
	$queued = array_values( array_filter( Queue::items(), static fn( $item ) => absint( $item['post'] ) === $one ) );
	$check( 1 === count( $queued ) && 'machine' === $queued[0]['action'] && 'en' === $queued[0]['language'] && 1 === (int) $queued[0]['user'], 'C23: the new original is queued for English, as the administrator' );
	$page = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => 1, 'post_title' => 'LB auto pagina', 'post_content' => $p( 'LB auto pagina' ) ) ) );
	$created[] = $page;
	$check( ! array_filter( Queue::items(), static fn( $item ) => absint( $item['post'] ) === $page ), 'C23: a type not chosen is not' );
	$draft = wp_insert_post( wp_slash( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => 1, 'post_title' => 'LB auto bozza', 'post_content' => $p( 'LB auto bozza' ) ) ) );
	$created[] = $draft;
	$check( ! array_filter( Queue::items(), static fn( $item ) => absint( $item['post'] ) === $draft ), 'C23: an original still a draft is not' );
	$two = wp_insert_post( wp_slash( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 1, 'post_title' => 'LB auto due', 'post_content' => $p( 'LB auto frase due' ) ) ) );
	$three = wp_insert_post( wp_slash( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 1, 'post_title' => 'LB auto tre', 'post_content' => $p( 'LB auto frase tre' ) ) ) );
	$created[] = $two;
	$created[] = $three;
	$check( 2 === count( Queue::items() ) && 0 === Auto::room_today(), 'C23: at most two a day: the third waits for tomorrow' );
	$settings( array( 'auto_translate' => array( 'en' => array( 'post' ) ), 'auto_user' => 1, 'auto_daily' => 30, 'auto_from' => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) );
	$old = wp_insert_post( wp_slash( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 1, 'post_title' => 'LB auto vecchio', 'post_content' => $p( 'LB auto vecchio' ) ) ) );
	$created[] = $old;
	$check( ! array_filter( Queue::items(), static fn( $item ) => absint( $item['post'] ) === $old ), 'C23: a content dated before the cutoff is not' );
	$settings( array( 'auto_translate' => array( 'en' => array( 'post' ) ), 'auto_user' => 1, 'auto_daily' => 30, 'auto_from' => '' ) );
	Queue::step( 0 );
	$member = Storage::find_group_language( Storage::find( $one )['group_uuid'] ?? '', 'en' );
	$translation = $member ? absint( $member['object_id'] ) : 0;
	if ( $translation ) {
		$created[] = $translation;
	}
	$check( $translation && 'draft' === get_post_status( $translation ) && '[en] LB auto frase uno' === wp_strip_all_tags( get_post( $translation )->post_content ), 'C23: the queue writes a draft, translated by the service' );
	$check( Auto::drafts_to_review() >= 1, 'C23: the dashboard counts the drafts of the service to review' );
	// Changed with a new sentence: the draft follows the new structure and the new sentence is translated.
	wp_update_post( wp_slash( array( 'ID' => $one, 'post_content' => $p( 'LB auto frase uno' ) . $p( 'LB auto frase nuova' ) ) ) );
	Queue::step( 0 );
	$check( '[en] LB auto frase uno[en] LB auto frase nuova' === wp_strip_all_tags( get_post( $translation )->post_content ) && 'draft' === get_post_status( $translation ), 'C23: an original changed: its draft updated and translated again, still a draft' );
	wp_update_post( array( 'ID' => $translation, 'post_status' => 'publish' ) );
	$before = count( Queue::items() );
	wp_update_post( wp_slash( array( 'ID' => $one, 'post_content' => $p( 'LB auto frase uno cambiata' ) ) ) );
	$check( count( Queue::items() ) === $before, 'C23: a published translation is never queued: it waits for a person' );
	foreach ( array( $two, $three ) as $source ) {
		$m = Storage::find_group_language( Storage::find( $source )['group_uuid'] ?? '', 'en' );
		if ( $m ) {
			$created[] = absint( $m['object_id'] );
		}
	}
	$settings( array() );
	wp_set_current_user( 1 );

	// C24: words and characters that would go to the service, without what the memory knows; the cost with a price.
	$estimate_id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB stima', 'post_content' => $p( 'LB stima una frase nota' ) . $p( 'LB stima tre parole' ) ) ) );
	$created[] = $estimate_id;
	Memory::learn( 'LB stima una frase nota', 'LB estimate a known sentence', 'en' );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => $mock, 'model' => 'mock', 'price' => '20' ) );
	$estimate = Auto::estimate( array( $estimate_id ), array( 'en' ) );
	$check( 1 === $estimate['contents'] && 6 === $estimate['words'] && mb_strlen( 'LB stima' . 'LB stima tre parole' ) === $estimate['characters'], 'C24: title and the sentence the memory does not know: ' . wp_json_encode( $estimate ) );
	$check( round( $estimate['characters'] / 1000000 * 20, 4 ) === $estimate['cost'], 'C24: the cost from the price per million characters' );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => $mock, 'model' => 'mock', 'price' => '0' ) );
	$check( null === Auto::estimate( array( $estimate_id ), array( 'en' ) )['cost'], 'C24: no price, no cost' );

	// C29: proposals of terms from the memory and the service; a person accepts them; nothing before.
	foreach ( array( 'LB Chiostri', 'LB Monasteri' ) as $name ) {
		$term = wp_insert_term( $name, 'category' );
		$terms[] = (int) $term['term_id'];
	}
	Memory::learn( 'LB Chiostri', 'LB Cloisters', 'en' );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => $mock, 'model' => 'mock', 'key' => 'lb-test-key' ) );
	$proposed = Terms::propose( 'category', 'en' );
	$proposals = static function ( $id ) {
		$value = get_term_meta( $id, Terms::PROPOSALS_META, true );
		return is_array( $value ) ? $value : array();
	};
	$check( $proposed >= 2 && 'LB Cloisters' === ( $proposals( $terms[0] )['en']['name'] ?? '' ) && 'memory' === ( $proposals( $terms[0] )['en']['from'] ?? '' ), 'C29: a proposal from the memory' );
	$check( '[en] LB Monasteri' === ( $proposals( $terms[1] )['en']['name'] ?? '' ) && 'service' === ( $proposals( $terms[1] )['en']['from'] ?? '' ) && 'en-lb-monasteri' === ( $proposals( $terms[1] )['en']['slug'] ?? '' ), 'C29: one from the service, with its slug' );
	$check( ! Terms::translation( $terms[0], 'en' ) && ! Terms::translation( $terms[1], 'en' ), 'C29: a proposal is no term' );
	ob_start();
	Terms::render_fields( get_term( $terms[1], 'category' ) );
	$fields = ob_get_clean();
	$check( false !== strpos( $fields, 'value="[en] LB Monasteri"' ) && false !== strpos( $fields, 'proposta' ), 'C29: the term screen shows the proposal in its fields' );
	// The XLIFF of the terms: a unit per term without its version; what comes back is a proposal too.
	$xml = Terms::export_xliff( 'category', 'en' );
	$check( false !== strpos( $xml, 'id="term:' . $terms[1] . '"' ) && false !== strpos( $xml, 'original="lingua-bridge:terms:category"' ), 'C29: the XLIFF names each term' );
	$back = str_replace( '<source>LB Monasteri</source>', '<source>LB Monasteri</source><target>LB Monasteries</target>', $xml );
	$imported = \LinguaBridge\Xliff::import( $back );
	$check( is_array( $imported ) && ! empty( $imported[0]['ok'] ) && 'LB Monasteries' === ( $proposals( $terms[1] )['en']['name'] ?? '' ) && 'xliff' === ( $proposals( $terms[1] )['en']['from'] ?? '' ), 'C29: the XLIFF of a translator becomes a proposal' );
	// Only the terms of the test are accepted: a version of News would take the English posts of the other suites.
	foreach ( $categories_before as $id ) {
		delete_term_meta( $id, Terms::PROPOSALS_META );
	}
	$accepted = Terms::accept( 'category', 'en' );
	$cloisters = Terms::translation( $terms[0], 'en' );
	$monasteries = Terms::translation( $terms[1], 'en' );
	if ( $cloisters ) {
		$terms[] = (int) $cloisters;
	}
	if ( $monasteries ) {
		$terms[] = (int) $monasteries;
	}
	$check( 2 === $accepted && $cloisters && 'LB Cloisters' === get_term( $cloisters )->name && $monasteries && 'lb-monasteries' === get_term( $monasteries )->slug, 'C29: «Accetta le proposte» makes the terms' );
	$check( empty( $proposals( $terms[0] ) ) && empty( $proposals( $terms[1] ) ), 'C29: and the proposals are gone' );
	wp_set_current_user( 0 );
	$check( 0 === Terms::propose( 'category', 'en' ), 'C29: nobody without the right to manage terms proposes' );
	wp_set_current_user( 1 );

	// C39: Complianz registers and translates its texts through the WPML API; without WPML, Lingua Bridge answers.
	do_action( 'wpml_register_single_string', 'complianz', 'lb_test_accept', 'LB Accetta i cookie', false, 'it' );
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . Strings::table() . " WHERE context = 'html' AND source = %s AND language = 'en'", 'LB Accetta i cookie' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( ! empty( $row['id'] ), 'C39: a text of Complianz becomes a string of the site' );
	do_action( 'wpml_register_single_string', 'another-plugin', 'x', 'LB Altro plugin', false, 'it' );
	$other = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Strings::table() . ' WHERE source = %s', 'LB Altro plugin' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 0 === (int) $other, 'C39: only the domains in the list (Complianz)' );
	Strings::save( (int) $row['id'], 'LB Accept the cookies', 'published' );
	$check( 'LB Accetta i cookie' === apply_filters( 'wpml_translate_single_string', 'LB Accetta i cookie', 'complianz', 'lb_test_accept' ), 'C39: on an Italian page the original' );
	// A page drawn in English (the prefix /en/ read by the router).
	$routed = new ReflectionProperty( Routing::class, 'request_language' );
	$routed->setAccessible( true );
	$routed->setValue( null, 'en' );
	$check( 'LB Accept the cookies' === apply_filters( 'wpml_translate_single_string', 'LB Accetta i cookie', 'complianz', 'lb_test_accept' ), 'C39: on an English page its translation' );
	$check( '<a href="#">LB Accetta i cookie</a>' === apply_filters( 'wpml_translate_single_string', '<a href="#">LB Accetta i cookie</a>', 'complianz', 'lb_test_link' ), 'C39: a text with markup is left to the page' );
	$routed->setValue( null, '' );
} finally {
	remove_filter( 'pre_http_request', $spy, 10 );
	foreach ( array_reverse( $created ) as $id ) {
		Storage::delete( $id );
		wp_delete_post( $id, true );
	}
	// The terms of the test and the versions «Accetta le proposte» made of the others (News, Uncategorized).
	$made_terms = array_diff( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'fields' => 'ids' ) ), $categories_before );
	foreach ( array_unique( array_merge( array_reverse( $terms ), $made_terms ) ) as $id ) {
		Terms::forget( $id );
		wp_delete_term( $id, 'category' );
	}
	foreach ( $categories_before as $id ) {
		delete_term_meta( $id, Terms::PROPOSALS_META );
	}
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Memory::table() . " WHERE source LIKE 'LB %'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DELETE FROM ' . Strings::table() . " WHERE source LIKE 'LB %'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( Queue::OPTION );
	delete_option( Auto::COUNT_OPTION );
	delete_option( MachineTranslation::DEEPL_GLOSSARIES_OPTION );
	delete_option( 'lb_mock_deepl_glossaries' );
	wp_clear_scheduled_hook( Queue::HOOK );
	false === $saved_mt ? delete_option( MachineTranslation::OPTION ) : update_option( MachineTranslation::OPTION, $saved_mt );
	update_option( Languages::OPTION, $saved_settings );
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
