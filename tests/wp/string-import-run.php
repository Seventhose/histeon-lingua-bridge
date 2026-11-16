<?php
/**
 * Strings from Polylang, WPML and TranslatePress (dev/test.sh): their data as they store it, made here and
 * removed at the end, read without the plugins.
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\StringImport;
use LinguaBridge\Strings;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
global $wpdb;
wp_set_current_user( 1 );
$translation_of = static function ( $context, $source ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT translation, status FROM ' . Strings::table() . " WHERE language = 'en' AND source_hash = %s", Strings::key( $context, $source ) ), ARRAY_A );
};
$made = array( 'posts' => array(), 'terms' => array() );
try {
	// Polylang: a language term and its polylang_mo post.
	$wpdb->insert( $wpdb->terms, array( 'name' => 'English', 'slug' => 'en', 'term_group' => 0 ) );
	$term = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $term, 'taxonomy' => 'language', 'description' => '', 'parent' => 0, 'count' => 0 ) );
	$made['terms'][] = $term;
	$mo = wp_insert_post( array( 'post_type' => 'polylang_mo', 'post_status' => 'private', 'post_title' => 'polylang_mo_' . $term ) );
	$made['posts'][] = $mo;
	update_post_meta( $mo, '_pll_strings_translations', array( array( 'LB Polylang widget', 'LB Polylang widget EN' ), array( 'Lingua Bridge Dev', 'Lingua Bridge Dev (EN)' ) ) );

	// WPML.
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}icl_strings (id bigint unsigned NOT NULL AUTO_INCREMENT, language varchar(7) NOT NULL, context varchar(160) NOT NULL, name varchar(160) NOT NULL, value longtext NOT NULL, status tinyint NOT NULL DEFAULT 0, PRIMARY KEY (id))" );
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}icl_string_translations (id bigint unsigned NOT NULL AUTO_INCREMENT, string_id bigint unsigned NOT NULL, language varchar(10) NOT NULL, status tinyint NOT NULL, value longtext, PRIMARY KEY (id))" );
	$wpdb->insert( $wpdb->prefix . 'icl_strings', array( 'language' => 'it', 'context' => 'Widgets', 'name' => 'title', 'value' => 'LB WPML completa' ) );
	$wpdb->insert( $wpdb->prefix . 'icl_string_translations', array( 'string_id' => $wpdb->insert_id, 'language' => 'en', 'status' => 10, 'value' => 'LB WPML complete' ) );
	$wpdb->insert( $wpdb->prefix . 'icl_strings', array( 'language' => 'it', 'context' => 'Widgets', 'name' => 'text', 'value' => 'LB WPML in corso' ) );
	$wpdb->insert( $wpdb->prefix . 'icl_string_translations', array( 'string_id' => $wpdb->insert_id, 'language' => 'en', 'status' => 2, 'value' => 'LB WPML in progress' ) );

	// TranslatePress: a dictionary (one theme text, one text of a content) and a gettext table.
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}trp_dictionary_it_it_en_us (id bigint NOT NULL AUTO_INCREMENT, original text NOT NULL, translated text, status int NOT NULL DEFAULT 0, block_type int DEFAULT 0, original_id bigint, PRIMARY KEY (id))" );
	$wpdb->insert( $wpdb->prefix . 'trp_dictionary_it_it_en_us', array( 'original' => 'LB TP footer', 'translated' => 'LB TP footer EN', 'status' => 2 ) );
	$wpdb->insert( $wpdb->prefix . 'trp_dictionary_it_it_en_us', array( 'original' => 'LB TP macchina', 'translated' => 'LB TP machine', 'status' => 1 ) );
	$wpdb->insert( $wpdb->prefix . 'trp_dictionary_it_it_en_us', array( 'original' => 'LB-ABOUT-IT', 'translated' => 'should not be a string', 'status' => 2 ) );
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}trp_gettext_en_us (id bigint NOT NULL AUTO_INCREMENT, original text NOT NULL, translated text, domain text, status int, PRIMARY KEY (id))" );
	$wpdb->insert( $wpdb->prefix . 'trp_gettext_en_us', array( 'original' => 'LB TP pulsante', 'translated' => 'LB TP button', 'domain' => 'theme', 'status' => 2 ) );

	$found = StringImport::detect();
	$check( $found['polylang'] >= 1 && $found['wpml'] >= 2 && $found['translatepress'] >= 2, 'the three sources are found in the database' );

	$stats = StringImport::run( 'polylang' );
	$check( 'LB Polylang widget EN' === ( $translation_of( 'html', 'LB Polylang widget' )['translation'] ?? '' ), 'Polylang: a string, published' );
	$check( 'published' === ( $translation_of( 'option', 'Lingua Bridge Dev' )['status'] ?? '' ) && $stats['skipped'] >= 1, 'Polylang: the site title is an option string, and one already published here is kept' );

	StringImport::run( 'wpml' );
	$check( 'published' === ( $translation_of( 'html', 'LB WPML completa' )['status'] ?? '' ), 'WPML: a complete translation is published' );
	$check( 'draft' === ( $translation_of( 'html', 'LB WPML in corso' )['status'] ?? '' ), 'WPML: an incomplete one is a draft' );

	StringImport::run( 'translatepress' );
	$check( 'published' === ( $translation_of( 'html', 'LB TP footer' )['status'] ?? '' ) && 'published' === ( $translation_of( 'html', 'LB TP pulsante' )['status'] ?? '' ), 'TranslatePress: human translations (dictionary and gettext) are published' );
	$check( 'draft' === ( $translation_of( 'html', 'LB TP macchina' )['status'] ?? '' ), 'TranslatePress: a machine translation is a draft' );
	$check( null === $translation_of( 'html', 'LB-ABOUT-IT' ), 'TranslatePress: a text of a content is not made a string' );
} finally {
	foreach ( array( 'LB Polylang widget', 'LB WPML completa', 'LB WPML in corso', 'LB TP footer', 'LB TP macchina', 'LB TP pulsante' ) as $source ) {
		$wpdb->delete( Strings::table(), array( 'source_hash' => Strings::key( 'html', $source ) ) );
	}
	foreach ( $made['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $made['terms'] as $id ) {
		$wpdb->delete( $wpdb->term_taxonomy, array( 'term_id' => $id ) );
		$wpdb->delete( $wpdb->terms, array( 'term_id' => $id ) );
	}
	foreach ( array( 'icl_strings', 'icl_string_translations', 'trp_dictionary_it_it_en_us', 'trp_gettext_en_us' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}$table" );
	}
	update_option( 'lingua_bridge_strings_version', (int) get_option( 'lingua_bridge_strings_version', 0 ) + 1 );
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
