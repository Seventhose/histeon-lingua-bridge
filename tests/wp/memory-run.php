<?php
/**
 * Translation memory, updates of changed sentences, independent pages (dev/test.sh).
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Memory;
use LinguaBridge\Storage;
use LinguaBridge\Translations;
use LinguaBridge\Updates;
use LinguaBridge\Xliff;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$p = static fn( $text ) => '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
$page = static fn( $title, $content, $status = 'publish' ) => wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => $status, 'post_title' => $title, 'post_content' => $content ) ) );
$created = array();
try {
	$check( array( 0 => 0, 2 => 2 ) === Updates::match( array( 'a', 'b', 'c' ), array( 'a', 'x', 'c' ) ), 'LCS: the unchanged sentences around an edit' );
	$check( array( 0 => 0, 2 => 1 ) === Updates::match( array( 'a', 'b' ), array( 'a', 'nuova', 'b' ) ), 'LCS: an added sentence' );

	// The memory learns from a translation that follows its original…
	$a = $page( 'LB mem A', $p( 'LB Contattaci' ) . $p( 'LB Orari di apertura' ) );
	$created[] = $a;
	$a_en = Translations::create_draft( $a, 'en' );
	$created[] = $a_en;
	wp_update_post( wp_slash( array( 'ID' => $a_en, 'post_title' => 'LB mem A EN', 'post_content' => $p( 'LB Contact us' ) . $p( 'LB Opening hours' ) ) ) );
	$check( array() === Memory::lookup( array( 'LB Orari di apertura', 'LB Contattaci' ), 'en' ), 'a draft teaches nothing (a contributor or a machine never reaches the translations of others)' );
	wp_publish_post( $a_en );
	$known = Memory::lookup( array( 'LB Orari di apertura', 'LB Contattaci' ), 'en' );
	$check( 'LB Opening hours' === ( $known['LB Orari di apertura'] ?? '' ) && 'LB Contact us' === ( $known['LB Contattaci'] ?? '' ), 'the memory learns the sentences of a published translation' );
	Memory::learn( 'LB Con markup', 'LB With <script>alert(1)</script><b>markup</b>', 'en' );
	$check( false === strpos( implode( '', Memory::lookup( array( 'LB Con markup' ), 'en' ) ), '<' ), 'markup never comes out of the memory' );

	// …and a new translation starts with them.
	$b = $page( 'LB mem B', $p( 'LB Orari di apertura' ) . $p( 'LB Altro testo' ) );
	$created[] = $b;
	$b_en = Translations::create_draft( $b, 'en' );
	$created[] = $b_en;
	$content = get_post( $b_en )->post_content;
	$check( false !== strpos( $content, '<p>LB Opening hours</p>' ) && false !== strpos( $content, '<p>LB Altro testo</p>' ), 'a new translation takes the known sentence, the rest stays to translate' );
	$check( 1 === (int) get_post_meta( $b_en, '_lingua_bridge_from_memory', true ), 'and says how many came from the memory' );
	$check( in_array( sha1( \LinguaBridge\Strings::normalize( 'LB Opening hours' ) ), (array) get_post_meta( $b_en, Memory::FILLED_META, true ), true ), 'what the memory filled in is marked to review' );
	$fresh = $page( 'LB mem F', $p( 'LB Contattaci' ) );
	$created[] = $fresh;
	$check( false !== strpos( Xliff::export( array( $fresh ), 'en' )['xml'], '<target state="needs-review-translation" state-qualifier="tm-suggestion">LB Contact us</target>' ), 'XLIFF carries what the memory knows, to review' );

	// Updates: the original changes, only the changed sentences are to redo.
	wp_update_post( wp_slash( array( 'ID' => $b_en, 'post_content' => $p( 'LB Opening hours' ) . $p( 'LB Other text' ) ) ) );
	$check( ! Translations::is_stale( Storage::find( $b_en ) ), 'a translation just made is up to date' );
	wp_update_post( wp_slash( array( 'ID' => $b, 'post_content' => $p( 'LB Orari di apertura' ) . $p( 'LB Altro testo nuovo' ) . $p( 'LB Paragrafo aggiunto' ) ) ) );
	$check( Translations::is_stale( Storage::find( $b_en ) ), 'the original changed: the translation lags behind' );
	$changes = Updates::changes( $b_en );
	$check( $changes['known'] && $changes['aligned'] && 2 === count( $changes['changes'] ), 'two sentences changed or added, found by comparing with the picture of the original' );
	$edit = $changes['changes'][0];
	$check( 'LB Altro testo' === $edit['before'] && 'LB Other text' === $edit['translation'] && 'LB Altro testo nuovo' === $edit['original'], 'an edited sentence: before, now, and its translation' );
	$check( '' === $changes['changes'][1]['before'], 'an added sentence has no before' );
	$counts = Updates::rebuild_draft( $b_en );
	$content = get_post( $b_en )->post_content;
	$check( ! is_wp_error( $counts ) && 1 === $counts['kept'] && 2 === $counts['todo'], 'the draft is rebuilt: one kept, two to translate' );
	$check( $p( 'LB Opening hours' ) . $p( 'LB Altro testo nuovo' ) . $p( 'LB Paragrafo aggiunto' ) === $content, 'on the new structure, the changed sentences in the original\'s words' );
	$check( ! Translations::is_stale( Storage::find( $b_en ) ), 'and it is up to date again' );

	// A published translation is never rebuilt; it is marked updated when done.
	wp_publish_post( $a_en );
	wp_update_post( wp_slash( array( 'ID' => $a, 'post_content' => $p( 'LB Contattaci ora' ) . $p( 'LB Orari di apertura' ) ) ) );
	$check( is_wp_error( Updates::rebuild_draft( $a_en ) ), 'a published translation is not rebuilt (no untranslated text goes online)' );
	$check( 'LB Contact us' === Updates::changes( $a_en )['changes'][0]['translation'], 'its changes lead to the sentence to edit' );
	Updates::mark_updated( $a_en );
	$check( ! Translations::is_stale( Storage::find( $a_en ) ), '«Segna come aggiornata»' );

	// Independent pages: blank or linked, never «to update», teach nothing to the memory.
	$c = $page( 'LB mem C', $p( 'LB Pagina C' ) );
	$created[] = $c;
	$c_en = Translations::create_draft( $c, 'en', 'blank' );
	$created[] = $c_en;
	$check( '' === get_post( $c_en )->post_content && Translations::is_independent( $c_en ), 'a blank page: empty content, independent' );
	wp_update_post( wp_slash( array( 'ID' => $c, 'post_content' => $p( 'LB Pagina C cambiata' ) ) ) );
	$check( ! Translations::is_stale( Storage::find( $c_en ) ), 'an independent page is never «to update»' );
	$check( 0 === Memory::learn_post( $c_en ), 'and teaches nothing to the memory' );
	$d = $page( 'LB mem D', $p( 'LB Pagina D' ) );
	$created[] = $d;
	$e = $page( 'LB other page', $p( 'LB A different page' ) );
	$created[] = $e;
	$linked = Translations::link_existing( $d, $e, 'en' );
	$check( $e === $linked && Translations::is_independent( $e ) && 'en' === Storage::find( $e )['language'], 'an existing page linked as the English version, independent' );
	$check( is_wp_error( Translations::link_existing( $d, $a, 'en' ) ), 'a page already linked cannot be linked again' );
	$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB post' ) );
	$created[] = $post;
	$check( is_wp_error( Translations::link_existing( $c, $post, 'de' ) ), 'only a content of the same type' );

	// F5: who wrote a sentence; a person's is never replaced by a machine's; the «Memoria» screen; TMX.
	Memory::learn( 'LB F5 frase', 'LB F5 by a person', 'en', 'person' );
	Memory::learn( 'LB F5 frase', 'LB F5 by a machine', 'en', 'service' );
	$check( 'LB F5 by a person' === ( Memory::lookup( array( 'LB F5 frase' ), 'en' )['LB F5 frase'] ?? '' ), 'a person\'s sentence is never replaced by a machine\'s' );
	Memory::learn( 'LB F5 altra', 'LB F5 by the agent', 'en', 'agent' );
	Memory::learn( 'LB F5 altra', 'LB F5 corrected', 'en', 'person' );
	$f5_found = Memory::search( 'en', 'LB F5' );
	$f5_rows = array_column( $f5_found['items'], null, 'source' );
	$check( 2 === $f5_found['total'] && 'person' === $f5_rows['LB F5 altra']['origin'] && 'LB F5 corrected' === $f5_rows['LB F5 altra']['target'], 'the screen finds the sentences, a person replaces a machine\'s' );
	Memory::correct( absint( $f5_rows['LB F5 frase']['id'] ), 'LB F5 fixed' );
	$check( 'LB F5 fixed' === ( Memory::lookup( array( 'LB F5 frase' ), 'en' )['LB F5 frase'] ?? '' ), 'a sentence corrected on the screen' );
	$f5_tmx = Memory::export_tmx( 'en' );
	Memory::forget( absint( $f5_rows['LB F5 frase']['id'] ) );
	$check( ! isset( Memory::lookup( array( 'LB F5 frase' ), 'en' )['LB F5 frase'] ), 'a sentence forgotten' );
	$check( Memory::import_tmx( $f5_tmx, 'en' ) >= 2 && 'LB F5 fixed' === ( Memory::lookup( array( 'LB F5 frase' ), 'en' )['LB F5 frase'] ?? '' ), 'a TMX file goes out and comes back' );
	$check( is_wp_error( Memory::import_tmx( '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><tmx>&e;</tmx>', 'en' ) ), 'a TMX with a DOCTYPE is refused' );
	$f5_src = $page( 'LB F5 pagina', $p( 'LB F5 dalla macchina' ) );
	$created[] = $f5_src;
	$f5_draft = Translations::create_or_update_draft( $f5_src, 'en', array( 'title' => 'LB F5 page', 'content' => $p( 'LB F5 from the machine' ) ) );
	$created[] = $f5_draft;
	wp_publish_post( $f5_draft );
	Memory::learn_post( $f5_draft );
	$f5_machine = array_column( Memory::search( 'en', 'LB F5 dalla macchina' )['items'], 'origin' );
	$check( array( 'agent' ) === $f5_machine, 'what an agent wrote is learned as the agent\'s, even once a person published it' );
} finally {
	foreach ( array_reverse( $created ) as $id ) {
		Storage::delete( $id );
		wp_delete_post( $id, true );
	}
	global $wpdb;
	$wpdb->query( 'DELETE FROM ' . Memory::table() . " WHERE source LIKE 'LB %'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
