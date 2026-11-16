<?php
/**
 * The WordPress side of the AI connector on a real site (dev/test.sh): only listed operations, the user's own
 * permissions on each content, drafts only, a published translation never overwritten.
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Connector;
use LinguaBridge\Storage;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
$post = static fn( $type, $name ) => get_posts( array( 'post_type' => $type, 'name' => $name, 'post_status' => 'any', 'numberposts' => 1 ) )[0] ?? null;
$created = array();
$users = array();
$user = static function ( $role ) use ( &$users ) {
	$id = wp_insert_user( array( 'user_login' => 'lb_' . $role . '_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	$users[] = $id;
	return $id;
};

try {
	wp_set_current_user( 1 );
	$only_it = $post( 'post', 'solo-italiano' );
	$post_it = $post( 'post', 'articolo-italiano' );
	$post_en = $post( 'post', 'english-article' );

	// The allowlist.
	$check( is_wp_error( Connector::run( 'eval', array() ) ), 'an unknown operation is refused' );
	$check( is_wp_error( Connector::run( 'saveDraft', array( 'post_status' => 'publish' ) ) ), 'an unknown argument is refused' );
	$check( is_wp_error( Connector::run( 'listWork', array( 'en' ) ) ), 'arguments must be an object' );

	$status = Connector::run( 'status', array() );
	$check( 'it' === $status['default'] && 2 === count( $status['languages'] ), 'status: languages of the site' );
	$brief = Connector::run( 'getBrief', array() );
	$check( isset( $brief['work']['totals']['en'] ), 'brief: the work to do per language' );

	$work = Connector::run( 'listWork', array( 'language' => 'en', 'state' => 'missing' ) );
	$ids = array_column( $work['items'], 'source_id' );
	$check( in_array( $only_it->ID, $ids, true ) && ! in_array( $post_it->ID, $ids, true ), 'listWork: what misses English, not what has it' );
	$check( is_wp_error( Connector::run( 'listWork', array( 'language' => 'it' ) ) ), 'listWork: the original language is not a target' );

	$source = Connector::run( 'getSource', array( 'post_id' => $only_it->ID, 'target_language' => 'en' ) );
	$check( ! is_wp_error( $source ) && 'missing' === $source['existing']['state'] && '' !== $source['translation_prompt'] && is_array( $source['report'] ), 'getSource: original, instructions, field report, nothing yet in English' );

	// F1: only the texts, numbered; the markup is rebuilt from the original's.
	$etch_page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB segmenti', 'post_content' => '<!-- wp:etch/element {"metadata":{"name":"Hero"},"tag":"section","attributes":{"class":"hero","aria-label":"Benvenuto"}} --><!-- wp:etch/text {"content":"Ciao mondo"} /--><!-- wp:etch/element {"tag":"a","attributes":{"href":"https://example.org/x","title":"Vai"}} --><!-- wp:etch/text {"content":"Scopri"} /--><!-- /wp:etch/element --><!-- /wp:etch/element -->' ) );
	$created[] = $etch_page;
	$segments = Connector::run( 'getSegments', array( 'post_id' => $etch_page, 'target_language' => 'en' ) );
	$check( ! is_wp_error( $segments ) && ! isset( $segments['content'] ) && array( 'Benvenuto', 'Ciao mondo', 'Vai', 'Scopri' ) === array_column( $segments['segments'], 'text' ) && 'c1' === $segments['segments'][0]['id'] && false !== strpos( $segments['translation_prompt'], 'A SEGMENTI' ), 'getSegments: the texts only, numbered, with the instructions for texts' );
	$saved = Connector::run( 'saveSegments', array( 'source_post_id' => $etch_page, 'language' => 'en', 'title' => 'LB segments', 'source_hash' => $segments['source_hash'] ?? '', 'segments' => array( array( 'id' => 'c1', 'text' => 'Welcome' ), array( 'id' => 'c2', 'text' => 'Hello <b>world</b>' ), array( 'id' => 'c4', 'text' => 'Discover' ) ) ) );
	$rebuilt = is_wp_error( $saved ) ? '' : get_post( $saved['post_id'] )->post_content;
	$check( ! is_wp_error( $saved ) && 'draft' === get_post_status( $saved['post_id'] ) && false !== strpos( $rebuilt, '"aria-label":"Welcome"' ) && false !== strpos( $rebuilt, '"content":"Hello world"' ) && false !== strpos( $rebuilt, '"title":"Vai"' ) && false !== strpos( $rebuilt, '"href":"https://example.org/x"' ) && false !== strpos( $rebuilt, '"metadata":{"name":"Hero"}' ), 'saveSegments: a draft with the original\'s markup and the translated texts (no tags; a text left out stays)' );
	if ( ! is_wp_error( $saved ) ) {
		$created[] = $saved['post_id'];
	}
	$check( is_wp_error( Connector::run( 'saveSegments', array( 'source_post_id' => $etch_page, 'language' => 'en', 'title' => 'x', 'segments' => array( array( 'id' => 'c99', 'text' => 'x' ) ) ) ) ), 'saveSegments: an id that getSegments did not give is refused' );
	$check( is_wp_error( Connector::run( 'saveSegments', array( 'source_post_id' => $etch_page, 'language' => 'en', 'title' => 'x', 'source_hash' => 'old', 'segments' => array() ) ) ), 'saveSegments: texts read from an older version of the original are refused' );
	$check( is_wp_error( Connector::run( 'saveSegments', array( 'source_post_id' => $post_it->ID, 'language' => 'en', 'title' => 'HIJACK', 'segments' => array() ) ) ) && 'publish' === get_post_status( $post_en->ID ), 'saveSegments: a published translation is never overwritten' );

	// F8: what a machine changed besides texts is noted for whoever reviews, not refused.
	$shape_it = '<!-- wp:etch/element {"tag":"div","attributes":{"class":"box"}} --><!-- wp:etch/text {"content":"Testo"} /--><!-- /wp:etch/element --><p><a href="https://example.org/a">link</a></p>';
	$check( array() === \LinguaBridge\Translations::structure_differences( $shape_it, str_replace( 'Testo', 'Text', $shape_it ) ), 'texts translated: no structure note' );
	$shape_notes = \LinguaBridge\Translations::structure_differences( $shape_it, str_replace( array( '"box"', 'example.org/a' ), array( '"box evil"', 'phish.example/a' ), $shape_it ) );
	$check( 2 === count( $shape_notes ) && false !== strpos( implode( ' ', $shape_notes ), 'attributes.class' ) && false !== strpos( implode( ' ', $shape_notes ), 'phish.example' ), 'a changed class and a link to another site are noted: ' . implode( ' | ', $shape_notes ) );
	$noted = Connector::run( 'saveDraft', array( 'source_post_id' => $etch_page, 'language' => 'en', 'title' => 'LB segments', 'content' => str_replace( array( 'Ciao mondo', 'example.org/x' ), array( 'Hello world', 'phish.example/x' ), get_post( $etch_page )->post_content ) ) );
	$check( ! is_wp_error( $noted ) && false !== strpos( implode( ' ', (array) get_post_meta( $noted['post_id'], \LinguaBridge\Translations::STRUCTURE_META, true ) ), 'phish.example' ), 'a draft saved by an agent keeps the notes for the review' );

	// Drafts only.
	$draft = Connector::run( 'saveDraft', array( 'source_post_id' => $only_it->ID, 'language' => 'en', 'title' => 'LB English only', 'content' => '<!-- wp:paragraph --><p>Only in English</p><!-- /wp:paragraph -->' ) );
	$check( ! is_wp_error( $draft ) && 'draft' === get_post_status( $draft['post_id'] ), 'saveDraft makes a draft' );
	if ( ! is_wp_error( $draft ) ) {
		$created[] = $draft['post_id'];
		$again = Connector::run( 'saveDraft', array( 'source_post_id' => $only_it->ID, 'language' => 'en', 'title' => 'LB English only 2', 'content' => 'x' ) );
		$check( ! is_wp_error( $again ) && $again['post_id'] === $draft['post_id'] && 'LB English only 2' === get_the_title( $draft['post_id'] ), 'saving again updates the same draft' );
		$row = Storage::find( $draft['post_id'] );
		$check( $row && 'en' === $row['language'] && 'needs_review' === $row['review_state'], 'the draft is linked as English, to review' );
	}
	$before = get_post( $post_en->ID );
	$overwrite = Connector::run( 'saveDraft', array( 'source_post_id' => $post_it->ID, 'language' => 'en', 'title' => 'HIJACK', 'content' => 'HIJACK' ) );
	$check( is_wp_error( $overwrite ) && $before->post_content === get_post( $post_en->ID )->post_content && 'publish' === get_post_status( $post_en->ID ), 'a published translation is never overwritten' );
	$correction = Connector::run( 'saveCorrection', array( 'published_post_id' => $post_en->ID, 'language' => 'en', 'title' => 'LB English article (fixed)', 'content' => 'fixed' ) );
	$check( ! is_wp_error( $correction ) && 'draft' === get_post_status( $correction['post_id'] ) && $correction['post_id'] !== $post_en->ID && 'LB English article' === get_the_title( $post_en->ID ), 'a correction is a separate draft; the published page stays' );
	if ( ! is_wp_error( $correction ) ) {
		$created[] = $correction['post_id'];
	}

	// An agent read untrusted text: active markup it adds is refused even for an administrator (unfiltered_html).
	foreach ( array( '<p>x</p><script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<a href="javascript:alert(1)">x</a>', '<iframe src="https://evil.example"></iframe>' ) as $bad ) {
		$injected = Connector::run( 'saveDraft', array( 'source_post_id' => $only_it->ID, 'language' => 'en', 'title' => 'LB English only', 'content' => $bad ) );
		$check( is_wp_error( $injected ) && 'lingua_active_markup' === $injected->get_error_code(), 'added active markup is refused: ' . $bad );
	}
	$embed = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB embed', 'post_content' => '<p>Video</p><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe>' ) );
	$created[] = $embed;
	$kept = Connector::run( 'saveDraft', array( 'source_post_id' => $embed, 'language' => 'en', 'title' => 'LB embed EN', 'content' => '<p>Video EN</p><iframe src="https://www.youtube-nocookie.com/embed/x"></iframe>' ) );
	$check( ! is_wp_error( $kept ), 'an embed already in the original stays in the translation' );
	if ( ! is_wp_error( $kept ) ) {
		$created[] = $kept['post_id'];
	}

	// A translation is a new item: a type nobody may create cannot be filled this way.
	register_post_type( 'lb_locked', array( 'show_ui' => true, 'supports' => array( 'title', 'editor' ), 'map_meta_cap' => true, 'capabilities' => array( 'create_posts' => 'do_not_allow' ) ) );
	$locked = wp_insert_post( array( 'post_type' => 'lb_locked', 'post_status' => 'publish', 'post_title' => 'LB bloccato' ) );
	$created[] = $locked;
	$denied = \LinguaBridge\Translations::create_draft( $locked, 'en' );
	$check( is_wp_error( $denied ), 'no translation of a type the user cannot create' );

	// Strings: an agent lists them and writes drafts; a published string is changed by a person only.
	$strings = Connector::run( 'listStrings', array( 'language' => 'en', 'status' => 'missing,published' ) );
	$by_source = array_column( $strings['items'], null, 'source' );
	$check( isset( $by_source['Contatti'], $by_source['Lingua Bridge Dev'] ), 'listStrings: menu labels and the site title' );
	\LinguaBridge\Strings::register( 'html', 'LB stringa per l\'agente' );
	$fresh = Connector::run( 'listStrings', array( 'language' => 'en' ) );
	$agent_row = array_values( array_filter( $fresh['items'], static fn( $r ) => 'LB stringa per l\'agente' === $r['source'] ) )[0];
	$written = Connector::run( 'saveStrings', array( 'language' => 'en', 'items' => array( array( 'id' => $agent_row['id'], 'translation' => '<b>LB string</b> for the agent' ), array( 'id' => $by_source['Contatti']['id'], 'translation' => 'HIJACK' ) ) ) );
	$after_row = \LinguaBridge\Strings::get( $agent_row['id'] );
	$check( 1 === $written['saved'] && 'draft' === $after_row['status'] && 'LB string for the agent' === $after_row['translation'], 'saveStrings writes a plain-text draft' );
	$check( 'Contacts' === \LinguaBridge\Strings::get( $by_source['Contatti']['id'] )['translation'], 'a published string is not changed by an agent' );
	\LinguaBridge\Strings::remove( $agent_row['id'] );

	// The user's own permissions.
	wp_set_current_user( $user( 'subscriber' ) );
	$check( ! Connector::can_connect(), 'a subscriber cannot link a tab' );
	$check( is_wp_error( Connector::run( 'getSource', array( 'post_id' => $only_it->ID, 'target_language' => 'en' ) ) ), 'a subscriber cannot read a source' );
	$author = $user( 'author' );
	wp_set_current_user( $author );
	$check( Connector::can_connect(), 'an author can link a tab' );
	$check( is_wp_error( Connector::run( 'getSource', array( 'post_id' => $only_it->ID, 'target_language' => 'en' ) ) ), 'an author cannot read someone else\'s post' );
	$check( is_wp_error( Connector::run( 'listStrings', array( 'language' => 'en' ) ) ), 'an author cannot touch the site strings' );
	$check( is_wp_error( Connector::run( 'saveDraft', array( 'source_post_id' => $only_it->ID, 'language' => 'en', 'title' => 'x', 'content' => 'x' ) ) ), 'an author cannot translate someone else\'s post' );
	$check( ! in_array( $only_it->ID, array_column( Connector::run( 'listWork', array() )['items'], 'source_id' ), true ), 'an author\'s list has only their posts' );
	$own = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'LB autore', 'post_author' => $author ) );
	$created[] = $own;
	$mine = Connector::run( 'saveDraft', array( 'source_post_id' => $own, 'language' => 'en', 'title' => 'LB author', 'content' => '<p>ok</p><script>alert(1)</script>' ) );
	$check( is_wp_error( $mine ) && 'lingua_active_markup' === $mine->get_error_code(), 'an author\'s translation cannot add a script either' );
	$plain = Connector::run( 'saveDraft', array( 'source_post_id' => $own, 'language' => 'en', 'title' => 'LB author', 'content' => '<p>ok</p>' ) );
	$check( ! is_wp_error( $plain ), 'an author translates their own post' );
	if ( ! is_wp_error( $plain ) ) {
		$created[] = $plain['post_id'];
	}
	if ( ! is_wp_error( $mine ) ) {
		$created[] = $mine['post_id'];
	}
} finally {
	wp_set_current_user( 1 );
	foreach ( $created as $id ) {
		Storage::delete( $id );
		wp_delete_post( $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $users as $id ) {
		wp_delete_user( $id );
	}
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
