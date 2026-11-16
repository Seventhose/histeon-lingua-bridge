<?php
/**
 * In-WordPress checks (dev/test.sh, wp eval-file): schema, upgrade, data kept on deactivation.
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Storage;

$failures = 0;
$check    = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};

$check( Storage::is_available(), 'the relations table exists and has its indexes' );
$check( Storage::SCHEMA_VERSION === get_option( Storage::SCHEMA_OPTION ), 'schema version recorded' );

// A visit costs no query for the schema check: the version is autoloaded and the table is not probed.
Storage::maybe_upgrade(); // an install from before 1.2: its version becomes autoloaded once
wp_cache_flush();
wp_load_alloptions();
global $wpdb;
$before = $wpdb->num_queries;
Storage::maybe_upgrade();
$check( $wpdb->num_queries === $before, 'a front-end request checks the schema without queries (' . ( $wpdb->num_queries - $before ) . ')' );
$check( array_key_exists( Storage::SCHEMA_OPTION, wp_load_alloptions() ), 'the schema version is autoloaded' );
wp_set_option_autoload( \LinguaBridge\Languages::OPTION, false ); // as 1.5.10 saved it
wp_cache_flush();
Storage::maybe_upgrade();
wp_cache_flush();
$check( array_key_exists( \LinguaBridge\Languages::OPTION, wp_load_alloptions() ), 'the settings are autoloaded (fixed once on sites that saved them without)' );

// An error shown to an administrator comes from this user's own stash, never from text in the URL.
wp_set_current_user( 1 );
$stash  = Closure::bind( static fn( string $m ): string => \LinguaBridge\Admin::stash_error( $m ), null, \LinguaBridge\Admin::class );
$render = Closure::bind( static function (): string { ob_start(); \LinguaBridge\Admin::render_notices(); return (string) ob_get_clean(); }, null, \LinguaBridge\Admin::class );
$_GET['lb_error'] = $stash( 'Lingua non valida.' );
$check( false !== strpos( $render(), 'Lingua non valida.' ), 'a stashed error is shown once' );
$check( false === strpos( $render(), 'Lingua non valida.' ), '…and only once' );
$_GET['lb_error'] = 'Chiama%20il%20numero%20123';
$check( false === strpos( $render(), 'numero' ), 'text put in the URL is never shown' );
unset( $_GET['lb_error'] );

// The selectors hidden on a translated home page: a setting; 1.1 sites keep theirs; nothing unsafe is accepted.
use LinguaBridge\Languages;
$saved = get_option( Languages::OPTION );
$legacy = $saved;
unset( $legacy['translated_home_hide_selectors'] );
update_option( Languages::OPTION, $legacy );
$check( in_array( '.cu-interior-hero', Languages::translated_home_hide_selectors(), true ), 'settings saved by 1.1 keep the old selectors' );
update_option( Languages::OPTION, array_merge( $legacy, array( 'translated_home_hide_selectors' => array( '.wp-block-post-title' ) ) ) );
$check( array( '.wp-block-post-title' ) === Languages::translated_home_hide_selectors(), 'the saved selectors are used' );
$request = array( 'languages' => "it|Italiano\nen|English", 'default_language' => 'it', 'translated_home_hide_selectors' => "h1{} </style><script>" );
$check( is_wp_error( Languages::save_from_request( $request ) ), 'a selector that could break out of the style is refused' );
foreach ( array( '.a', '#b > .c', 'header nav:not(.x)', '[data-role=title]' ) as $ok ) {
	$check( Languages::is_safe_selector( $ok ), "selector accepted: $ok" );
}
foreach ( array( '.a{x:y}', '.a;', '[a="b"]', '</style>', '@import x' ) as $bad ) {
	$check( ! Languages::is_safe_selector( $bad ), "selector refused: $bad" );
}
update_option( Languages::OPTION, $saved );

// What a draft takes with it, said before it is made.
$seed_post = get_posts( array( 'post_type' => 'post', 'name' => 'articolo-italiano', 'numberposts' => 1 ) )[0] ?? null;
add_post_meta( $seed_post->ID, 'lb_test_public', 'x' );
$report = \LinguaBridge\Fields::support_report( $seed_post->ID );
$states = array_column( $report, 'state', 'label' );
$check( 'translated' === ( $report[0]['state'] ?? '' ), 'title and content are translated' );
$check( (bool) array_filter( $report, static fn( $r ) => 'copied' === $r['state'] && false !== strpos( $r['label'], 'Altri campi personalizzati' ) ), 'public custom fields are reported as copied' );
$check( (bool) array_filter( $report, static fn( $r ) => 'missing' === $r['state'] ), 'internal plugin data is reported as left behind' );

// Lists in each language for static copies and sitemaps: an English post refreshes the English lists.
$en_post = get_posts( array( 'post_type' => 'post', 'name' => 'english-article', 'numberposts' => 1 ) )[0] ?? null;
$it_post = get_posts( array( 'post_type' => 'post', 'name' => 'articolo-italiano', 'numberposts' => 1 ) )[0] ?? null;
$paths = static fn( $urls ) => array_map( static fn( $u ) => (string) wp_parse_url( $u, PHP_URL_PATH ), $urls );
$en_lists = $paths( \LinguaBridge\Archives::for_post( array(), $en_post ) );
$check( in_array( '/en/', $en_lists, true ) && in_array( '/en/category/news/', $en_lists, true ), 'an English post names the English home and category: ' . implode( ' ', $en_lists ) );
$check( (bool) array_filter( $en_lists, static fn( $p ) => 0 === strpos( $p, '/en/author/' ) ), 'an English post names the English author archive' );
$check( ! array_filter( $en_lists, static fn( $p ) => 0 !== strpos( $p, '/en/' ) ), 'only English lists are added for an English post' );
$check( array( 'x' ) === \LinguaBridge\Archives::for_post( array( 'x' ), $it_post ), 'an Italian post adds nothing: the root lists are Italian already' );
$lists = $paths( \LinguaBridge\Archives::language_lists() );
$check( in_array( '/en/category/news/', $lists, true ) && in_array( '/en/eventi/', $lists, true ), 'the language sitemap names the English category and event archive: ' . implode( ' ', $lists ) );
$check( ! in_array( '/en/category/uncategorized/', $lists, true ), 'a term without English content is not named' );

// Strings layer: whole text nodes and text attributes only, escaped; scripts, comments, textareas untouched.
use LinguaBridge\Strings;
$check( 'Ciao' === Strings::clean( '<b>Ciao</b><script>x</script>' ) || 'Ciaox' === Strings::clean( '<b>Ciao</b><script>x</script>' ), 'a translation is plain text' );
$check( false === strpos( Strings::clean( 'a < b > c' ), '<' ), 'no angle brackets in a translation' );
global $wpdb;
$probe = 'LB prova & co';
Strings::register( 'html', $probe );
$probe_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Strings::table() . " WHERE source_hash = %s AND language = 'en'", Strings::key( 'html', $probe ) ) );
Strings::save( $probe_id, 'LB test "x" & y', 'published' );
$out = Strings::apply_html( "<div>\n  LB prova &amp; co\n</div><!-- LB prova &amp; co --><textarea>LB prova &amp; co</textarea><span title=\"LB prova &amp; co\">LB  prova\t&amp; co</span>", 'en' );
$check( false !== strpos( $out, "<div>\n  LB test &quot;x&quot; &amp; y\n</div>" ), 'a text node is replaced, its spacing kept, the translation escaped: ' . $out );
$check( false !== strpos( $out, '<!-- LB prova &amp; co -->' ) && false !== strpos( $out, '<textarea>LB prova &amp; co</textarea>' ), 'comments and textareas are untouched' );
$check( false !== strpos( $out, 'title="LB test &quot;x&quot; &amp; y"' ) && false !== strpos( $out, '>LB test &quot;x&quot; &amp; y</span>' ), 'attributes and differently spaced text match too' );
$check( '<p>LB prova &amp; co, e altro</p>' === Strings::apply_html( '<p>LB prova &amp; co, e altro</p>', 'en' ), 'only a whole text is replaced, never a part' );
Strings::remove( $probe_id );

// On an archive the queried object is a term: its id is never read as a post's (that post's language would leak).
$news_term = get_term_by( 'slug', 'news', 'category' );
$GLOBALS['wp_query'] = new WP_Query( array( 'category_name' => 'news' ) );
$GLOBALS['wp_query']->queried_object = $news_term;
$GLOBALS['wp_query']->queried_object_id = $news_term->term_id;
$check( 0 === \LinguaBridge\Frontend::queried_post_id(), 'a term archive has no queried post' );
$GLOBALS['wp_query'] = new WP_Query();

// A failed pattern (here: no backtracking allowed) changes nothing, never empties a content.
ini_set( 'pcre.jit', '0' );
$limit = ini_get( 'pcre.backtrack_limit' );
ini_set( 'pcre.backtrack_limit', '1' );
$failed = \LinguaBridge\Editor::replace( '<p>' . str_repeat( 'parola ', 50 ) . '</p>', trim( str_repeat( 'parola ', 50 ) ), 'x' );
$segmented = \LinguaBridge\Xliff::segment( '<p>' . str_repeat( 'parola ', 50 ) . '</p>' );
ini_set( 'pcre.backtrack_limit', $limit );
ini_set( 'pcre.jit', '1' );
$check( 0 === $failed['count'] && false !== strpos( $failed['content'], 'parola' ), 'editor: a failed match leaves the content as it is' );
$check( false === $segmented['ok'] && array() === $segmented['segments'], 'XLIFF: a failed segmentation is reported, not built on' );

// The visual editor: a whole text, wherever it is whole (HTML text, attribute, block JSON), never a part.
use LinguaBridge\Editor;
$gutenberg = "<!-- wp:paragraph -->\n<p>L'ufficio è aperto</p>\n<!-- /wp:paragraph --><p>L'ufficio è aperto il lunedì</p><img alt=\"Foto dell'ufficio\" src=\"x.jpg\">";
$r = Editor::replace( $gutenberg, "L\u{2019}ufficio è aperto", 'The office is open' );
$check( 1 === $r['count'] && false !== strpos( $r['content'], '<p>The office is open</p>' ) && false !== strpos( $r['content'], "<p>L'ufficio è aperto il lunedì</p>" ), 'Gutenberg: the whole text, typographic quotes as WordPress prints them, not the longer text' );
$r = Editor::replace( $gutenberg, "Foto dell\u{2019}ufficio", 'Office photo', 'alt' );
$check( 1 === $r['count'] && false !== strpos( $r['content'], 'alt="Office photo"' ), 'an alt attribute' );
$histos = '<!-- wp:xb/text {"uid":"a1","content":"Prezzi \u0026 orari \u003cbr\u003e"} /--><!-- wp:xb/text {"uid":"a2","content":"Prezzi \u0026 orari \u003cbr\u003e e altro"} /-->';
$r = Editor::replace( $histos, 'Prezzi & orari <br>', 'Prices & hours "now"' );
$check( 1 === $r['count'] && false !== strpos( $r['content'], '"content":"Prices \u0026 hours \u0022now\u0022"' ) && false !== strpos( $r['content'], 'e altro' ), 'block JSON (Histeon): escaped as WordPress serializes, the longer text untouched: ' . $r['content'] );
$check( 0 === Editor::replace( '<p>Ciao</p>', 'Cia', 'x' )['count'], 'never a part of a text' );
$unicode = '<!-- wp:xb/text {"content":"Citt\u00e0 aperta"} /-->';
$check( 1 === Editor::replace( $unicode, 'Città aperta', 'Open city' )['count'], 'JSON with \\u escapes for letters' );

// Settings by section: a tab changes only its own fields.
$before = Languages::settings();
$ok = Languages::save_from_request( array( 'translation_context' => 'Ctx', 'translation_glossary' => array( array( 'source_language' => 'it', 'source_term' => 'Chi siamo', 'target_language' => 'en', 'target_term' => 'About us', 'note' => '' ), array( 'source_language' => 'it', 'source_term' => '', 'target_language' => 'en', 'target_term' => '' ), array( 'source_language' => 'it', 'source_term' => 'Via', 'target_language' => 'en', 'target_term' => 'Away', 'remove' => '1' ) ) ), 'ai' );
$after = Languages::settings();
$check( true === $ok && 'Ctx' === $after['translation_context'] && 1 === count( $after['translation_glossary'] ), 'the AI tab saves context and glossary rows (empty and removed rows skipped)' );
$check( $before['languages'] === $after['languages'] && $before['prefix_language_urls'] === $after['prefix_language_urls'] && $before['translated_home_hide_selectors'] === $after['translated_home_hide_selectors'], 'the other tabs keep their values' );
$ok = Languages::save_from_request( array( 'lb_languages' => array( 'it' => array( 'label' => 'Italiano' ), 'en' => array( 'label' => 'English (UK)' ) ), 'default_language' => 'it', 'lb_add_language' => 'fr' ), 'languages' );
$check( true === $ok && 'English (UK)' === Languages::all()['en'] && 'Français' === Languages::all()['fr'], 'the languages table renames and adds from the list' );
$check( is_wp_error( Languages::save_from_request( array( 'lb_languages' => array( 'it' => array( 'label' => 'Italiano' ), 'en' => array( 'remove' => '1' ) ), 'default_language' => 'it' ), 'languages' ) ), 'a language with contents cannot be removed' );
$ok = Languages::save_from_request( array( 'lb_languages' => array( 'it' => array( 'label' => 'Italiano' ), 'en' => array( 'label' => 'English' ), 'fr' => array( 'remove' => '1' ) ), 'default_language' => 'it' ), 'languages' );
$check( true === $ok && ! isset( Languages::all()['fr'] ), 'an unused language is removed' );
$check( is_wp_error( Languages::save_from_request( array(), 'nope' ) ), 'an unknown section is refused' );
$check( Languages::is_configured(), 'a site with settings counts as configured' );
update_option( Languages::OPTION, $before );

// No built-in context or glossary: a site gets only its own.
$prompt = \LinguaBridge\Abilities::get_source( array( 'post_id' => $it_post->ID, 'target_language' => 'en' ) )['translation_prompt'];
$check( false === strpos( $prompt, 'GLOSSARIO OBBLIGATORIO' ) || ! empty( Languages::settings()['translation_glossary'] ), 'a site gets no glossary it did not write' );

// A private type (form submissions, orders) is never translatable: not listed, not by id, not even for an admin.
wp_set_current_user( 1 );
register_post_type( 'lb_test_private', array( 'public' => false, 'show_ui' => true, 'supports' => array( 'title', 'editor' ) ) );
$private_id = wp_insert_post( array( 'post_type' => 'lb_test_private', 'post_status' => 'publish', 'post_title' => 'Messaggio privato', 'post_content' => '<p>Dati di chi ha scritto</p>' ) );
$check( ! in_array( 'lb_test_private', \LinguaBridge\Admin::editable_post_types(), true ), 'a private type is not offered for translation' );
$check( in_array( 'wp_block', \LinguaBridge\Admin::editable_post_types(), true ) && in_array( 'page', \LinguaBridge\Admin::editable_post_types(), true ), 'pages and patterns still are' );
$check( ! \LinguaBridge\Translations::supports_post( $private_id ), 'a private item is not supported by id' );
$check( is_wp_error( \LinguaBridge\Translations::create_draft( $private_id, 'en' ) ), 'no translation of a private item can be created' );
$check( false === strpos( \LinguaBridge\Xliff::export( array( $private_id ), 'en' )['xml'], 'Dati di chi ha scritto' ), 'a private item never goes into an XLIFF file' );
wp_delete_post( $private_id, true );
unregister_post_type( 'lb_test_private' );

// Agent writes: the active markup of the original, fragment by fragment, after decoding; nothing new, nothing changed.
use LinguaBridge\Translations;
$orig = array( 'title' => 'Titolo', 'content' => '<p>Ciao</p><script>ok()</script>', 'excerpt' => '' );
$check( true === Translations::check_active_markup( $orig, array( 'title' => 'Title', 'content' => '<p>Hello</p><script>ok()</script>' ) ), 'a translation that keeps the original script is accepted' );
$check( is_wp_error( Translations::check_active_markup( $orig, array( 'title' => 'Title', 'content' => '<p>Hello</p><script>steal()</script>' ) ) ), 'a script swapped for another is refused (counts are not enough)' );
$check( is_wp_error( Translations::check_active_markup( $orig, array( 'title' => '<script>ok()</script>', 'content' => '<p>Hello</p>' ) ) ), 'a script moved from the content to the title is refused' );
$check( is_wp_error( Translations::check_active_markup( $orig, array( 'title' => 'T', 'content' => '<a href="jav&#x61;script:alert(1)">x</a>' ) ) ), 'a javascript: URL written with entities is refused' );
$check( is_wp_error( Translations::check_active_markup( $orig, array( 'title' => 'T', 'content' => '<!-- wp:x/y {"content":"<script>alert(1)</script>"} /-->' ) ) ), 'a script written with JSON escapes in a block is refused' );
// Histeon keeps a <script> as an xb/element block with its code in a verbatim text child, Etch keeps it base64 in
// the block attributes: neither is "<script" in the text, and a translation still cannot add or change one.
$xb_script = static fn( string $code ): string => '<!-- wp:xb/element {"uid":"s1","tag":"script"} --><!-- wp:xb/text {"uid":"s2","content":"' . $code . '","verbatim":true} /--><!-- /wp:xb/element -->';
$xb_orig   = array( 'title' => 'Titolo', 'content' => '<!-- wp:xb/element {"uid":"p1","tag":"p"} --><!-- wp:xb/text {"uid":"p2","content":"Ciao"} /--><!-- /wp:xb/element -->' . $xb_script( 'ok()' ) );
$check( true === Translations::check_active_markup( $xb_orig, array( 'title' => 'Title', 'content' => str_replace( 'Ciao', 'Hello', $xb_orig['content'] ) ) ), 'a Histeon page translated with its script unchanged is accepted' );
$check( is_wp_error( Translations::check_active_markup( $xb_orig, array( 'title' => 'Title', 'content' => str_replace( 'ok()', 'steal()', $xb_orig['content'] ) ) ) ), 'a Histeon script element whose code changed is refused' );
$check( is_wp_error( Translations::check_active_markup( $xb_orig, array( 'title' => 'Title', 'content' => $xb_orig['content'] . $xb_script( 'more()' ) ) ) ), 'a new Histeon script element is refused' );
$check( is_wp_error( Translations::check_active_markup( 'Ciao', array( 'title' => 'T', 'content' => '<!-- wp:xb/element {"uid":"f1","tag":"iframe","attributes":{"src":"https://example.org/"}} /-->' ) ) ), 'a new Histeon iframe element is refused' );
$check( is_wp_error( Translations::check_active_markup( 'Ciao', array( 'title' => 'T', 'content' => '<!-- wp:etch/element {"tag":"div","script":{"id":"x","code":"' . base64_encode( 'steal()' ) . '"}} /-->' ) ) ), 'a new Etch element script is refused' );
$check( is_wp_error( Translations::check_active_markup( $orig, array( 'title' => 'T', 'content' => '<!-- wp:x/y {"attributes":{"onclick":"alert(1)"}} /-->' ) ) ), 'an event handler as a JSON key is refused' );

// An Etch search form: its texts translate (label, placeholder, the layer names of the editor), its action does not.
$etch_form = static fn( string $label, string $placeholder, string $action, string $layer = 'Ricerca' ): string => '<!-- wp:etch/element {"metadata":{"name":"' . $layer . '"},"tag":"form","attributes":{"action":"' . $action . '","method":"get","aria-label":"' . $label . '"}} --><!-- wp:etch/element {"tag":"input","attributes":{"type":"search","name":"s","placeholder":"' . $placeholder . '"}} /--><!-- wp:etch/text {"content":"' . $label . '"} /--><!-- /wp:etch/element -->';
$form_orig = array( 'title' => 'Titolo', 'content' => $etch_form( 'Cerca nel sito', 'Scrivi qui', '/' ) );
$check( true === Translations::check_active_markup( $form_orig, array( 'title' => 'Title', 'content' => $etch_form( 'Search the site', 'Type here', '/', 'Search' ) ) ), 'an Etch search form with its texts translated is accepted' );
$form_bad = Translations::check_active_markup( $form_orig, array( 'title' => 'Title', 'content' => $etch_form( 'Search the site', 'Type here', 'https://evil.example/' ) ) );
$check( is_wp_error( $form_bad ) && false !== strpos( $form_bad->get_error_message(), 'form «Ricerca»' ), 'an Etch form whose action changed is refused, and the message names it' );
$form_button = str_replace( '<!-- /wp:etch/element -->', '<!-- wp:etch/element {"tag":"button","attributes":{"formaction":"https://evil.example/"}} /--><!-- /wp:etch/element -->', $form_orig['content'] );
$check( is_wp_error( Translations::check_active_markup( $form_orig, array( 'title' => 'T', 'content' => $form_button ) ) ), 'a button that sends the form elsewhere (formaction) is refused' );
$etch_script_el = static fn( string $code ): string => '<!-- wp:etch/element {"tag":"script"} --><!-- wp:etch/text {"content":"' . $code . '"} /--><!-- /wp:etch/element -->';
$check( is_wp_error( Translations::check_active_markup( array( 'title' => 'T', 'content' => $etch_script_el( 'ok()' ) ), array( 'title' => 'T', 'content' => $etch_script_el( 'steal()' ) ) ) ), 'an Etch script element is still compared whole' );

// The instructions of «Traduci con l'IA» give PowerShell its $env: (a double-quoted PHP string once ate it).
wp_set_current_user( 1 );
ob_start();
\LinguaBridge\Admin::render_ai();
$ai_page = (string) ob_get_clean();
$check( false !== strpos( $ai_page, '$env:LINGUA_BRIDGE_PAIR=' ), 'the Windows command of the AI page sets $env:LINGUA_BRIDGE_PAIR' );
unset( $ai_page );

// A translation never overwrites the original, nor an independent page.
$protect_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'LB protect IT', 'post_content' => '<p>Originale</p>' ) );
$protect_en = Translations::create_draft( $protect_it, 'en' );
$check( is_wp_error( Translations::create_or_update_draft( $protect_en, 'it', array( 'title' => 'Sovrascritto', 'content' => '<p>x</p>' ) ) ) && 'LB protect IT' === get_post( $protect_it )->post_title, 'the original (a draft) is never written through a translation' );
Translations::set_mode( $protect_en, true );
$check( is_wp_error( Translations::create_or_update_draft( $protect_it, 'en', array( 'title' => 'Agent', 'content' => '<p>x</p>' ) ) ), 'an independent page is never overwritten by an agent' );

// The protection of the original goes with its translations.
$private_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'LB private IT', 'post_content' => '<p>Riservato</p>' ) );
$private_en = Translations::create_draft( $private_it, 'en' );
wp_update_post( array( 'ID' => $private_en, 'post_status' => 'publish' ) );
$check( 'private' === get_post_status( $private_en ), 'the translation of a private page stays private when published' );
$locked_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB locked IT', 'post_content' => '<p>Con password</p>', 'post_password' => 'segreta' ) );
$locked_en = Translations::create_draft( $locked_it, 'en' );
$check( 'segreta' === get_post( $locked_en )->post_password, 'a password-protected page: its translation has the password' );
wp_update_post( array( 'ID' => $locked_en, 'post_password' => '' ) );
wp_publish_post( $locked_en );
$check( 'segreta' === get_post( $locked_en )->post_password, 'and keeps it when published another way' );
// An original made private or protected later takes its published translations with it (sitemap, front end).
$later_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB later IT', 'post_content' => '<p>Poi riservato</p>' ) );
$later_en = Translations::create_draft( $later_it, 'en' );
wp_publish_post( $later_en );
$later_draft_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB later draft IT', 'post_content' => '<p>Bozza</p>' ) );
$later_fr = Translations::create_draft( $later_draft_it, 'en' );
wp_update_post( array( 'ID' => $later_it, 'post_status' => 'private' ) );
wp_update_post( array( 'ID' => $later_draft_it, 'post_status' => 'private' ) );
$check( 'private' === get_post_status( $later_en ), 'an original made private later: its published translation becomes private too' );
$check( 'draft' === get_post_status( $later_fr ), '…and a draft translation stays a draft' );
$open_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB open IT', 'post_content' => '<p>Poi con password</p>' ) );
$open_en = Translations::create_draft( $open_it, 'en' );
wp_publish_post( $open_en );
wp_update_post( array( 'ID' => $open_it, 'post_password' => 'dopo' ) );
$check( 'dopo' === get_post( $open_en )->post_password && 'publish' === get_post_status( $open_en ), 'an original given a password later: its published translation gets it too' );
foreach ( array( $later_en, $later_fr, $later_it, $later_draft_it, $open_en, $open_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
// A link sent by Histeon Publish needs edit_post on both contents, and the translation takes the original's protection.
$remote_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'LB remote IT', 'post_content' => '<p>Remoto</p>' ) );
$remote_en = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB remote EN', 'post_content' => '<p>Remote</p>' ) );
$remote_reader = wp_insert_user( array( 'user_login' => 'lb_remote_reader', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $remote_reader );
\LinguaBridge\Histos::remote_link( $remote_en, $remote_it, 'en', 'follow' );
wp_set_current_user( 1 );
$check( ! Storage::find( $remote_en ), 'a user who cannot edit the contents cannot link them through Histeon' );
\LinguaBridge\Histos::remote_link( $remote_en, $remote_it, 'en', 'follow' );
$check( Storage::find( $remote_en ) && 'private' === get_post_status( $remote_en ), 'a published page linked to a private original becomes private' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $remote_reader );
foreach ( array( $remote_en, $remote_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
wp_trash_post( $protect_it );
$check( ! Translations::supports_post( $protect_it ), 'a trashed content is no source' );
foreach ( array( $protect_en, $protect_it, $private_en, $private_it, $locked_en, $locked_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// The translation service reaches public addresses only.
$public = Closure::bind( static fn( $h ) => \LinguaBridge\MachineTranslation::public_address( $h ), null, \LinguaBridge\MachineTranslation::class );
foreach ( array( '169.254.169.254', '10.0.0.8', '100.64.1.1', '127.0.0.1', '::ffff:127.0.0.1', 'fc00::1', 'fe80::1', '192.168.1.1' ) as $inside ) {
	$check( '' === $public( $inside ), "a private or reserved address is refused: $inside" );
}
$check( '8.8.8.8' === $public( '8.8.8.8' ), 'a public address is accepted' );

// The on-page editor speaks the person's language (profile), the page keeps its own (here Italian).
$lb_user   = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$lb_before = get_user_meta( $lb_user->ID, 'locale', true );
wp_set_current_user( $lb_user->ID );
update_user_meta( $lb_user->ID, 'locale', 'de_DE' );
$check( 'Settings' === \LinguaBridge\I18n::in_user_language( static function () { return __( 'Impostazioni', 'lingua-bridge' ); } ), 'the editor texts follow the user language (German → English)' );
$check( 'Impostazioni' === __( 'Impostazioni', 'lingua-bridge' ), 'the page keeps its language after the editor texts' );
update_user_meta( $lb_user->ID, 'locale', 'it_IT' );
$check( 'Impostazioni' === \LinguaBridge\I18n::in_user_language( static function () { return __( 'Impostazioni', 'lingua-bridge' ); } ), 'an Italian user gets the Italian texts' );
update_user_meta( $lb_user->ID, 'locale', $lb_before );

// Audit of 3 November, note 21: public meta copied to a translation keeps its backslashes (add_post_meta unslashes).
wp_set_current_user( 1 );
$slash_it = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB slash', 'post_content' => 'LB slash' ) ) );
add_post_meta( $slash_it, 'lb_path', wp_slash( 'C:\cartella\file' ) );
add_post_meta( $slash_it, 'lb_list', wp_slash( array( 'a\b', 'c' ) ) );
$slash_en = \LinguaBridge\Translations::create_draft( $slash_it, 'en' );
$check( is_int( $slash_en ) && 'C:\cartella\file' === get_post_meta( $slash_en, 'lb_path', true ), 'a backslash in public meta survives the copy to a translation' );
$check( is_int( $slash_en ) && array( 'a\b', 'c' ) === get_post_meta( $slash_en, 'lb_list', true ), '…in a serialized value too' );
foreach ( array( $slash_en, $slash_it ) as $gone ) {
	if ( is_int( $gone ) ) {
		Storage::delete( $gone );
		wp_delete_post( $gone, true );
	}
}

// Note 22: with DISALLOW_FILE_MODS (here its filter) the always-on router is never written into mu-plugins.
$check( ! \LinguaBridge\Router::installed(), 'no router before the check' );
add_filter( 'file_mod_allowed', '__return_false' );
$router_message = \LinguaBridge\Router::sync( true );
$check( false !== strpos( $router_message, 'DISALLOW_FILE_MODS' ) && ! file_exists( \LinguaBridge\Router::path() ), 'file changes disabled: the router is not written and the message says how to install it by hand' );
$check( '' === \LinguaBridge\Router::sync( false ), '…and turning it off asks nothing when there is no file' );
remove_filter( 'file_mod_allowed', '__return_false' );

// Note 25: an Etch component takes the place of another only if the language's member is a published component.
$etch_lang  = Closure::bind( static function ( $language ) { \LinguaBridge\Frontend::$current_language_cache = $language; \LinguaBridge\Frontend::$component_variant_cache = array(); }, null, \LinguaBridge\Frontend::class );
$etch_block = static fn( $id ) => array( 'blockName' => 'etch/component', 'attrs' => array( 'componentId' => $id ) );
$etch_it    = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'LB etch it', 'post_content' => 'LB etch it' ) );
$etch_en    = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'LB etch en', 'post_content' => 'LB etch en' ) );
$etch_page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB etch page', 'post_content' => 'LB etch page' ) );
$etch_good  = wp_generate_uuid4();
$etch_bad   = wp_generate_uuid4();
$etch_other = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'LB etch it 2', 'post_content' => 'LB etch it 2' ) );
Storage::insert( array( 'object_id' => $etch_it, 'group_uuid' => $etch_good, 'language' => Languages::default_code(), 'source_id' => $etch_it, 'source_hash' => '', 'review_state' => 'source' ) );
Storage::insert( array( 'object_id' => $etch_en, 'group_uuid' => $etch_good, 'language' => 'en', 'source_id' => $etch_it, 'source_hash' => '', 'review_state' => 'reviewed' ) );
Storage::insert( array( 'object_id' => $etch_other, 'group_uuid' => $etch_bad, 'language' => Languages::default_code(), 'source_id' => $etch_other, 'source_hash' => '', 'review_state' => 'source' ) );
Storage::insert( array( 'object_id' => $etch_page, 'group_uuid' => $etch_bad, 'language' => 'en', 'source_id' => $etch_other, 'source_hash' => '', 'review_state' => 'reviewed' ) );
$etch_lang( 'en' );
$swapped = \LinguaBridge\Frontend::translate_etch_component( $etch_block( $etch_it ), null );
$check( $etch_en === $swapped['attrs']['componentId'], 'an Etch component is drawn in its published English version' );
$etch_ref = \LinguaBridge\Frontend::translate_etch_component( array( 'blockName' => 'etch/component', 'attrs' => array( 'ref' => $etch_it ) ), null );
$check( $etch_en === $etch_ref['attrs']['ref'] && ! isset( $etch_ref['attrs']['componentId'] ), 'an Etch 1.6+ component («ref») is drawn in its English version, in the same attribute' );
$kept = \LinguaBridge\Frontend::translate_etch_component( $etch_block( $etch_other ), null );
$check( $etch_other === $kept['attrs']['componentId'], 'a page linked as the English member of a component never takes its place' );
$check( ! current_user_can( 'publish_post', $etch_en ) && \LinguaBridge\Editor::can_publish( $etch_en ), 'a component (wp_block, «publish_blocks» nobody has) can be published from the page by an administrator' );
$check( in_array( $etch_en, \LinguaBridge\Frontend::rendered_components(), true ), 'the translated component drawn on the page is known to the editor' );
$etch_lang( null );
foreach ( array( $etch_it, $etch_en, $etch_page, $etch_other ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// N2: the internal links of a page in another language lead to that language.
$link_it    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB link IT', 'post_name' => 'lb-link-it', 'post_content' => '<p>Pagina</p>' ) );
$link_en    = Translations::create_draft( $link_it, 'en' );
wp_update_post( array( 'ID' => $link_en, 'post_name' => 'lb-link-en' ) );
wp_publish_post( $link_en );
$link_alone = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB link alone', 'post_name' => 'lb-link-alone', 'post_content' => '<p>Solo italiano</p>' ) );
$link_post  = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB link post', 'post_name' => 'lb-link-post', 'post_content' => '<p>Articolo</p>' ) );
$link_post_en = Translations::create_draft( $link_post, 'en' );
wp_publish_post( $link_post_en );
Storage::content_changed();
$check( get_permalink( $link_en ) === \LinguaBridge\Links::url( get_permalink( $link_it ), 'en' ), 'a link to a page with an English version leads to it' );
$check( get_permalink( $link_en ) . '#form' === \LinguaBridge\Links::url( wp_make_link_relative( get_permalink( $link_it ) ) . '#form', 'en' ), '…written as a path from the root, with its anchor' );
$check( get_permalink( $link_post_en ) === \LinguaBridge\Links::url( get_permalink( $link_post ), 'en' ), 'a link to an article with an English version leads to it' );
$check( '' === \LinguaBridge\Links::url( get_permalink( $link_alone ), 'en' ), 'a page without an English version: the link stays' );
$check( home_url( '/en/category/uncategorized/' ) === \LinguaBridge\Links::url( home_url( '/category/uncategorized/' ), 'en' ), 'a list (a category) gets the language prefix' );
foreach ( array( 'https://other.example/lb-link-it/', home_url( '/wp-content/uploads/a.pdf' ), home_url( '/wp-admin/' ), home_url( '/en/lb-link-en/' ), '//other.example/x', 'mailto:a@example.test', '#top' ) as $kept_link ) {
	$check( '' === \LinguaBridge\Links::url( $kept_link, 'en' ), "a link that stays as it is: $kept_link" );
}
$link_html = \LinguaBridge\Links::apply_html( '<nav><a class="x" href="' . esc_url( get_permalink( $link_it ) ) . '">IT</a><a hreflang="it" href="' . esc_url( get_permalink( $link_it ) ) . '">IT</a></nav><script>var u = "' . get_permalink( $link_it ) . '";</script>', 'en' );
$check( 1 === substr_count( $link_html, 'href="' . get_permalink( $link_en ) . '"' ) && 1 === substr_count( $link_html, 'hreflang="it" href="' . get_permalink( $link_it ) . '"' ) && false !== strpos( $link_html, 'var u = "' . get_permalink( $link_it ) . '"' ), 'in a page: menus and buttons rewritten, links to another language and scripts untouched' );
// The page of the articles (page_for_posts): url_to_postid() does not find it, its translation must be found anyway.
$link_blog_option = get_option( 'page_for_posts' );
$link_front_option = get_option( 'show_on_front' );
update_option( 'show_on_front', 'page' );
update_option( 'page_for_posts', $link_it );
Storage::content_changed();
$check( get_permalink( $link_en ) === \LinguaBridge\Links::url( wp_make_link_relative( get_permalink( $link_it ) ), 'en' ), 'a link to the page of the articles leads to its translation' );
$check( trailingslashit( get_permalink( $link_en ) ) . 'page/2/' === \LinguaBridge\Links::url( trailingslashit( get_permalink( $link_it ) ) . 'page/2/', 'en' ), '…and to the same page of the list' );
update_option( 'page_for_posts', $link_blog_option );
update_option( 'show_on_front', $link_front_option );
Storage::content_changed();
foreach ( array( $link_en, $link_it, $link_alone, $link_post_en, $link_post ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// D6: the original's lists and home say the original's language, not WordPress's (contents in Italian, site en_US).
$check( 'lang="' . Languages::default_code() . '"' === \LinguaBridge\Frontend::language_attributes( 'lang="en-US"' ), 'a page of the original that is no content says the original\'s language' );
$check( 'lang="' . Languages::default_code() . '-XX"' === \LinguaBridge\Frontend::language_attributes( 'lang="' . Languages::default_code() . '-XX"' ), '…and WordPress\'s value stays when it is the same language' );

// D7: the switcher counts as placed once a page drew it; the setup picks the navigation block only if the theme has one.
delete_option( \LinguaBridge\MenuSwitcher::SEEN_OPTION );
\LinguaBridge\MenuSwitcher::seen( 'nav-block' );
$check( 'nav-block' === get_option( \LinguaBridge\MenuSwitcher::SEEN_OPTION ), 'a switcher drawn on a page is remembered' );
delete_option( \LinguaBridge\MenuSwitcher::SEEN_OPTION );
$check( in_array( \LinguaBridge\MenuSwitcher::automatic(), array( 'nav-block', '' ), true ), 'the setup\'s choice on a block theme: the navigation block only if a template has one' );

// U8: a translation whose slug WordPress numbered (another English page has it) says so in the editor.
$dup_first = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB newsletter uno', 'post_name' => 'lb-newsletter-uno', 'post_content' => '<p>Uno</p>' ) );
$dup_first_en = Translations::create_draft( $dup_first, 'en' );
wp_update_post( array( 'ID' => $dup_first_en, 'post_name' => 'lb-newsletter', 'post_status' => 'publish' ) );
$dup_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB newsletter', 'post_name' => 'lb-newsletter', 'post_content' => '<p>Iscriviti</p>' ) );
$dup_en = Translations::create_draft( $dup_it, 'en' );
wp_update_post( array( 'ID' => $dup_en, 'post_name' => 'lb-newsletter', 'post_status' => 'publish' ) );
$dup_note = \LinguaBridge\Admin::numbered_slug_note( get_post( $dup_en ) );
$check( 'lb-newsletter' !== get_post( $dup_en )->post_name && false !== strpos( $dup_note, 'lb-newsletter' ), 'a numbered slug of a translation is explained in the editor: ' . get_post( $dup_en )->post_name );
$check( '' === \LinguaBridge\Admin::numbered_slug_note( get_post( $dup_it ) ), '…never on the original' );
foreach ( array( $dup_en, $dup_it, $dup_first_en, $dup_first ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// F3: the texts a page shows to people, outside head, scripts and the admin bar; contents are not strings.
$scan_texts = \LinguaBridge\StringScan::texts( '<html><head><title>Titolo</title></head><body><div id="wpadminbar"><ul><li>Bacheca</li></ul></div></div><header><a href="/">Vai al contenuto</a><input placeholder="Cerca nel sito"></header><script>var x = "Codice";</script><p>Leggi l&#8217;articolo</p><p>2026</p><p>{post.title}</p></body></html>' );
$check( isset( $scan_texts['Vai al contenuto'], $scan_texts['Cerca nel sito'], $scan_texts['Leggi l’articolo'] ) && 3 === count( $scan_texts ), 'the texts of a page: visible and accessible ones, not the head, the admin bar, scripts, numbers or expressions: ' . implode( ' | ', $scan_texts ) );
$check( array() !== \LinguaBridge\StringScan::pairs( 'en' ), 'the pages to compare in English: at least its home' );

// Giro 4. P1: the relations of a page of rows in a few queries; D12: the summary counts everything, not a sample.
$p1_ids = array();
for ( $p1 = 0; $p1 < 5; $p1++ ) {
	$p1_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB p1 ' . $p1, 'post_content' => '<p>Testo ' . $p1 . '</p>' ) );
	$p1_ids[] = $p1_it;
	$p1_ids[] = Translations::create_draft( $p1_it, 'en' );
}
wp_cache_flush();
$p1_members = Storage::prime( array_values( array_filter( $p1_ids, static fn( $id ) => 'LB p1' === substr( get_the_title( $id ), 0, 5 ) ) ) );
global $wpdb;
$p1_before = $wpdb->num_queries;
foreach ( $p1_ids as $p1_id ) {
	$p1_row = Storage::find( $p1_id );
	Storage::group_rows( $p1_row['group_uuid'] );
}
$check( 10 === count( $p1_members ) && $p1_before === $wpdb->num_queries, 'after prime(), find() and group_rows() of the rows and their groups cost no query (' . ( $wpdb->num_queries - $p1_before ) . ')' );
wp_set_current_user( 1 );
delete_transient( 'lb_summary_1_' . absint( get_option( Storage::CONTENT_VERSION_OPTION, 0 ) ) );
Storage::content_changed();
$d12 = \LinguaBridge\Connector::work_summary();
$check( isset( $d12['totals']['en'], $d12['published']['en'] ) && $d12['scanned'] >= 5 && $d12['totals']['en']['draft'] >= 5 && ! empty( $d12['complete'] ), 'the summary counts drafts, missing and published from the table, for every original: ' . wp_json_encode( $d12['totals']['en'] ) );
foreach ( array_reverse( $p1_ids ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// D1: the language of the contents is proposed from their words; the checklist does not count the defaults as a choice.
$check( in_array( Languages::guess_content_language(), array( '', 'it' ), true ), 'the contents\' language is guessed (or left unclear): ' . Languages::guess_content_language() );

// U2: the original is always first in the order of the languages, the others as the arrows put them.
$u2_saved = get_option( Languages::OPTION );
$u2 = Languages::save_from_request( array( 'default_language' => Languages::default_code(), 'lb_languages' => array( 'en' => array( 'label' => 'English', 'order' => '0' ), Languages::default_code() => array( 'label' => 'Italiano', 'order' => '1' ) ) ), 'languages' );
$check( true === $u2 && Languages::default_code() === array_key_first( Languages::all() ), 'the original stays first whatever the order sent: ' . implode( ',', array_keys( Languages::all() ) ) );
update_option( Languages::OPTION, $u2_saved );

// N1: categories and tags in every language. A translated term is a normal term; the translations of contents get it.
use LinguaBridge\Terms;
$n1_cat = wp_insert_term( 'LB Notizie', 'category', array( 'slug' => 'lb-notizie' ) );
$n1_cat = is_wp_error( $n1_cat ) ? 0 : absint( $n1_cat['term_id'] );
$n1_it = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB n1 articolo', 'post_content' => '<p>Notizia</p>', 'post_category' => array( $n1_cat ) ) );
$n1_en = Translations::create_draft( $n1_it, 'en' );
wp_publish_post( $n1_en );
$n1_term = Terms::create( $n1_cat, 'en', 'LB News', 'lb-news' );
$check( is_int( $n1_term ) && 'lb-news' === get_term( $n1_term )->slug && 'category' === get_term( $n1_term )->taxonomy, 'a category gets its English version: a normal term with its own name and slug' );
$check( in_array( $n1_term, wp_get_object_terms( $n1_en, 'category', array( 'fields' => 'ids' ) ), true ) && ! in_array( $n1_cat, wp_get_object_terms( $n1_en, 'category', array( 'fields' => 'ids' ) ), true ), 'the English article now has the English category, not the Italian one' );
$check( in_array( $n1_cat, wp_get_object_terms( $n1_it, 'category', array( 'fields' => 'ids' ) ), true ), 'the original keeps its category' );
$check( home_url( '/en/category/lb-news/' ) === get_term_link( $n1_term ), 'the English category lives under /en/: ' . get_term_link( $n1_term ) );
$check( is_wp_error( Terms::create( $n1_cat, 'en', 'Again' ) ) && is_wp_error( Terms::create( $n1_term, 'it', 'x' ) ), 'one translation per language, and only of the original' );
$n1_it2 = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB n1 secondo', 'post_content' => '<p>Altra</p>', 'post_category' => array( $n1_cat ) ) );
$n1_en2 = Translations::create_draft( $n1_it2, 'en' );
$check( array( $n1_term ) === wp_get_object_terms( $n1_en2, 'category', array( 'fields' => 'ids' ) ), 'a translation made later gets the translated category at once' );
$n1_reader = wp_insert_user( array( 'user_login' => 'lb_n1_author', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
wp_set_current_user( $n1_reader );
$check( is_wp_error( Terms::create( $n1_cat, 'fr', 'Nouvelles' ) ), 'whoever cannot manage categories cannot translate one' );
wp_set_current_user( 1 );
$n1_versions = Terms::versions( get_term( $n1_cat ) );
$check( isset( $n1_versions['en'], $n1_versions[ Languages::default_code() ] ) && $n1_versions['en'] === get_term_link( $n1_term ), 'the versions of a category archive, for the switchers and hreflang' );
// C3: on a translated term's archive, a language with no term of its own has the original's list under its prefix,
// when its list has content; x-default is the original's.
$c3_saved = get_option( Languages::OPTION );
update_option( Languages::OPTION, array_merge( $c3_saved, array( 'languages' => array_merge( Languages::all(), array( 'fr' => 'Français', 'de' => 'Deutsch' ) ) ) ) );
$c3_fr = Translations::create_draft( $n1_it, 'fr' );
wp_publish_post( $c3_fr );
$GLOBALS['wp_query'] = new WP_Query( array( 'category_name' => 'lb-news' ) );
ob_start();
Terms::alternate_links();
$c3_head = (string) ob_get_clean();
$c3_switcher = wp_list_pluck( \LinguaBridge\MenuSwitcher::links(), 'url', 'code' );
$GLOBALS['wp_query'] = $GLOBALS['wp_the_query'];
$check( array( Languages::default_code(), 'en', 'fr' ) === array_keys( $c3_switcher ) && home_url( '/fr/category/lb-notizie/' ) === $c3_switcher['fr'], 'the switcher of the category\'s archive has the same versions: ' . implode( ', ', array_keys( $c3_switcher ) ) );
$check( false !== strpos( $c3_head, 'hreflang="fr" href="' . home_url( '/fr/category/lb-notizie/' ) . '"' ) && false !== strpos( $c3_head, 'hreflang="en" href="' . home_url( '/en/category/lb-news/' ) . '"' ), 'the French list of the Italian category is a version of the English category\'s archive' );
$check( false === strpos( $c3_head, 'hreflang="de"' ), '…not the German one, which has no content' );
$check( false !== strpos( $c3_head, 'hreflang="x-default" href="' . home_url( '/category/lb-notizie/' ) . '"' ), '…and x-default is the original category' );
Storage::delete( $c3_fr );
wp_delete_post( $c3_fr, true );
update_option( Languages::OPTION, $c3_saved );
wp_delete_term( $n1_term, 'category' );
$check( null === Terms::find( $n1_term ), 'a deleted translated term leaves its group' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $n1_reader );
foreach ( array( $n1_en2, $n1_it2, $n1_en, $n1_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
wp_delete_term( $n1_cat, 'category' );

// C1: «Metti il selettore nella testata» (Etch): the switcher block goes at the end of the header's <nav>, else of the
// <header>, else of the component; in the original and in its language versions, each copied first and restored after.
use LinguaBridge\Switcher;
$c1_nav = '<!-- wp:etch/element {"tag":"header","attributes":{"class":"x-head"}} -->' . "\n" . '<!-- wp:etch/element {"tag":"nav","attributes":[]} -->' . "\n" . '<!-- wp:etch/text {"content":"Menu"} /-->' . "\n" . '<!-- /wp:etch/element -->' . "\n" . '<!-- /wp:etch/element -->';
$c1_in = Switcher::insert_block( $c1_nav );
$c1_blocks = parse_blocks( $c1_in );
$check( 'lingua-bridge/language-switcher' === ( $c1_blocks[0]['innerBlocks'][0]['innerBlocks'][1]['blockName'] ?? '' ) && 'dropdown' === ( $c1_blocks[0]['innerBlocks'][0]['innerBlocks'][1]['attrs']['style'] ?? '' ), 'the switcher block goes at the end of the header\'s nav' );
$c1_head = parse_blocks( Switcher::insert_block( '<!-- wp:etch/element {"tag":"header","attributes":[]} -->' . "\n" . '<!-- wp:etch/text {"content":"Logo"} /-->' . "\n" . '<!-- /wp:etch/element -->' ) );
$check( 'lingua-bridge/language-switcher' === ( $c1_head[0]['innerBlocks'][1]['blockName'] ?? '' ), '…else at the end of the header' );
$c1_none = parse_blocks( Switcher::insert_block( '<!-- wp:etch/text {"content":"Solo testo"} /-->' ) );
$check( 'lingua-bridge/language-switcher' === ( end( $c1_none )['blockName'] ?? '' ), '…else at the end of the component' );
$check( Switcher::has_switcher( $c1_in ) && Switcher::has_switcher( '[lingua_bridge_switcher style="dropdown"]' ) && ! Switcher::has_switcher( $c1_nav ), 'a component that already has the switcher is known' );
$c1_it = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'LB c1 testata', 'post_content' => wp_slash( $c1_nav ) ) );
$c1_en = Translations::create_draft( $c1_it, 'en' );
wp_update_post( array( 'ID' => $c1_en, 'post_status' => 'publish' ) );
Translations::snapshot( $c1_en );
$check( ! Translations::is_stale( Storage::find( $c1_en ) ), 'the English header follows its original before' );
$c1_put = Switcher::put_in_header( $c1_it );
$check( 2 === $c1_put && Switcher::has_switcher( get_post( $c1_it )->post_content ) && Switcher::has_switcher( get_post( $c1_en )->post_content ), 'the switcher goes into the original header and its English version: ' . ( is_wp_error( $c1_put ) ? $c1_put->get_error_message() : $c1_put ) );
$check( ! Translations::is_stale( Storage::find( $c1_en ) ), '…and the English one still follows its original' );
$check( $c1_nav === get_post_meta( $c1_it, Switcher::BACKUP_META, true ), 'a copy of the header was kept before' );
$check( 0 === Switcher::put_in_header( $c1_it ), 'twice: nothing more to change' );
$check( is_wp_error( Switcher::put_in_header( $c1_en ) ), 'a language version is never the one chosen' );
$check( 2 === Switcher::restore( $c1_it ) && ! Switcher::has_switcher( get_post( $c1_it )->post_content ) && '' === get_post_meta( $c1_it, Switcher::BACKUP_META, true ), '«Ripristina» puts back both headers as they were' );
$c1_reader = wp_insert_user( array( 'user_login' => 'lb_c1_author', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
wp_set_current_user( $c1_reader );
$check( is_wp_error( Switcher::put_in_header( $c1_it ) ), 'whoever cannot edit the component cannot change it' );
wp_set_current_user( 1 );
wp_delete_user( $c1_reader );
foreach ( array( $c1_en, $c1_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
// C1, C2, C4: the «Selettore» tab and the setup's step 2 save only allowed values.
$c1_saved = get_option( Languages::OPTION );
Languages::save_from_request( array( 'floating_shown' => '1', 'floating_position' => 'top-left', 'floating_style' => 'list', 'floating_label' => '<b>', 'visitor_language' => 'redirect', 'switcher_menu' => 'nowhere' ), 'switcher' );
$c1_settings = Languages::settings();
$check( 'off' === $c1_settings['floating_switcher'] && 'top-left' === $c1_settings['floating_position'] && 'list' === $c1_settings['floating_style'] && 'name' === $c1_settings['floating_label'] && 'redirect' === Languages::visitor_language() && '' === $c1_settings['switcher_menu'], 'the switcher tab: an unticked box turns the floating switcher off, a value not allowed is ignored' );
Languages::save_from_request( array( 'switcher_place' => 'floating', 'prefix_language_urls' => '1', 'visitor_language' => 'suggest' ), 'urls' );
$check( 'auto' === Languages::settings()['floating_switcher'] && 'suggest' === Languages::visitor_language(), 'the setup\'s «fluttuante» turns it on' );
Languages::save_from_request( array( 'switcher_place' => 'manual', 'prefix_language_urls' => '1' ), 'urls' );
$check( 'off' === Languages::settings()['floating_switcher'] && '' === Languages::settings()['switcher_menu'], '…and «lo metto io» off, with no menu' );
update_option( Languages::OPTION, $c1_saved );
$check( 'suggest' === Languages::visitor_language() || isset( $c1_saved['visitor_language'] ), 'C2: suggest is the default for the visitor\'s language' );

// N3: the same slug in more languages: the prefix tells them apart, the address finds the page of its language.
$n3_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB newsletter n3', 'post_name' => 'lb-newsletter-n3', 'post_content' => '<p>Iscriviti</p>' ) );
$n3_en = Translations::create_draft( $n3_it, 'en' );
wp_update_post( array( 'ID' => $n3_en, 'post_name' => 'lb-newsletter-n3', 'post_status' => 'publish' ) );
$check( 'lb-newsletter-n3' === get_post( $n3_en )->post_name && home_url( '/en/lb-newsletter-n3/' ) === get_permalink( $n3_en ), 'a translation keeps the slug of its original: /en/lb-newsletter-n3/' );
$n3_other = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB n3 altra', 'post_name' => 'lb-newsletter-n3', 'post_content' => '<p>Altra</p>' ) );
$check( 'lb-newsletter-n3' !== get_post( $n3_other )->post_name, 'two pages of the same language still get different slugs: ' . get_post( $n3_other )->post_name );
$n3_resolve = static function ( $uri, $vars ) {
	$_SERVER['REQUEST_URI'] = $uri;
	return \LinguaBridge\Routing::resolve_page_slug( \LinguaBridge\Routing::resolve_home_request( $vars ) );
};
$n3_en_vars = $n3_resolve( '/en/lb-newsletter-n3/', array( 'pagename' => 'lb-newsletter-n3', 'lingua_bridge_route_language' => 'en' ) );
$n3_it_vars = $n3_resolve( '/lb-newsletter-n3/', array( 'pagename' => 'lb-newsletter-n3' ) );
$check( $n3_en === ( $n3_en_vars['page_id'] ?? 0 ) && $n3_it === ( $n3_it_vars['page_id'] ?? 0 ), 'the same path finds the English page under /en/ and the Italian one at the root' );
$check( get_permalink( $n3_en ) === \LinguaBridge\Links::url( get_permalink( $n3_it ), 'en' ), 'an internal link to the original leads to the translation even with the same slug' );
unset( $_SERVER['REQUEST_URI'] );
\LinguaBridge\Routing::resolve_home_request( array() );
foreach ( array( $n3_other, $n3_en, $n3_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// C5: the redirects WordPress makes for Lingua Bridge, given to Static Bridge for the server's rules (Strict mode).
$c5_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB c5', 'post_name' => 'lb-c5', 'post_content' => '<p>C5</p>' ) );
$c5_en = Translations::create_draft( $c5_it, 'en' );
wp_update_post( array( 'ID' => $c5_en, 'post_name' => 'lb-c5-vecchio', 'post_status' => 'publish' ) );
wp_update_post( array( 'ID' => $c5_en, 'post_name' => 'lb-c5-nuovo' ) );
$c5_cat = wp_insert_term( 'LB c5 categoria', 'category', array( 'slug' => 'lb-c5-cat' ) );
$c5_cat = is_wp_error( $c5_cat ) ? 0 : absint( $c5_cat['term_id'] );
$c5_term = Terms::create( $c5_cat, 'en', 'LB c5 category', 'lb-c5-cat-en' );
$c5 = apply_filters( 'static_bridge_redirects', array() );
$c5_to = static fn( $from ) => isset( $c5[ $from ] ) ? (string) wp_parse_url( $c5[ $from ]['to'], PHP_URL_PATH ) . ' ' . $c5[ $from ]['status'] : '';
$check( '/en/lb-c5-nuovo/ 301' === $c5_to( '/en/lb-c5-vecchio/' ), 'the old slug of a translated page leads to the new one: ' . $c5_to( '/en/lb-c5-vecchio/' ) );
$check( '/en/category/lb-c5-cat-en/ 301' === $c5_to( '/en/category/lb-c5-cat/' ), 'the original category under /en/ leads to the English one' );
$check( '/en/category/lb-c5-cat-en/ 301' === $c5_to( '/category/lb-c5-cat-en/' ), '…and the English category at the root too' );
$check( ! isset( $c5['/chi-siamo/'] ) && ! array_filter( array_keys( $c5 ), static fn( $f ) => 0 !== strpos( $f, '/' ) ), 'only paths of this site, never a page that is there' );
$check( array( '/x/' => array( 'to' => 'kept', 'status' => 302 ) ) === array_intersect_key( apply_filters( 'static_bridge_redirects', array( '/x/' => array( 'to' => 'kept', 'status' => 302 ) ) ), array( '/x/' => 1 ) ), 'what another plugin gave is kept' );
$c5_saved = get_option( Languages::OPTION );
update_option( Languages::OPTION, array_merge( $c5_saved, array( 'language_domains' => array( 'en' => 'en.example.test' ) ) ) );
$check( array( 'en' => 'en.example.test' ) === apply_filters( 'static_bridge_hosts', array() ), 'C7: the hosts of the languages with a domain go to Static Bridge' );
$check( ! array_filter( \LinguaBridge\Archives::export_urls( array() ), static fn( $u ) => false !== strpos( $u, 'en.example.test' ) ), 'C7: the full export gets no address of a language\'s domain' );
update_option( Languages::OPTION, $c5_saved );
wp_delete_term( $c5_term, 'category' );
wp_delete_term( $c5_cat, 'category' );
foreach ( array( $c5_en, $c5_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// C10: the SEO texts of Yoast, Rank Math, SEOPress and Slim SEO go with the translation and are translated, their
// variables untouched; a canonical address stays the original's own.
use LinguaBridge\Fields;
$c10_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB c10', 'post_content' => '<p>SEO</p>' ) );
update_post_meta( $c10_it, '_yoast_wpseo_title', '%%title%% %%sep%% Chi siamo' );
update_post_meta( $c10_it, '_yoast_wpseo_metadesc', 'La descrizione italiana' );
update_post_meta( $c10_it, 'rank_math_description', 'Descrizione Rank Math' );
update_post_meta( $c10_it, 'rank_math_canonical_url', 'https://example.test/originale/' );
update_post_meta( $c10_it, '_seopress_titles_desc', 'Descrizione SEOPress' );
update_post_meta( $c10_it, 'slim_seo', array( 'title' => 'Titolo Slim', 'description' => 'Descrizione Slim', 'canonical' => 'https://example.test/originale/' ) );
$c10_en = Translations::create_draft( $c10_it, 'en' );
$check( '%%title%% %%sep%% Chi siamo' === get_post_meta( $c10_en, '_yoast_wpseo_title', true ) && 'Descrizione SEOPress' === get_post_meta( $c10_en, '_seopress_titles_desc', true ), 'the SEO texts of Yoast and SEOPress (meta with _) go with the new translation' );
$check( '' === get_post_meta( $c10_en, 'rank_math_canonical_url', true ) && ! isset( get_post_meta( $c10_en, 'slim_seo', true )['canonical'] ), 'a canonical address is never copied: it is the original\'s' );
$c10_fields = wp_list_pluck( wp_list_filter( Fields::translatable_values( $c10_en ), array( 'provider' => 'seo' ) ), 'value', 'key' );
$check( isset( $c10_fields['_yoast_wpseo_metadesc'], $c10_fields['rank_math_description'], $c10_fields['_seopress_titles_desc'], $c10_fields['slim_seo:description'] ) && 'Descrizione Slim' === $c10_fields['slim_seo:description'], 'agents, XLIFF and the service see the SEO texts: ' . implode( ', ', array_keys( $c10_fields ) ) );
Fields::save_translatable_values(
	$c10_en,
	array(
		array( 'provider' => 'seo', 'key' => '_yoast_wpseo_title', 'value' => 'About us' ),
		array( 'provider' => 'seo', 'key' => '_yoast_wpseo_metadesc', 'value' => 'The English description' ),
		array( 'provider' => 'seo', 'key' => 'slim_seo:description', 'value' => 'Slim description <b>EN</b>' ),
		array( 'provider' => 'seo', 'key' => 'not_seo', 'value' => 'x' ),
	)
);
$check( '%%title%% %%sep%% Chi siamo' === get_post_meta( $c10_en, '_yoast_wpseo_title', true ), 'a title that loses the plugin\'s variables is refused' );
$check( 'The English description' === get_post_meta( $c10_en, '_yoast_wpseo_metadesc', true ) && 'Slim description EN' === get_post_meta( $c10_en, 'slim_seo', true )['description'] && 'Titolo Slim' === get_post_meta( $c10_en, 'slim_seo', true )['title'], 'the translated texts are saved, as plain text, inside Slim SEO\'s array too' );
$check( '' === get_post_meta( $c10_en, 'not_seo', true ), 'a key that is no SEO text is not written' );
$check( Fields::save_seo_value( $c10_en, '_yoast_wpseo_title', '%%title%% %%sep%% About us', $c10_it ) && '%%title%% %%sep%% About us' === get_post_meta( $c10_en, '_yoast_wpseo_title', true ), '…with the same variables it is saved' );
$c10_panel = wp_list_pluck( \LinguaBridge\Editor::seo_texts( $c10_en ), 'original', 'key' );
$check( 'La descrizione italiana' === ( $c10_panel['_yoast_wpseo_metadesc'] ?? '' ), 'the on-page editor shows the SEO texts with the original beside them' );
$check( (bool) wp_list_filter( Fields::support_report( $c10_it ), array( 'state' => 'translated' ) ) && false !== strpos( wp_json_encode( Fields::support_report( $c10_it ) ), 'SEO' ), '«Crea traduzione» says the SEO texts are translated' );
foreach ( array( $c10_en, $c10_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// C13: each custom field has its mode: translate, copy (again whenever the original is saved), copy once, ignore.
// C15: the data that is no text stays the same as the original's, when chosen (off by default).
use LinguaBridge\Sync;
$c13_saved = get_option( Languages::OPTION );
Languages::save_from_request( array( 'translatable_meta_keys' => '', 'lb_field_modes' => array( 'lb_c13_ignore' => 'ignore', 'lb_c13_copy' => 'copy', 'lb_c13_tr' => 'translate', 'lb_c13_bad' => 'drop', 'lb_c13_default' => '' ), 'lb_sync_shown' => '1', 'lb_sync' => array( 'menu_order', 'terms', 'template', 'nothing' ) ), 'advanced' );
$check( array( 'lb_c13_ignore' => 'ignore', 'lb_c13_copy' => 'copy', 'lb_c13_tr' => 'translate' ) === Sync::field_modes() && array( 'template', 'menu_order', 'terms' ) === Sync::synced(), 'C13, C15: the modes and the synchronized data are saved, the values not allowed are not: ' . wp_json_encode( Sync::synced() ) );
$check( in_array( 'lb_c13_tr', Languages::translatable_meta_keys(), true ), 'C13: a field set to «Traduci» is translated' );
$c13_cat = wp_insert_term( 'LB c15 categoria', 'category', array( 'slug' => 'lb-c15-cat' ) );
$c13_cat_en = Terms::create( $c13_cat['term_id'], 'en', 'LB c15 category', 'lb-c15-cat-en' );
$c13_it = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB c13', 'post_content' => '<p>C13</p>', 'meta_input' => array( 'lb_c13_ignore' => 'non copiare', 'lb_c13_copy' => 'uno', 'lb_c13_tr' => 'Testo da tradurre', 'lb_c13_default' => 'una volta' ) ) );
$c13_en = Translations::create_draft( $c13_it, 'en' );
wp_update_post( array( 'ID' => $c13_en, 'post_status' => 'publish' ) );
$check( '' === get_post_meta( $c13_en, 'lb_c13_ignore', true ) && 'uno' === get_post_meta( $c13_en, 'lb_c13_copy', true ) && 'una volta' === get_post_meta( $c13_en, 'lb_c13_default', true ), 'C13: «Ignora» is not copied, the others are' );
$check( (bool) wp_list_filter( Fields::translatable_values( $c13_en ), array( 'provider' => 'post_meta', 'key' => 'lb_c13_tr' ) ), 'C13: «Traduci» goes to agents, XLIFF and the service' );
update_post_meta( $c13_it, 'lb_c13_copy', 'due' );
update_post_meta( $c13_it, 'lb_c13_default', 'cambiato' );
wp_update_post( array( 'ID' => $c13_it, 'menu_order' => 7 ) );
wp_set_object_terms( $c13_it, array( $c13_cat['term_id'] ), 'category' );
wp_update_post( array( 'ID' => $c13_it, 'post_title' => 'LB c13 salvato' ) );
$check( 'due' === get_post_meta( $c13_en, 'lb_c13_copy', true ) && 'una volta' === get_post_meta( $c13_en, 'lb_c13_default', true ), 'C13: saving the original copies «Copia» again, not «Copia una volta»' );
$check( 7 === (int) get_post( $c13_en )->menu_order && 'publish' === get_post_status( $c13_en ), 'C15: the order reaches the published translation' );
$check( array( $c13_cat_en ) === wp_get_object_terms( $c13_en, 'category', array( 'fields' => 'ids' ) ), 'C15: the category reaches it as its English version' );
$check( 'LB c13 salvato' !== get_post( $c13_en )->post_title, 'C15: never a text' );
update_option( Languages::OPTION, $c13_saved );
wp_update_post( array( 'ID' => $c13_it, 'menu_order' => 9 ) );
$check( 7 === (int) get_post( $c13_en )->menu_order, 'C15: off by default: nothing reaches the translation' );
foreach ( array( $c13_en, $c13_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
wp_delete_term( $c13_cat_en, 'category' );
wp_delete_term( $c13_cat['term_id'], 'category' );

// C16: a template part saved in the database is translatable: its translation has the same theme and area and a slug
// of its own, and on a page in that language the template-part block draws it.
use LinguaBridge\TemplateParts;
$check( in_array( 'wp_template_part', Translations::translatable_types(), true ), 'C16: template parts are translatable' );
$c16_it = wp_insert_post( array( 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_title' => 'LB testata', 'post_name' => 'lb-testata', 'post_content' => '<!-- wp:paragraph --><p>LB testata</p><!-- /wp:paragraph -->', 'tax_input' => array( 'wp_theme' => array( get_stylesheet() ), 'wp_template_part_area' => array( 'header' ) ) ) );
wp_set_object_terms( $c16_it, array( get_stylesheet() ), 'wp_theme' );
wp_set_object_terms( $c16_it, array( 'header' ), 'wp_template_part_area' );
$c16_en = Translations::create_draft( $c16_it, 'en' );
$check( 'lb-testata-en' === get_post( $c16_en )->post_name && array( get_stylesheet() ) === wp_get_object_terms( $c16_en, 'wp_theme', array( 'fields' => 'slugs' ) ) && array( 'header' ) === wp_get_object_terms( $c16_en, 'wp_template_part_area', array( 'fields' => 'slugs' ) ), 'C16: the translated part has the theme, the area and its own slug' );
$c16_language = new ReflectionProperty( \LinguaBridge\Frontend::class, 'current_language_cache' );
$c16_language->setAccessible( true );
$c16_language->setValue( null, 'en' );
$c16_block = array( 'blockName' => 'core/template-part', 'attrs' => array( 'slug' => 'lb-testata', 'theme' => get_stylesheet() ) );
$check( 'lb-testata' === TemplateParts::swap( $c16_block )['attrs']['slug'], 'C16: a draft is never drawn' );
wp_update_post( array( 'ID' => $c16_en, 'post_status' => 'publish' ) );
$c16_swaps = new ReflectionProperty( TemplateParts::class, 'swaps' );
$c16_swaps->setAccessible( true );
$c16_swaps->setValue( null, array() );
$check( 'lb-testata-en' === TemplateParts::swap( $c16_block )['attrs']['slug'], 'C16: on an English page the English part is drawn' );
$c16_language->setValue( null, null );
foreach ( array( $c16_en, $c16_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// C17: the bases of the addresses translated for a language: links, rules and the way back for the switchers.
$c17_saved = get_option( Languages::OPTION );
Languages::save_from_request( array( 'prefix_language_urls' => '1', 'language_bases' => "en category cat\nen tag wp-admin\nit category x\nen author author" ), 'urls' );
$check( array( 'en' => array( 'category' => 'cat' ) ) === Languages::settings()['language_bases'], 'C17: a base a line, only for a language of translation, never reserved nor the same' );
$check( home_url( '/en/cat/news/' ) === \LinguaBridge\Routing::language_url( home_url( '/category/news/' ), 'en' ), 'C17: an English list has the English base: ' . \LinguaBridge\Routing::language_url( home_url( '/category/news/' ), 'en' ) );
$check( 'category/news' === \LinguaBridge\Routing::original_base( 'cat/news', 'en' ), 'C17: and back' );
$c17_rules = \LinguaBridge\Routing::rewrite_rules( array( 'category/(.+?)/?$' => 'index.php?category_name=$matches[1]' ) );
$c17_keys = array_keys( $c17_rules );
$check( isset( $c17_rules['^en/cat/(.+?)/?$'] ) && array_search( '^en/cat/(.+?)/?$', $c17_keys, true ) < array_search( '^en/category/(.+?)/?$', $c17_keys, true ), 'C17: the rules of the English base come before those of the original\'s' );
update_option( Languages::OPTION, $c17_saved );

// N5: the language in the REST API: a field on every translatable type, and ?lingua= on the lists.
$n5_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB n5 pagina', 'post_content' => '<p>Ciao</p>' ) );
$n5_en = Translations::create_draft( $n5_it, 'en' );
wp_publish_post( $n5_en );
$n5_src2 = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB n5 altra', 'post_content' => '<p>x</p>' ) );
$n5_draft = Translations::create_draft( $n5_src2, 'en' );
$n5_get = static function ( $route, $params = array() ) {
	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_get_server()->response_to_data( rest_do_request( $request ), false );
};
wp_set_current_user( 0 );
$n5_one = $n5_get( '/wp/v2/pages/' . $n5_it );
$check( 'it' === ( $n5_one['lingua']['code'] ?? '' ) && true === ( $n5_one['lingua']['original'] ?? null ) && $n5_en === ( (array) $n5_one['lingua']['versions'] )['en']['id'], 'REST: a page says its language and its versions' );
$n5_list = $n5_get( '/wp/v2/pages', array( 'lingua' => 'en', 'per_page' => 100 ) );
$n5_codes = array_unique( array_map( static fn( $p ) => $p['lingua']['code'] ?? '?', $n5_list ) );
$check( array( 'en' ) === array_values( $n5_codes ) && in_array( $n5_en, array_column( $n5_list, 'id' ), true ), 'REST: ?lingua=en lists the English pages only' );
$n5_orig = $n5_get( '/wp/v2/pages', array( 'lingua' => 'originals', 'per_page' => 100 ) );
$check( in_array( $n5_it, array_column( $n5_orig, 'id' ), true ) && ! in_array( $n5_en, array_column( $n5_orig, 'id' ), true ), 'REST: ?lingua=originals leaves the translations out' );
$check( ! isset( ( (array) ( $n5_get( '/wp/v2/pages/' . $n5_src2 )['lingua']['versions'] ?? array() ) )['en'] ), 'REST: a draft version is not named to a visitor' );
wp_set_current_user( 1 );
foreach ( array( $n5_draft, $n5_src2, $n5_en, $n5_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// Note 26: one rule for the links (hreflang, switchers, menus): published, not a media file.
$check( ! \LinguaBridge\Frontend::online( null ) && ! \LinguaBridge\Frontend::online( 0 ), 'nothing is not online' );
$online_draft = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'LB online draft', 'post_content' => 'x' ) );
$online_page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB online page', 'post_content' => 'x' ) );
// Posts in memory (WordPress itself never saves a published attachment): only the rule is under test.
$online_post  = static fn( $type, $parent = 0 ) => new WP_Post( (object) array( 'ID' => 0, 'post_type' => $type, 'post_status' => 'publish', 'post_parent' => $parent, 'filter' => 'raw' ) );
$check( \LinguaBridge\Frontend::online( $online_page ) && ! \LinguaBridge\Frontend::online( $online_draft ), 'a published page is online, a draft is not' );
$check( ! \LinguaBridge\Frontend::online( $online_post( 'attachment' ) ), 'a media file is never a link target, even when published' );
$check( ! \LinguaBridge\Frontend::online( $online_post( 'product_variation', $online_draft ) ), 'a variation of a product that is not published is not online' );
$check( \LinguaBridge\Frontend::online( $online_post( 'product_variation', $online_page ) ), 'a variation of a published product is' );
foreach ( array( $online_draft, $online_page ) as $gone ) {
	wp_delete_post( $gone, true );
}

// Doc 23, item 181: Static Bridge asks, for each route it queues for an incremental update, the same content in the
// other languages (static_bridge_translated_urls, Static Bridge 2.2.9). Asked here as Static Bridge asks.
$lb_by_name   = static fn( $type, $name ) => get_posts( array( 'post_type' => $type, 'name' => $name, 'numberposts' => 1 ) )[0] ?? null;
$lb_translated = static fn( $url, $given = array() ) => apply_filters( 'static_bridge_translated_urls', $given, $url );
$lb_set        = static function ( $post_id, array $fields ) use ( $wpdb ) {
	// Straight to the table, as another request would leave it: no hook of this test may publish anything.
	$wpdb->update( $wpdb->posts, $fields, array( 'ID' => $post_id ) );
	clean_post_cache( $post_id );
};
$lb_it   = $lb_by_name( 'post', 'articolo-italiano' );
$lb_en   = $lb_by_name( 'post', 'english-article' );
$lb_only = $lb_by_name( 'post', 'solo-italiano' );
$check( $lb_it && $lb_en && $lb_only, 'the seeded articles exist' );
if ( $lb_it && $lb_en && $lb_only ) {
	$it_url = get_permalink( $lb_it );
	$en_url = get_permalink( $lb_en );
	$check( array( $en_url ) === $lb_translated( $it_url ), 'Static Bridge gets the English article for the Italian one (' . wp_json_encode( $lb_translated( $it_url ) ) . ')' );
	$check( array( $it_url ) === $lb_translated( $en_url ), '…and the Italian article for the English one' );
	$check( array() === $lb_translated( get_permalink( $lb_only ) ), 'a post without translations gets none' );
	$check( array( 'https://kept.example/' , $en_url ) === $lb_translated( $it_url, array( 'https://kept.example/' ) ), 'what another plugin gave is kept, before the translations' );
	$full = array_map( static fn( $i ) => home_url( '/other-' . $i . '/' ), range( 1, 32 ) );
	$check( $full === $lb_translated( $it_url, $full ), 'never more than 32 routes in all' );
	$check( array() === $lb_translated( home_url( '/category/news/' ) ) && array() === $lb_translated( $it_url . 'page/2/' ), 'a list page, or a page of a list, gets none' );
	$check( array() === $lb_translated( str_replace( wp_parse_url( home_url(), PHP_URL_HOST ), 'evil.example', $it_url ) ) && array() === $lb_translated( $it_url . '?x=1' ), 'a route of another site, or with a query string, gets none' );
	$home_en = \LinguaBridge\Routing::translated_home_id( 'en' );
	$check( $home_en && in_array( get_permalink( $home_en ), $lb_translated( home_url( '/' ) ), true ), 'the home gets the translated home' );
	$check( in_array( home_url( '/' ), $lb_translated( home_url( '/en/' ) ), true ), '…and the translated home, at the language root, the home' );

	// Only public content, both ways: a removal of the source never takes a published translation with it.
	$lb_set( $lb_en->ID, array( 'post_status' => 'draft' ) );
	$check( array() === $lb_translated( $it_url ), 'a draft translation is not given' );
	$lb_set( $lb_en->ID, array( 'post_status' => 'publish', 'post_password' => 'segreta' ) );
	$check( array() === $lb_translated( $it_url ), 'a password-protected translation is not given' );
	$lb_set( $lb_en->ID, array( 'post_password' => '' ) );
	foreach ( array( array( 'post_status' => 'draft' ), array( 'post_status' => 'private' ), array( 'post_status' => 'publish', 'post_password' => 'segreta' ) ) as $fields ) {
		$lb_set( $lb_it->ID, $fields );
		$check( array() === $lb_translated( $it_url ), 'a source that is no longer public gets nothing: ' . wp_json_encode( $fields ) );
	}
	$lb_set( $lb_it->ID, array( 'post_status' => 'publish', 'post_password' => '' ) );
	$check( array( $en_url ) === $lb_translated( $it_url ), 'the source public again gets its translation again' );
}

// N9: the texts of a media file per language: alt and caption of the library, used on pages of that language.
$n9_file = wp_insert_attachment( array( 'post_title' => 'LB n9 foto', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_excerpt' => 'Il chiostro di sera' ), false );
update_post_meta( $n9_file, '_wp_attachment_image_alt', 'Il chiostro' );
update_post_meta( $n9_file, '_wp_attachment_metadata', array( 'width' => 10, 'height' => 10, 'file' => 'lb-n9.jpg', 'sizes' => array() ) );
\LinguaBridge\Media::save( array( 'ID' => $n9_file ), array( 'lingua_bridge_alt_en' => 'The cloister', 'lingua_bridge_caption_en' => 'The cloister at night' ) );
$check( 'The cloister' === \LinguaBridge\Media::text( $n9_file, 'en', 'alt' ) && 'The cloister at night' === \LinguaBridge\Media::text( $n9_file, 'en', 'caption' ), 'a media file keeps its alternative text and caption per language' );
$n9_lang = Closure::bind( static function ( $language ) { \LinguaBridge\Frontend::$current_language_cache = $language; }, null, \LinguaBridge\Frontend::class );
$n9_lang( 'en' );
$n9_attrs = \LinguaBridge\Media::image_attributes( array( 'alt' => 'Il chiostro' ), get_post( $n9_file ) );
$n9_page_alt = \LinguaBridge\Media::image_attributes( array( 'alt' => 'Scritto nella pagina' ), get_post( $n9_file ) );
$check( 'The cloister' === $n9_attrs['alt'] && 'Scritto nella pagina' === $n9_page_alt['alt'] && 'The cloister at night' === \LinguaBridge\Media::caption( 'Il chiostro di sera', $n9_file ), 'an image of the library on an English page has the English alternative text (an alt of the page stays)' );
$check( 'The cloister' === get_post_meta( $n9_file, '_wp_attachment_image_alt', true ), 'a builder that reads the library\'s alt as a meta (Etch does) gets the English one' );
$n9_lang( null );
$check( 'Il chiostro' === get_post_meta( $n9_file, '_wp_attachment_image_alt', true ), '…and the original\'s on a page of the original' );
\LinguaBridge\Media::save( array( 'ID' => $n9_file ), array( 'lingua_bridge_alt_en' => '', 'lingua_bridge_caption_en' => '' ) );
$check( array() === \LinguaBridge\Media::texts( $n9_file ), 'empty texts remove the translation of the file' );
wp_delete_attachment( $n9_file, true );

// D13: the known inconsistencies closed together, each with its check.
// 62-1: deleting a translation leaves the «da aggiornare» of its sisters as it is.
$d13_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB d13', 'post_content' => '<p>Uno</p>' ) );
$d13_en = Translations::create_draft( $d13_it, 'en' );
wp_update_post( array( 'ID' => $d13_it, 'post_content' => '<p>Uno, cambiato</p>' ) );
Translations::forget_hash( $d13_it );
$d13_extra = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'LB d13 extra', 'post_content' => '<p>x</p>' ) );
Storage::insert( array( 'object_id' => $d13_extra, 'group_uuid' => Storage::find( $d13_it )['group_uuid'], 'language' => 'fr', 'source_id' => $d13_it, 'source_hash' => 'old', 'review_state' => 'needs_review' ) );
$check( Translations::is_stale( Storage::find( $d13_en ) ), '62-1: the English translation is outdated after the original changed' );
wp_delete_post( $d13_extra, true );
$check( Translations::is_stale( Storage::find( $d13_en ) ), '62-1: …and stays so when another translation is deleted' );
// 621-1: a backslash of a translated field stays.
$d13_meta_saved = get_option( Languages::OPTION );
update_option( Languages::OPTION, array_merge( (array) $d13_meta_saved, array( 'translatable_meta_keys' => array( 'lb_d13_label' ) ) ) );
\LinguaBridge\Fields::save_translatable_values( $d13_en, array( array( 'provider' => 'post_meta', 'key' => 'lb_d13_label', 'value' => 'C:\\cartella\\file' ) ) );
$check( 'C:\\cartella\\file' === get_post_meta( $d13_en, 'lb_d13_label', true ), '621-1: a backslash of a translated field stays: ' . get_post_meta( $d13_en, 'lb_d13_label', true ) );
update_option( Languages::OPTION, $d13_meta_saved );
// 621-4 and 621-5: a form without the locales or the glossary keeps them.
$d13_settings = get_option( Languages::OPTION );
update_option( Languages::OPTION, array_merge( (array) $d13_settings, array( 'locales' => array( 'en' => 'en_GB' ), 'translation_glossary' => array( array( 'source_language' => 'it', 'source_term' => 'Chiostro', 'target_language' => 'en', 'target_term' => 'Cloister', 'note' => '' ) ) ) ) );
Languages::save_from_request( array( 'languages' => "it|Italiano\nen|English", 'default_language' => 'it' ), 'languages' );
$check( array( 'en' => 'en_GB' ) === Languages::settings()['locales'], '621-4: languages saved as lines keep their WordPress locales' );
Languages::save_from_request( array( 'translation_context' => 'Un sito di prova.' ), 'ai' );
$check( 1 === count( Languages::settings()['translation_glossary'] ) && 'Un sito di prova.' === Languages::settings()['translation_context'], '621-5: the setup\'s step with the context alone keeps the glossary' );
update_option( Languages::OPTION, $d13_settings );
// 621-8: a new draft whose save fails does not stay behind with the original's text.
$d13_fail = static fn( $empty, $postarr ) => ! empty( $postarr['ID'] ) ? true : $empty;
add_filter( 'wp_insert_post_empty_content', $d13_fail, 10, 2 );
$d13_src = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB d13 fallisce', 'post_content' => '<p>Testo</p>' ) );
$d13_failed = Translations::create_or_update_draft( $d13_src, 'en', array( 'title' => 'LB d13 fails', 'content' => '<p>Text</p>' ) );
remove_filter( 'wp_insert_post_empty_content', $d13_fail, 10 );
$check( is_wp_error( $d13_failed ) && ! Storage::find_group_language( Storage::find( $d13_src )['group_uuid'] ?? '', 'en' ), '621-8: a draft whose save failed is not left with the original\'s text' );
// 621-14: an unknown notice in the URL shows nothing.
$_GET['lb_notice'] = 'qualcosa';
$d13_notices = $render();
unset( $_GET['lb_notice'] );
$check( false === strpos( $d13_notices, 'Bozza tradotta creata.' ), '621-14: an unknown notice says nothing' );
// 621-29: no XLIFF for a language that is not one of translation.
$check( 0 === \LinguaBridge\Xliff::export( array( $d13_it ), Languages::default_code() )['count'] && 0 === \LinguaBridge\Xliff::export( array( $d13_it ), 'xx' )['count'], '621-29: an XLIFF to the original\'s language or an unknown one has no content' );
// 621-22: a menu label with an apostrophe is still the page's title, and takes the translation's.
$d13_office = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "LB L'ufficio", 'post_content' => '<p>Ufficio</p>' ) );
$d13_office_en = Translations::create_draft( $d13_office, 'en' );
wp_update_post( array( 'ID' => $d13_office_en, 'post_title' => 'LB The office', 'post_status' => 'publish' ) );
$d13_route = Closure::bind( static function ( $language ) { \LinguaBridge\Routing::$request_language = $language; }, null, \LinguaBridge\Routing::class );
$d13_route( 'en' );
$d13_block = \LinguaBridge\Menus::navigation_link( array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'label' => "LB L'ufficio", 'id' => $d13_office, 'kind' => 'post-type' ) ) );
$d13_route( '' );
$check( 'LB The office' === ( $d13_block['attrs']['label'] ?? '' ), '621-22: a menu label with an apostrophe takes the translation\'s title: ' . ( $d13_block['attrs']['label'] ?? '' ) );
foreach ( array( $d13_office_en, $d13_office ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
foreach ( array( $d13_en, $d13_it, $d13_src ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// F4: the switcher's options (shortcode and block) and its versions on a content.
$f4_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB f4', 'post_content' => '<p>Uno</p>' ) );
$f4_en = Translations::create_draft( $f4_it, 'en' );
wp_update_post( array( 'ID' => $f4_en, 'post_title' => 'LB f4 en', 'post_status' => 'publish' ) );
$f4_query = static function ( $id ) {
	$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $id ) );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
};
$f4_query( $f4_en );
$f4_list = \LinguaBridge\Frontend::render_switcher();
$f4_drop = \LinguaBridge\Frontend::render_switcher( array( 'style' => 'dropdown', 'label' => 'code', 'hide_current' => 'yes' ) );
$f4_block = \LinguaBridge\Blocks::render_language_switcher( array( 'label' => 'both', 'hideCurrent' => true ) );
$check( false !== strpos( $f4_list, '<nav class="lb-language-switcher" aria-label="Lingua"><ul>' ) && false !== strpos( $f4_list, 'aria-current="page">English</a>' ) && false !== strpos( $f4_list, '>Italiano</a>' ), 'F4: the switcher of a content, as before (names, the current marked)' );
$check( false !== strpos( $f4_drop, '<details class="lb-language-switcher__menu"><summary>EN</summary>' ) && false !== strpos( $f4_drop, '>IT<span class="screen-reader-text"> Italiano</span></a>' ) && false === strpos( $f4_drop, 'aria-current' ), 'F4: a dropdown with codes, the current language hidden' );
$check( false !== strpos( $f4_block, '>IT · Italiano</a>' ) && false === strpos( $f4_block, 'EN · English' ), 'F4: the block takes the same options (code and name, current hidden)' );
$f4_missing = \LinguaBridge\Frontend::render_switcher( array( 'show_missing' => '1' ) );
$f4_only = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB f4 solo', 'post_content' => '<p>Solo</p>' ) );
$f4_query( $f4_only );
$check( '' === \LinguaBridge\Frontend::render_switcher(), 'F4: a content in one language has no switcher' );
$f4_home_en = \LinguaBridge\MenuSwitcher::language_home( 'en' );
$f4_single = \LinguaBridge\Frontend::render_switcher( array( 'show_missing' => 'yes' ) );
$check( '' === $f4_home_en || ( false !== strpos( $f4_single, 'href="' . esc_url( $f4_home_en ) . '"' ) && false !== strpos( $f4_missing, 'English' ) ), 'F4: with show_missing a language without this content leads to its home' );
$GLOBALS['wp_query'] = new WP_Query();
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
foreach ( array( $f4_en, $f4_it, $f4_only ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}

// N7: the «Traduttore» role, with the languages given to it.
\LinguaBridge\Translators::ensure_role();
$n7_settings = get_option( Languages::OPTION );
update_option( Languages::OPTION, array_merge( (array) $n7_settings, array( 'languages' => array_merge( (array) ( $n7_settings['languages'] ?? array() ), array( 'de' => 'Deutsch' ) ) ) ) );
$n7_role = get_role( \LinguaBridge\Translators::ROLE );
$check( $n7_role && $n7_role->has_cap( \LinguaBridge\Translators::CAP ) && $n7_role->has_cap( 'edit_posts' ) && ! $n7_role->has_cap( 'publish_posts' ) && ! $n7_role->has_cap( 'edit_others_posts' ), 'N7: the role translates and edits, never publishes or edits others\' contents' );
$n7_user = wp_insert_user( array( 'user_login' => 'lb_n7_' . wp_generate_password( 5, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => \LinguaBridge\Translators::ROLE ) );
$n7_source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB n7 originale', 'post_content' => '<p>Uno</p>', 'post_author' => 1 ) );
$n7_secret = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'LB n7 bozza', 'post_content' => '<p>Due</p>', 'post_author' => 1 ) );
$n7_published = Translations::create_draft( $n7_source, 'de' );
wp_update_post( array( 'ID' => $n7_published, 'post_status' => 'publish' ) );
// The profile: only an administrator gives the languages.
$_POST['lingua_bridge_translator_nonce'] = wp_create_nonce( 'lingua_bridge_translator_' . $n7_user );
$_POST['lingua_bridge_languages'] = array( 'en', 'it', 'xx' );
\LinguaBridge\Translators::save_profile( $n7_user );
$check( array( 'en' ) === \LinguaBridge\Translators::languages( $n7_user ), 'N7: an administrator gives the languages (the original\'s and unknown ones are left out)' );
wp_set_current_user( $n7_user );
$_POST['lingua_bridge_translator_nonce'] = wp_create_nonce( 'lingua_bridge_translator_' . $n7_user );
$_POST['lingua_bridge_languages'] = array( 'en', 'de' );
\LinguaBridge\Translators::save_profile( $n7_user );
unset( $_POST['lingua_bridge_translator_nonce'], $_POST['lingua_bridge_languages'] );
$check( array( 'en' ) === \LinguaBridge\Translators::languages( $n7_user ), 'N7: a translator cannot give themselves another language' );
$check( ! current_user_can( 'edit_post', $n7_source ) && \LinguaBridge\Translators::may_translate( $n7_source, 'en' ) && ! \LinguaBridge\Translators::may_translate( $n7_source, 'de' ) && ! \LinguaBridge\Translators::may_translate( $n7_secret, 'en' ), 'N7: a published original can be translated into their language, not edited; a draft original not even read' );
$n7_draft = Translations::create_draft( $n7_source, 'en' );
$check( is_int( $n7_draft ) && current_user_can( 'edit_post', $n7_draft ) && ! current_user_can( 'publish_post', $n7_draft ) && ! current_user_can( 'delete_post', $n7_draft ), 'N7: the translator creates the English draft and edits it, without publishing or deleting it' );
$n7_saved = Translations::create_or_update_draft( $n7_source, 'en', array( 'title' => 'LB n7 original', 'content' => '<p>One</p>' ) );
$check( $n7_saved === $n7_draft && 'LB n7 original' === get_post( $n7_draft )->post_title, 'N7: …and saves its texts as an agent would' );
$check( is_wp_error( Translations::create_draft( $n7_source, 'de' ) ) || Storage::find_group_language( Storage::find( $n7_source )['group_uuid'], 'de' ), 'N7: another language is refused' );
$check( ! current_user_can( 'edit_post', $n7_published ), 'N7: a published translation is the editors\'' );
wp_update_post( array( 'ID' => $n7_draft, 'post_status' => 'pending' ) );
$check( 'pending' === get_post_status( $n7_draft ) && current_user_can( 'edit_post', $n7_draft ), 'N7: sent for review, still theirs to fix' );
$n7_work = \LinguaBridge\Connector::run( 'listWork', array( 'state' => 'missing,stale,draft,published', 'limit' => 100 ) );
$n7_ids = is_wp_error( $n7_work ) ? array() : array_column( $n7_work['items'], 'source_id' );
$n7_langs = is_wp_error( $n7_work ) || ! $n7_work['items'] ? array() : array_keys( $n7_work['items'][0]['languages'] );
$check( in_array( $n7_source, $n7_ids, true ) && ! in_array( $n7_secret, $n7_ids, true ) && array( 'en' ) === $n7_langs, 'N7: the work list shows the published originals, in their languages only' );
ob_start();
\LinguaBridge\Admin::render_dashboard();
$n7_dashboard = (string) ob_get_clean();
$check( false !== strpos( $n7_dashboard, 'LB n7 originale' ) && false === strpos( $n7_dashboard, 'LB n7 bozza' ) && false === strpos( $n7_dashboard, 'value="de"' ), 'N7: the dashboard shows the translator the published originals and their language only' );
wp_set_current_user( 1 );
$check( current_user_can( 'edit_post', $n7_published ) && ! \LinguaBridge\Translators::is_translator( 1 ), 'N7: an administrator keeps all their rights' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $n7_user );
foreach ( array( $n7_draft, $n7_published, $n7_source, $n7_secret ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
update_option( Languages::OPTION, $n7_settings );

// N10: a language on its own domain instead of its prefix.
$n10_settings = get_option( Languages::OPTION );
$n10_urls = array( 'prefix_language_urls' => '1', 'rewrite_links_shown' => '1', 'rewrite_links' => ! empty( $n10_settings['rewrite_links'] ) ? '1' : '', 'keep_routing' => ! empty( $n10_settings['keep_routing'] ) ? '1' : '', 'translated_home_hide_selectors' => implode( "\n", (array) ( $n10_settings['translated_home_hide_selectors'] ?? array() ) ) );
Languages::save_from_request( $n10_urls + array( 'language_domains' => "en https://EN.localhost:8895/\nxx x.example\nit it.example\nen twice.example\nde bad_host" ), 'urls' );
$check( array( 'en' => 'en.localhost:8895' ) === \LinguaBridge\Routing::language_domains(), 'N10: domains a line, only languages of translation and real host names, the first one per language' );
$n10_it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB n10', 'post_name' => 'lb-n10', 'post_content' => '<p>Uno</p>' ) );
$n10_en = Translations::create_draft( $n10_it, 'en' );
wp_update_post( array( 'ID' => $n10_en, 'post_title' => 'LB n10 en', 'post_name' => 'lb-n10-en', 'post_status' => 'publish' ) );
$check( 'http://en.localhost:8895/lb-n10-en/' === get_permalink( $n10_en ) && false === strpos( get_permalink( $n10_it ), 'en.localhost' ), 'N10: the English page is on its domain, without the prefix; the original stays on the main one' );
$check( 'http://en.localhost:8895/category/news/' === \LinguaBridge\Routing::language_url( home_url( '/category/news/' ), 'en' ), 'N10: a list of the language too' );
$check( in_array( 'en.localhost', apply_filters( 'allowed_redirect_hosts', array() ), true ), 'N10: the domain is a host WordPress may redirect to' );
foreach ( array( $n10_en, $n10_it ) as $gone ) {
	Storage::delete( $gone );
	wp_delete_post( $gone, true );
}
update_option( Languages::OPTION, $n10_settings );
$check( array() === \LinguaBridge\Routing::language_domains(), 'N10: without domains, nothing changes' );

echo $failures ? "FAILURES: $failures\n" : "OK\n";
// A failure fails dev/test.sh, not only its output.
if ( $failures ) {
	exit( 1 );
}
