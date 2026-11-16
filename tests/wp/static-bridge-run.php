<?php
/**
 * Static Bridge next to Lingua Bridge (dev/test.sh with STATIC_BRIDGE_PLUGIN): Static Bridge's own code chooses
 * the routes. A full export finds every language; a changed English post refreshes the English lists; a queued page
 * brings its published translations.
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};

$plugin = null;
foreach ( isset( $GLOBALS['wp_filter']['static_bridge_queue_paths'] ) ? $GLOBALS['wp_filter']['static_bridge_queue_paths']->callbacks : array() as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Static_Bridge_Plugin ) {
			$plugin = $callback['function'][0];
		}
	}
}
$check( null !== $plugin, 'Static Bridge is active' );
if ( null === $plugin ) {
	exit( 1 );
}
$call = static fn( string $method, ...$args ) => ( new ReflectionMethod( $plugin, $method ) )->invokeArgs( $plugin, $args );
$paths = static fn( $urls ) => array_map( static fn( $u ) => (string) wp_parse_url( $u, PHP_URL_PATH ), (array) $urls );
$post = static fn( $type, $name ) => get_posts( array( 'post_type' => $type, 'name' => $name, 'numberposts' => 1 ) )[0] ?? null;

// Incremental: the lists a changed post sends back to the static copy.
$en_lists = $paths( $call( 'archive_urls_for_post', $post( 'post', 'english-article' ) ) );
foreach ( array( '/', '/category/news/', '/en/', '/en/category/news/' ) as $expected ) {
	$check( in_array( $expected, $en_lists, true ), "an English post refreshes $expected" );
}
$it_lists = $paths( $call( 'archive_urls_for_post', $post( 'post', 'articolo-italiano' ) ) );
$check( ! array_filter( $it_lists, static fn( $p ) => 0 === strpos( $p, '/en/' ) ), 'an Italian post refreshes no English list' );
$event_lists = $paths( $call( 'archive_urls_for_post', $post( 'evento', 'event-lb' ) ) );
$check( in_array( '/en/eventi/', $event_lists, true ), 'an English event refreshes the English event archive' );

// Incremental, translations (doc 23, item 181): each queued page brings its published translations, through
// Static Bridge's own code (static_bridge_translated_urls, Static Bridge 2.2.9).
$queued = $paths( $call( 'with_translated_urls', array( get_permalink( $post( 'post', 'articolo-italiano' ) ), get_permalink( $post( 'page', 'about' ) ) ) ) );
foreach ( array( '/articolo-italiano/', '/en/english-article/', '/en/about/', '/chi-siamo/' ) as $expected ) {
	$check( in_array( $expected, $queued, true ), "an incremental update of the Italian article and of the English page queues $expected" );
}
$check( 4 === count( $queued ), 'and nothing else: ' . implode( ', ', $queued ) );
$check( array( '/category/news/' ) === $paths( $call( 'with_translated_urls', array( home_url( '/category/news/' ) ) ) ), 'a list page brings no translation' );

// Full export: what the inventory names. Static Bridge 2.2 reads the posts in batches, then the archives and the
// routes of static_bridge_export_urls (it no longer reads the sitemaps).
$inventory = array();
$cursor    = 0;
for ( $guard = 0; $guard < 100; ++$guard ) {
	$batch = $call( 'discover_wordpress_post_url_batch', $cursor );
	if ( ! is_array( $batch ) ) {
		break;
	}
	$inventory = array_merge( $inventory, $paths( $batch['urls'] ) );
	$cursor    = (int) $batch['cursor'];
	if ( ! empty( $batch['complete'] ) ) {
		break;
	}
}
foreach ( array( '/en/', '/en/about/', '/en/english-article/' ) as $expected ) {
	$check( in_array( $expected, $inventory, true ), "the export inventory has $expected" );
}
$archives = array();
$phase    = 'terms';
$cursor   = 0;
for ( $guard = 0; $guard < 1000 && 'done' !== $phase; ++$guard ) {
	$batch = $call( 'discover_wordpress_archive_url_batch', $phase, $cursor );
	if ( ! is_array( $batch ) ) {
		break;
	}
	$archives = array_merge( $archives, $paths( $batch['urls'] ) );
	$phase    = (string) $batch['phase'];
	$cursor   = (int) $batch['cursor'];
}
foreach ( array( '/en/category/news/', '/en/eventi/', '/category/news/' ) as $expected ) {
	$check( in_array( $expected, $archives, true ), "a full export finds $expected" );
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
