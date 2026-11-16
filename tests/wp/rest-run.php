<?php
/**
 * The features of 1.11.0: x-default, the caches, the strings in PO, the sentences alike in the memory, the
 * translations assigned, a file per language and the index of the static search. Run by dev/test.sh with wp eval-file.
 */

add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Assignments;
use LinguaBridge\Languages;
use LinguaBridge\Media;
use LinguaBridge\Memory;
use LinguaBridge\Po;
use LinguaBridge\Purge;
use LinguaBridge\StaticSearch;
use LinguaBridge\Storage;
use LinguaBridge\Strings;
use LinguaBridge\Translations;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$saved_settings = get_option( Languages::OPTION );
$settings = static function ( array $changes ) use ( $saved_settings ) {
	update_option( Languages::OPTION, array_merge( (array) $saved_settings, $changes ) );
};
$p = static fn( $text ) => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$created = array();
$users = array();
global $wpdb;
try {
	// C12: x-default.
	$versions = array( 'it' => 'https://example.test/', 'en' => 'https://example.test/en/' );
	$check( 'https://example.test/' === Languages::x_default( $versions ), 'C12: of serie the original' );
	$settings( array( 'x_default' => 'en' ) );
	$check( 'https://example.test/en/' === Languages::x_default( $versions ) && 'https://example.test/' === Languages::x_default( array( 'it' => 'https://example.test/' ) ), 'C12: the chosen language, or the original where it has no version' );
	$settings( array( 'x_default' => 'none' ) );
	$check( '' === Languages::x_default( $versions ), 'C12: none' );
	$filter = static fn( $url, $all ) => $all['it'] ?? $url;
	add_filter( 'lingua_bridge_x_default', $filter, 10, 2 );
	$check( 'https://example.test/' === Languages::x_default( $versions ), 'C12: the filter has the last word' );
	remove_filter( 'lingua_bridge_x_default', $filter, 10 );
	$settings( array() );

	// C40: a content saved purges its other versions; WP Rocket and LiteSpeed only when they are there.
	$it = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB cache', 'post_content' => $p( 'LB cache' ) ) ) );
	$created[] = $it;
	$en = Translations::create_draft( $it, 'en' );
	$created[] = $en;
	wp_update_post( array( 'ID' => $en, 'post_status' => 'publish' ) );
	$purged = array();
	$litespeed = array();
	$spy = static function ( $urls ) use ( &$purged ) {
		$purged = $urls;
	};
	$ls = static function ( $url ) use ( &$litespeed ) {
		$litespeed[] = $url;
	};
	add_action( 'lingua_bridge_purge_urls', $spy );
	add_action( 'litespeed_purge_url', $ls );
	wp_update_post( wp_slash( array( 'ID' => $it, 'post_content' => $p( 'LB cache cambiata' ) ) ) );
	$check( array( get_permalink( $en ) ) === $purged, 'C40: saving the original purges the English version: ' . wp_json_encode( $purged ) );
	$check( array( get_permalink( $en ) ) === $litespeed, 'C40: and LiteSpeed is asked for the same address' );
	wp_update_post( array( 'ID' => $en, 'post_status' => 'draft' ) );
	$purged = array();
	wp_update_post( wp_slash( array( 'ID' => $it, 'post_content' => $p( 'LB cache di nuovo' ) ) ) );
	$check( array() === $purged, 'C40: a translation not published is no page of a cache' );
	remove_action( 'lingua_bridge_purge_urls', $spy );
	remove_action( 'litespeed_purge_url', $ls );
	$all = 0;
	$count_all = static function () use ( &$all ) {
		++$all;
	};
	add_action( 'lingua_bridge_purge_all', $count_all );
	Strings::register( 'html', 'LB stringa della cache' );
	$string_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Strings::table() . " WHERE language = 'en' AND source = %s", 'LB stringa della cache' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	Strings::save( $string_id, 'LB cache string', 'published' );
	Strings::save( $string_id, 'LB cache string 2', 'published' );
	$check( 1 === $all, 'C40: a string published purges everything, once per request' );
	remove_action( 'lingua_bridge_purge_all', $count_all );

	// C31: the strings in a PO file and back as drafts.
	Strings::register( 'menu', 'LB Chi siamo' );
	Strings::register( 'html', 'LB Leggi tutto "subito"' );
	$po = Po::export( 'en' );
	$check( false !== strpos( $po, "msgctxt \"menu\"\nmsgid \"LB Chi siamo\"\nmsgstr \"\"" ) && false !== strpos( $po, 'msgid "LB Leggi tutto \"subito\""' ) && false !== strpos( $po, 'msgstr "LB cache string 2"' ), 'C31: every string with its context, quotes escaped' );
	$back = str_replace( array( "msgid \"LB Chi siamo\"\nmsgstr \"\"", "msgid \"LB Leggi tutto \\\"subito\\\"\"\nmsgstr \"\"" ), array( "msgid \"LB Chi siamo\"\nmsgstr \"LB About \"\n\"us\"", "msgid \"LB Leggi tutto \\\"subito\\\"\"\nmsgstr \"LB Read all \\\"now\\\"\"" ), $po );
	$back .= "\nmsgid \"LB non esiste\"\nmsgstr \"LB does not exist\"\n";
	$back = str_replace( 'msgstr "LB cache string 2"', 'msgstr "LB changed by the file"', $back );
	$result = Po::import( $back, 'en' );
	$row = static fn( $context, $source ) => $wpdb->get_row( $wpdb->prepare( 'SELECT translation, status FROM ' . Strings::table() . " WHERE language = 'en' AND context = %s AND source = %s", $context, $source ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( is_array( $result ) && 2 === $result['drafts'] && 1 === $result['unknown'] && $result['published'] >= 1, 'C31: two drafts, one unknown, the published ones left alone: ' . wp_json_encode( $result ) );
	$check( array( 'translation' => 'LB About us', 'status' => 'draft' ) === $row( 'menu', 'LB Chi siamo' ) && 'LB Read all "now"' === $row( 'html', 'LB Leggi tutto "subito"' )['translation'], 'C31: multi-line and escaped texts read back, as drafts' );
	$check( 'LB cache string 2' === $row( 'html', 'LB stringa della cache' )['translation'], 'C31: a published translation is never replaced by a file' );
	$check( is_wp_error( Po::import( "\xff\xfe", 'en' ) ), 'C31: a file not in UTF-8 is refused' );

	// C25: a sentence alike in the memory, with how alike; never one too different, never the same.
	Memory::learn( 'LB La biblioteca apre alle nove del mattino', 'LB The library opens at nine in the morning', 'en' );
	Memory::learn( 'LB Il museo chiude a mezzanotte', 'LB The museum closes at midnight', 'en' );
	$alike = Memory::similar( array( 'LB La biblioteca apre alle dieci del mattino', 'LB Una frase che non somiglia a niente', 'LB La biblioteca apre alle nove del mattino' ), 'en' );
	$match = $alike['LB La biblioteca apre alle dieci del mattino'] ?? array();
	$check( 'LB The library opens at nine in the morning' === ( $match['target'] ?? '' ) && ( $match['percent'] ?? 0 ) >= 85 && ( $match['percent'] ?? 100 ) < 100, 'C25: the sentence alike, with its percent: ' . wp_json_encode( $match ) );
	$check( ! isset( $alike['LB Una frase che non somiglia a niente'] ) && ! isset( $alike['LB La biblioteca apre alle nove del mattino'] ), 'C25: nothing for a sentence too different, nor for the same one (the exact memory does that)' );
	$fuzzy_it = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB fuzzy', 'post_content' => $p( 'LB La biblioteca apre alle dieci del mattino' ) ) ) );
	$created[] = $fuzzy_it;
	$xml = \LinguaBridge\Xliff::export( array( $fuzzy_it ), 'en' )['xml'];
	$check( false !== strpos( $xml, 'state-qualifier="fuzzy-match"' ) && false !== strpos( $xml, 'LB The library opens at nine in the morning' ), 'C25: the XLIFF carries it as a target to review, marked fuzzy' );

	// C26: a translation assigned; the person is told, now or in the daily summary; «Le mie».
	$translator = wp_insert_user( array( 'user_login' => 'lb_assegnata', 'user_email' => 'lb_assegnata@example.test', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
	$users[] = $translator;
	$mails = array();
	$catch = static function ( $pre, $atts ) use ( &$mails ) {
		$mails[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $catch, 10, 2 );
	$check( Assignments::assign( $en, $translator ) && $translator === Assignments::assignee( $en ), 'C26: assigned' );
	$check( 1 === count( $mails ) && 'lb_assegnata@example.test' === $mails[0]['to'] && false !== strpos( $mails[0]['message'], 'LB cache' ), 'C26: the person is told by email' );
	$check( in_array( $it, Assignments::mine( $translator ), true ), 'C26: «Le mie» has the original' );
	Storage::update_review_state( $en, 'reviewed' );
	Storage::update_review_state( $en, 'needs_review' );
	$check( 2 === count( $mails ) && false !== strpos( $mails[1]['message'], 'rivedere' ), 'C26: told again when it goes to «da rivedere»' );
	$check( ! Assignments::assign( $en, 999999 ) || 0 === Assignments::assignee( $en ), 'C26: nobody outside the candidates' );
	$contributor = wp_insert_user( array( 'user_login' => 'lb_collaboratore', 'user_email' => 'lb_collaboratore@example.test', 'user_pass' => wp_generate_password(), 'role' => 'contributor' ) );
	$users[] = $contributor;
	$check( ! isset( Assignments::candidates( $it, 'en', $en )[ $contributor ] ) && isset( Assignments::candidates( $it, 'en', $en )[ $translator ] ), 'C26: only who can edit the translation (a contributor cannot)' );
	$settings( array( 'notify' => 'daily' ) );
	Assignments::assign( $en, 0 );
	Assignments::assign( $en, $translator );
	$check( 2 === count( $mails ) && 1 === count( (array) get_user_meta( $translator, Assignments::PENDING_META, true ) ), 'C26: daily: kept for the summary: ' . count( $mails ) . ' ' . wp_json_encode( get_user_meta( $translator, Assignments::PENDING_META, true ) ) );
	Assignments::send_daily();
	$check( 3 === count( $mails ) && '' === get_user_meta( $translator, Assignments::PENDING_META, true ), 'C26: the summary sent once, then empty' );
	$settings( array( 'notify' => 'off' ) );
	Assignments::assign( $en, 0 );
	Assignments::assign( $en, $translator );
	$check( 3 === count( $mails ), 'C26: off: no email' );
	remove_filter( 'pre_wp_mail', $catch, 10 );
	$settings( array() );

	// C30: another file for English.
	$upload = static function ( $name, $color ) {
		$image = imagecreatetruecolor( 400, 300 );
		imagefill( $image, 0, 0, $color );
		$path = get_temp_dir() . $name;
		imagepng( $image, $path );
		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $path ), 0 );
		return is_wp_error( $id ) ? 0 : $id;
	};
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$file_it = $upload( 'lb-cartello-it.png', 0x0000ff );
	$file_en = $upload( 'lb-cartello-en.png', 0xff0000 );
	$created[] = $file_it;
	$created[] = $file_en;
	Media::save( array( 'ID' => $file_it ), array( 'lingua_bridge_file_en' => (string) $file_en ) );
	$check( $file_en === Media::file_for( $file_it, 'en' ), 'C30: English uses another file' );
	Media::save( array( 'ID' => $file_it ), array( 'lingua_bridge_file_en' => (string) $file_it ) );
	$check( 0 === Media::file_for( $file_it, 'en' ), 'C30: never the file itself' );
	Media::save( array( 'ID' => $file_it ), array( 'lingua_bridge_file_en' => (string) $file_en ) );
	update_option( 'lb_test_c30', array( $file_it, $file_en ), false );

	// C8: the index of a language: its contents, never another language's, never a draft.
	$index = StaticSearch::index( 'en' );
	$titles = wp_list_pluck( $index, 't' );
	$check( in_array( 'LB cache', $titles, true ) === false && ! in_array( 'LB fuzzy', $titles, true ), 'C8: the English index has no Italian page nor a draft' );
	wp_update_post( array( 'ID' => $en, 'post_status' => 'publish' ) );
	$index = StaticSearch::index( 'en' );
	$check( in_array( 'LB cache', wp_list_pluck( $index, 't' ), true ) && 0 === strpos( (string) ( $index[0]['u'] ?? '' ), '/' ), 'C8: the English version is there, with a relative address' );
	$check( in_array( 'LB fuzzy', wp_list_pluck( StaticSearch::index( 'it' ), 't' ), true ), 'C8: the Italian index has the Italian page' );
} finally {
	foreach ( array_reverse( $created ) as $id ) {
		if ( in_array( $id, (array) get_option( 'lb_test_c30', array() ), true ) ) {
			continue; // the HTTP test of C30 uses them, then removes them.
		}
		Storage::delete( $id );
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) {
		wp_delete_user( $id );
	}
	$wpdb->query( 'DELETE FROM ' . Memory::table() . " WHERE source LIKE 'LB %'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DELETE FROM ' . Strings::table() . " WHERE source LIKE 'LB %'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	update_option( Languages::OPTION, $saved_settings );
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
