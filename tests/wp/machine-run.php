<?php
/**
 * Automatic translation with the owner's key, against the fake service of the dev fixture (dev/test.sh).
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\MachineTranslation;
use LinguaBridge\Memory;
use LinguaBridge\MenuSwitcher;
use LinguaBridge\Storage;
use LinguaBridge\Translations;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$saved_option = get_option( MachineTranslation::OPTION );
$p = static fn( $text ) => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$created = array();
try {
	// Addresses: https, or http on a loopback host only; never credentials in the URL.
	$check( MachineTranslation::allowed_endpoint( 'https://api.openai.com/v1' ), 'https is allowed' );
	$check( ! MachineTranslation::allowed_endpoint( 'http://api.example.com/v1' ), 'plain http to another host is not' );
	$check( MachineTranslation::allowed_endpoint( 'http://localhost:11434/v1' ), 'http on localhost is (a local model)' );
	$check( ! MachineTranslation::allowed_endpoint( 'https://user:pass@api.example.com/v1' ), 'credentials in the address are refused' );
	$check( is_wp_error( MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://evil.example/v1', 'model' => 'm' ) ) ), 'saving a refused address fails' );

	// The key: encrypted, never in the option as text.
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock', 'key' => 'lb-test-key' ) );
	$check( false === strpos( (string) wp_json_encode( get_option( MachineTranslation::OPTION ) ), 'lb-test-key' ), 'the key is stored encrypted' );
	$check( MachineTranslation::configured() && MachineTranslation::has_saved_key(), 'configured' );
	$out = MachineTranslation::translate_texts( array( 'Buongiorno', 'Ciao' ), 'it', 'en' );
	$check( array( '[en] Buongiorno', '[en] Ciao' ) === $out, 'texts translated through the service, in order: ' . wp_json_encode( $out ) );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock', 'key' => 'wrong-key-123' ) );
	$error = MachineTranslation::translate_texts( array( 'Ciao' ), 'it', 'en' );
	$check( is_wp_error( $error ) && false !== strpos( $error->get_error_message(), '401' ) && false === strpos( $error->get_error_message(), 'wrong-key-123' ), 'a refused key: an error that never shows the key' );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock', 'key' => 'lb-test-key' ) );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock' ) );
	$check( array( '[en] Ciao' ) === MachineTranslation::translate_texts( array( 'Ciao' ), 'it', 'en' ), 'saving without a key keeps the saved one' );

	// D10: a call that fails gives the budget back; the timeout and the extra parameters are the owner's to set.
	$spent = static fn() => (int) get_option( 'lingua_bridge_mt_spent_' . get_current_user_id() . '_' . gmdate( 'YmdH' ), 0 );
	$before_spent = $spent();
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock', 'key' => 'wrong-key-123' ) );
	$check( is_wp_error( MachineTranslation::translate_texts( array( 'Una frase che non passa' ), 'it', 'en' ) ) && $before_spent === $spent(), 'a failed call gives the characters back to the hourly budget' );
	$check( is_wp_error( MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock', 'extra' => '{"max_tokens": 10' ) ) ), 'extra parameters that are not JSON are refused' );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock', 'key' => 'lb-test-key', 'timeout' => '900', 'extra' => '{"max_tokens": 512, "chat_template_kwargs": {"enable_thinking": false}, "messages": "x", "model": "other"}' ) );
	$mt = MachineTranslation::settings();
	$check( 600 === $mt['timeout'] && array( 'max_tokens' => 512, 'chat_template_kwargs' => array( 'enable_thinking' => false ) ) === $mt['extra'], 'timeout at most 600 seconds; only the allowed extra keys are kept: ' . wp_json_encode( array( $mt['timeout'], $mt['extra'] ) ) );
	$sent = array();
	$spy = static function ( $pre, $args, $url ) use ( &$sent ) {
		if ( false !== strpos( $url, 'lb-mock' ) ) {
			$sent = array( 'timeout' => $args['timeout'], 'body' => json_decode( $args['body'], true ) );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $spy, 10, 3 );
	MachineTranslation::translate_texts( array( 'Ciao' ), 'it', 'en' );
	remove_filter( 'pre_http_request', $spy, 10 );
	$check( 600 === ( $sent['timeout'] ?? 0 ) && 512 === ( $sent['body']['max_tokens'] ?? 0 ) && false === ( $sent['body']['chat_template_kwargs']['enable_thinking'] ?? null ) && 'mock' === ( $sent['body']['model'] ?? '' ), 'the request carries the timeout and the extra parameters, never in place of the model or the messages' );
	MachineTranslation::save_settings( array( 'provider' => 'openai', 'endpoint' => 'http://wordpress/wp-json/lb-mock/v1', 'model' => 'mock' ) );

	// A draft: the sentences still in the original's words; never a published page.
	$it = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB MT pagina', 'post_content' => $p( 'LB MT uno' ) . $p( 'LB MT due' ) ) ) );
	$created[] = $it;
	$en = Translations::create_draft( $it, 'en' );
	$created[] = $en;
	wp_update_post( wp_slash( array( 'ID' => $en, 'post_content' => $p( 'LB MT one by hand' ) . $p( 'LB MT due' ) ) ) );
	$count = MachineTranslation::translate_post( $en );
	$post = get_post( $en );
	$check( 2 === $count && '[en] LB MT pagina' === $post->post_title && $p( 'LB MT one by hand' ) . $p( '[en] LB MT due' ) === $post->post_content, 'the draft: title and the untranslated sentence, the one done by hand kept' );
	$check( ! isset( Memory::lookup( array( 'LB MT due' ), 'en' )['LB MT due'] ), 'the memory does not learn a machine draft' );
	wp_publish_post( $en );
	Memory::learn_post( $en );
	$check( '[en] LB MT due' === ( Memory::lookup( array( 'LB MT due' ), 'en' )['LB MT due'] ?? '' ), 'it learns once published (reviewed)' );
	$check( is_wp_error( MachineTranslation::translate_post( $en ) ), 'a published translation is never machine-translated' );
	// C10: the SEO texts still in Italian go to the service too; a title keeps its plugin's variables.
	$seo_it = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB MT seo', 'post_content' => $p( 'LB MT seo frase' ) ) ) );
	update_post_meta( $seo_it, 'rank_math_description', 'Descrizione da tradurre' );
	update_post_meta( $seo_it, '_yoast_wpseo_title', '%%title%% %%sep%% Sito' );
	$seo_en = Translations::create_draft( $seo_it, 'en' );
	$created[] = $seo_en;
	$created[] = $seo_it;
	MachineTranslation::translate_post( $seo_en );
	$check( '[en] Descrizione da tradurre' === get_post_meta( $seo_en, 'rank_math_description', true ), 'C10: the SEO description is translated by the service' );
	$check( '[en] %%title%% %%sep%% Sito' === get_post_meta( $seo_en, '_yoast_wpseo_title', true ), 'C10: a title is translated with its plugin\'s variables as they were' );

	// F2: the queue. Contents go in at once; each step works a few of them as the user who queued them.
	delete_option( \LinguaBridge\Queue::OPTION );
	$q_one = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB coda uno', 'post_content' => $p( 'LB coda frase uno' ) ) ) );
	$q_two = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB coda due', 'post_content' => $p( 'LB coda frase due' ) ) ) );
	$created[] = $q_one;
	$created[] = $q_two;
	$check( 2 === \LinguaBridge\Queue::add( 'machine', array( $q_one, $q_two, $q_one ), 'en' ) && 0 === \LinguaBridge\Queue::add( 'machine', array( $q_one ), 'en' ), 'the queue takes each content once while it waits' );
	$check( 2 === \LinguaBridge\Queue::summary()['queued'] && wp_next_scheduled( \LinguaBridge\Queue::HOOK ), 'two waiting, and WP-Cron will work them' );
	\LinguaBridge\Queue::step( 0 );
	$q_summary = \LinguaBridge\Queue::summary();
	$q_draft = Storage::find_group_language( Storage::find( $q_one )['group_uuid'] ?? '', 'en' );
	$check( 2 === $q_summary['done'] && $q_draft && '[en] LB coda frase uno' === wp_strip_all_tags( get_post( $q_draft['object_id'] )->post_content ) && 'draft' === get_post_status( $q_draft['object_id'] ), 'a step: drafts created and translated by the service' );
	foreach ( array( $q_one, $q_two ) as $q_source ) {
		$q_member = Storage::find_group_language( Storage::find( $q_source )['group_uuid'], 'en' );
		if ( $q_member ) {
			$created[] = absint( $q_member['object_id'] );
		}
	}
	$q_author = wp_insert_user( array( 'user_login' => 'lb_queue_author', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
	wp_set_current_user( $q_author );
	\LinguaBridge\Queue::add( 'create', array( $q_two ), 'en' );
	wp_set_current_user( 1 );
	\LinguaBridge\Queue::step( 0 );
	wp_set_current_user( $q_author );
	$q_author_summary = \LinguaBridge\Queue::summary();
	wp_set_current_user( 1 );
	$check( 1 === $q_author_summary['failed'] && 1 === get_current_user_id(), 'a job runs with the permissions of whoever queued it (an author, on someone else\'s page: refused), then the user is back' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $q_author );
	delete_option( \LinguaBridge\Queue::OPTION );
	wp_clear_scheduled_hook( \LinguaBridge\Queue::HOOK );

	// The language switcher in the menu: automatic choice on a block theme.
	$check( 'nav-block' === MenuSwitcher::automatic(), 'block theme: the navigation block' );
} finally {
	foreach ( array_reverse( $created ) as $id ) {
		Storage::delete( $id );
		wp_delete_post( $id, true );
	}
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Memory::table() . " WHERE source LIKE 'LB %'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	false === $saved_option ? delete_option( MachineTranslation::OPTION ) : update_option( MachineTranslation::OPTION, $saved_option );
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
