<?php
/**
 * XLIFF on a real site (dev/test.sh): segments and back without changing a byte; a full round trip; refusals.
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Storage;
use LinguaBridge\Xliff;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$created = array();
try {
	// Segment and rebuild: the same content, byte for byte.
	$gutenberg = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Chi siamo</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Una comunità di <strong>studenti</strong> &amp; docenti.</p>\n<!-- /wp:paragraph --><!-- wp:image {\"id\":5} --><figure><img src=\"a.jpg\" alt=\"Il chiostro\"/></figure><!-- /wp:image --><script>var x = \"testo\";</script>";
	$histos = '<!-- wp:xb/element {"uid":"e1","tag":"section","attributes":{"aria-label":"Sezione"}} --><!-- wp:xb/text {"uid":"t1","content":"Prezzi \u0026 orari"} /--><!-- wp:xb/text {"uid":"t2","content":"{post.title}"} /--><!-- /wp:xb/element -->';
	foreach ( array( 'Gutenberg' => $gutenberg, 'Histeon' => $histos ) as $label => $content ) {
		$parts = Xliff::segment( $content );
		$back = Xliff::rebuild( $parts['template'], $parts['segments'], array() );
		$check( $content === $back, "$label: segments and back, unchanged" . ( $content === $back ? '' : "\n  in:  $content\n  out: $back" ) );
	}
	$g = Xliff::segment( $gutenberg );
	$check( array( 'Chi siamo', 'Una comunità di', 'studenti', '& docenti.', 'Il chiostro' ) === array_column( $g['segments'], 'text' ), 'Gutenberg: the visible texts, decoded, not the script' );
	$h = Xliff::segment( $histos );
	$check( array( 'Prezzi & orari' ) === array_column( $h['segments'], 'text' ), 'Histeon: text values of the blocks; an expression alone is not a text' );
	$rebuilt = Xliff::rebuild( $h['template'], $h['segments'], array( 'Prices & "hours" <now>' ) );
	$check( false !== strpos( $rebuilt, '"content":"Prices \u0026 \u0022hours\u0022"' ), 'a translated JSON text is escaped as WordPress serializes it' );
	$check( false === strpos( $rebuilt, 'now' ) && false === strpos( $rebuilt, '<now' ), 'markup in a translated JSON text is dropped, never stored' );

	// A rich prop of a Histeon component keeps its inline formats in the translation, and only those.
	$component = '<!-- wp:xb/component {"uid":"c1","ref":"articolo/riquadro","props":{"title":"Attenzione qui","text":"Un testo \u003cstrong\u003eforte\u003c/strong\u003e e un \u003ca href=\u0022https://esempio.it\u0022\u003elink\u003c/a\u003e"}} /-->';
	$c = Xliff::segment( $component );
	$check( array( 'json', 'json-inline' ) === array_column( $c['segments'], 'kind' ), 'Histeon component: a prop with inline formats is a rich segment, a plain one is not' );
	$check( $component === Xliff::rebuild( $c['template'], $c['segments'], array() ), 'Histeon component: segments and back, unchanged' );
	$translated = Xliff::rebuild( $c['template'], $c['segments'], array( 'Watch <b>out</b>', 'A <strong>strong</strong> text <script>alert(1)</script> and a <a href="javascript:alert(1)">bad</a> or <a href="https://example.com" onclick="x()">good</a> link' ) );
	$blocks = parse_blocks( $translated );
	$props = $blocks[0]['attrs']['props'] ?? array();
	$check( 'Watch out' === ( $props['title'] ?? null ), 'a plain prop stays plain text' );
	$check( false !== strpos( (string) ( $props['text'] ?? '' ), '<strong>strong</strong>' ) && false !== strpos( (string) ( $props['text'] ?? '' ), '<a href="https://example.com">good</a>' ), 'a rich prop keeps bold and safe links' . "\n  got: " . ( $props['text'] ?? '' ) );
	$check( false === strpos( (string) ( $props['text'] ?? '' ), '<script' ) && false === strpos( (string) ( $props['text'] ?? '' ), 'javascript:' ) && false === strpos( (string) ( $props['text'] ?? '' ), 'onclick' ), 'a rich prop drops scripts, javascript: links and handlers' );
	$check( false === strpos( $translated, '<strong>' ) && false === strpos( $translated, '--' . '>' . '<' ), 'the block comment still holds no raw angle brackets' );

	// A round trip: export, translate, import.
	$source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB XLIFF pagina', 'post_excerpt' => 'Un estratto', 'post_content' => $gutenberg ) );
	$created[] = $source;
	$export = Xliff::export( array( $source ), 'en' );
	$check( 1 === $export['count'] && false !== strpos( $export['xml'], '<source>Il chiostro</source>' ) && false === strpos( $export['xml'], 'var x' ), 'export: one file, clean segments, no code' );
	$translations = array( 'LB XLIFF pagina' => 'LB XLIFF page', 'Un estratto' => 'An excerpt', 'Chi siamo' => 'About us', 'Una comunità di' => 'A community of', 'studenti' => 'students', '& docenti.' => '& teachers.', 'Il chiostro' => 'The cloister' );
	$doc = new DOMDocument();
	$doc->loadXML( $export['xml'] );
	foreach ( $doc->getElementsByTagNameNS( Xliff::NS, 'trans-unit' ) as $tu ) {
		$source_text = $tu->getElementsByTagNameNS( Xliff::NS, 'source' )->item( 0 )->textContent;
		$target = $doc->createElementNS( Xliff::NS, 'target' );
		$target->appendChild( $doc->createTextNode( $translations[ $source_text ] ?? '' ) );
		$tu->appendChild( $target );
	}
	$results = Xliff::import( $doc->saveXML() );
	$draft = $results[0]['draft'] ?? 0;
	$created[] = $draft;
	$post = get_post( $draft );
	$check( $results[0]['ok'] && $post && 'draft' === $post->post_status && 'LB XLIFF page' === $post->post_title && 'An excerpt' === $post->post_excerpt, 'import: a draft with the translated title and excerpt' );
	$expected = str_replace( array( 'Chi siamo', 'Una comunità di', 'studenti', '&amp; docenti.', 'Il chiostro' ), array( 'About us', 'A community of', 'students', '&amp; teachers.', 'The cloister' ), $gutenberg );
	$check( $post && $expected === $post->post_content, 'import: the original structure with the translated texts' );
	$check( $post && 'en' === Storage::find( $draft )['language'], 'import: linked as English' );
	$again = Xliff::import( $doc->saveXML() );
	$check( $again[0]['ok'] && $again[0]['draft'] === $draft, 'importing again updates the same draft' );
	// Exporting again pre-fills the targets with the translation.
	$check( false !== strpos( Xliff::export( array( $source ), 'en' )['xml'], '<target state="translated">The cloister</target>' ), 'a new export carries the existing translation' );
	// A new draft is a copy of the original: its texts are not translated yet, and a CAT tool must offer them.
	$fresh_source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB XLIFF fresh', 'post_content' => '<!-- wp:paragraph --><p>Una frase ancora da tradurre</p><!-- /wp:paragraph -->' ) );
	$fresh_draft = \LinguaBridge\Translations::create_draft( $fresh_source, 'en' );
	$created[] = $fresh_source;
	$created[] = $fresh_draft;
	$fresh_xml = Xliff::export( array( $fresh_source ), 'en' )['xml'];
	$check( false === strpos( $fresh_xml, 'state="translated"' ) && false !== strpos( $fresh_xml, 'Una frase ancora da tradurre' ), 'a draft still equal to its original gives no «translated» targets' );

	// The edges of a segment: a space the translation needs where the original had none («dell’<a>» → «of <a>»), a
	// space the translator left out where the original had one, never two; quotes of a shortcode stay real quotes.
	$edges = '<!-- wp:paragraph --><p>Gli eventi dell’<a href="/x/">11 settembre</a> e <strong>l’</strong>esito, "detto" così.</p><!-- /wp:paragraph -->'
		. '<!-- wp:shortcode -->[fluentform id="5"]<!-- /wp:shortcode --><!-- wp:paragraph --><p>Scrivici: [contatti tipo=\'breve\']</p><!-- /wp:paragraph -->'
		. '<!-- wp:xb/text {"uid":"t9","content":"Vai a "} /-->';
	$edges_parts = Xliff::segment( $edges );
	$edges_back = Xliff::rebuild( $edges_parts['template'], $edges_parts['segments'], array() );
	$check( $edges === $edges_back, 'quotes and shortcodes: segments and back, unchanged' . ( $edges === $edges_back ? '' : "\n  out: $edges_back" ) );
	$edges_source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB XLIFF bordi', 'post_content' => wp_slash( $edges ) ) );
	$created[] = $edges_source;
	$edges_doc = new DOMDocument();
	$edges_doc->loadXML( Xliff::export( array( $edges_source ), 'en' )['xml'] );
	$edges_translations = array( 'LB XLIFF bordi' => 'LB XLIFF edges', 'Gli eventi dell’' => 'The events of ', '11 settembre' => '11 September', 'e' => ' and ', 'l’' => 'the ', 'esito, "detto" così.' => 'outcome, "so" called.', 'Scrivici: [contatti tipo=\'breve\']' => 'Write to us: [contatti tipo=\'breve\']', 'Vai a ' => 'Go to' );
	foreach ( $edges_doc->getElementsByTagNameNS( Xliff::NS, 'trans-unit' ) as $tu ) {
		$text = $tu->getElementsByTagNameNS( Xliff::NS, 'source' )->item( 0 )->textContent;
		$target = $edges_doc->createElementNS( Xliff::NS, 'target' );
		$target->appendChild( $edges_doc->createTextNode( $edges_translations[ $text ] ?? $text ) );
		$tu->appendChild( $target );
	}
	$edges_result = Xliff::import( $edges_doc->saveXML() );
	$created[] = $edges_result[0]['draft'] ?? 0;
	$edges_post = get_post( $edges_result[0]['draft'] ?? 0 );
	$edges_content = $edges_post ? $edges_post->post_content : '';
	$check( false !== strpos( $edges_content, '<p>The events of <a href="/x/">11 September</a> and <strong>the </strong>outcome' ), 'import: a space at the edge of a translated segment stays, and is never doubled' . "\n  got: $edges_content" );
	$check( false !== strpos( $edges_content, '"content":"Go to "' ), 'import: a space at the edge of the original\'s text, left out by the translator, comes back' );
	$check( false !== strpos( $edges_content, '[fluentform id="5"]' ) && false !== strpos( $edges_content, '[contatti tipo=\'breve\']' ) && false === strpos( $edges_content, '&quot;' ) && false === strpos( $edges_content, '&#039;' ), 'import: the attributes of a shortcode keep their real quotes' );

	// Refusals.
	$check( is_wp_error( Xliff::import( '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><xliff>&e;</xliff>' ) ), 'DOCTYPE and entities are refused' );
	$utf16 = "\xFF\xFE" . mb_convert_encoding( '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE x [<!ENTITY e "x">]><xliff>&e;</xliff>', 'UTF-16LE', 'UTF-8' );
	$check( is_wp_error( Xliff::import( $utf16 ) ), 'a DOCTYPE in UTF-16 is refused too' );
	$private = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'LB segreto di un altro', 'post_author' => 1 ) );
	$created[] = $private;
	wp_update_post( array( 'ID' => $source, 'post_title' => 'LB XLIFF pagina cambiata' ) );
	$changed = Xliff::import( $doc->saveXML() );
	$check( ! $changed[0]['ok'], 'a file from an older version of the original is refused' );
	$author = wp_insert_user( array( 'user_login' => 'lb_xliff_' . wp_generate_password( 5, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'author' ) );
	wp_set_current_user( $author );
	$denied = Xliff::import( $doc->saveXML() );
	$check( ! $denied[0]['ok'] && 0 === $denied[0]['post'], 'an author cannot import into someone else\'s page, nor learn which it is' );
	$probe = Xliff::import( '<?xml version="1.0" encoding="UTF-8"?><xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2"><file original="lingua-bridge:post:' . $private . '" source-language="it" target-language="en" datatype="plaintext"><body/></file></xliff>' );
	$check( 0 === $probe[0]['post'], 'the result never names a content the user cannot edit' );
	$check( 0 === Xliff::export( array( $source ), 'en' )['count'], 'an author cannot export someone else\'s page' );
	wp_set_current_user( 1 );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $author );

	// F6 and F7: the glossary as a note for the translator, the texts left as the original, a TBX, listWork's offset.
	$f7_settings = get_option( \LinguaBridge\Languages::OPTION );
	update_option( \LinguaBridge\Languages::OPTION, array_merge( (array) $f7_settings, array( 'translation_glossary' => array( array( 'source_language' => 'it', 'source_term' => 'Chiostro', 'target_language' => 'en', 'target_term' => 'Cloister', 'note' => 'maiuscolo' ) ) ) ) );
	$f7_source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB XLIFF F7', 'post_content' => '<!-- wp:paragraph --><p>Il Chiostro</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Roma 2026</p><!-- /wp:paragraph -->' ) );
	$created[] = $f7_source;
	$f7_xml = Xliff::export( array( $f7_source ), 'en' )['xml'];
	$check( false !== strpos( $f7_xml, '<note from="lingua-bridge-glossary">' ) && false !== strpos( $f7_xml, 'Chiostro → Cloister (maiuscolo)' ), 'F7: the glossary goes in the file as a note for the translator' );
	$f7_doc = new DOMDocument();
	$f7_doc->loadXML( $f7_xml );
	foreach ( $f7_doc->getElementsByTagNameNS( Xliff::NS, 'trans-unit' ) as $tu ) {
		$text = $tu->getElementsByTagNameNS( Xliff::NS, 'source' )->item( 0 )->textContent;
		$target = $f7_doc->createElementNS( Xliff::NS, 'target' );
		$target->appendChild( $f7_doc->createTextNode( 'Il Chiostro' === $text ? 'The Cloister' : $text ) );
		$tu->appendChild( $target );
	}
	$f7_result = Xliff::import( $f7_doc->saveXML() );
	$created[] = $f7_result[0]['draft'] ?? 0;
	$check( $f7_result[0]['ok'] && 2 === $f7_result[0]['same'], 'F7: the import counts the texts left as the original (the title and «Roma 2026»)' );
	update_option( \LinguaBridge\Languages::OPTION, $f7_settings );
	$f6_tbx = \LinguaBridge\Languages::glossary_from_file( '<?xml version="1.0" encoding="UTF-8"?><tbx type="TBX-Basic"><text><body><termEntry><langSet xml:lang="it"><tig><term>Refettorio</term></tig></langSet><langSet xml:lang="en-US"><tig><term>Refectory</term></tig></langSet></termEntry></body></text></tbx>' );
	$check( ! is_wp_error( $f6_tbx ) && 1 === count( $f6_tbx ) && 'Refectory' === $f6_tbx[0]['target_term'] && 'en' === $f6_tbx[0]['target_language'], 'F6: a TBX gives the original\'s term towards each language of the site' );
	$check( is_wp_error( \LinguaBridge\Languages::glossary_from_file( '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><tbx>&e;</tbx>' ) ), 'F6: a TBX with a DOCTYPE is refused' );
	$f7_first = \LinguaBridge\Connector::run( 'listWork', array( 'language' => 'en', 'state' => 'missing,stale,draft,published', 'limit' => 2 ) );
	$f7_next = \LinguaBridge\Connector::run( 'listWork', array( 'language' => 'en', 'state' => 'missing,stale,draft,published', 'limit' => 2, 'offset' => 1 ) );
	$check( ! is_wp_error( $f7_next ) && isset( $f7_first['items'][1], $f7_next['items'][0] ) && $f7_first['items'][1]['source_id'] === $f7_next['items'][0]['source_id'], 'F7: listWork with an offset starts after the first items' );
	$check( array() !== Xliff::work_chunks( 'en', 'missing,stale,draft,published', '' ) && count( Xliff::work_chunks( 'en', 'missing,stale,draft,published', '' )[0] ) <= Xliff::PER_FILE, 'F7: what to export comes in files of at most 100 contents' );
} finally {
	wp_set_current_user( 1 );
	foreach ( $created as $id ) {
		if ( $id ) {
			Storage::delete( $id );
			wp_delete_post( $id, true );
		}
	}
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
