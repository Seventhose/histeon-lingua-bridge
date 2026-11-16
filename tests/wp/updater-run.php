<?php
/**
 * Signed updates from GitHub releases (includes/class-release-updater.php, docs/UPDATES.md). GitHub is replaced by
 * pre_http_request: no network. The repository and the release key come from the wp-config constants, as on a site
 * that chooses its own.
 */

add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
require_once ABSPATH . 'wp-admin/includes/file.php';
use LinguaBridge\ReleaseUpdater;

$pair = sodium_crypto_sign_keypair();
$id   = random_bytes( 8 );
define( 'LINGUA_BRIDGE_UPDATE_REPOSITORY', 'acme/lb' );
define( 'LINGUA_BRIDGE_UPDATE_PUBLIC_KEY', base64_encode( 'Ed' . $id . sodium_crypto_sign_publickey( $pair ) ) );

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
$signature = static function ( string $data, string $name, string $secret = '' ) use ( $pair, $id ): string {
	$sk      = '' === $secret ? sodium_crypto_sign_secretkey( $pair ) : $secret;
	$sig     = sodium_crypto_sign_detached( sodium_crypto_generichash( $data, '', 64 ), $sk );
	$comment = "timestamp:1\tfile:$name\thashed";
	return "untrusted comment: x\n" . base64_encode( 'ED' . $id . $sig ) . "\ntrusted comment: $comment\n" . base64_encode( sodium_crypto_sign_detached( $sig . $comment, $sk ) ) . "\n";
};
$requests = array();
$github = static function ( string $version, string $zip, string $sig, array $extra = array() ) use ( &$requests ): void {
	remove_all_filters( 'pre_http_request' );
	delete_site_transient( ReleaseUpdater::CACHE );
	$base = "https://github.com/acme/lb/releases/download/v$version/";
	add_filter(
		'pre_http_request',
		static function ( $pre, array $args, string $url ) use ( $version, $zip, $sig, $base, $extra, &$requests ) {
			$requests[] = $url;
			$api        = array_merge(
				array(
					'tag_name' => "v$version",
					'html_url' => "https://github.com/acme/lb/releases/tag/v$version",
					'assets'   => array(
						array( 'name' => "lingua-bridge-$version.zip", 'browser_download_url' => $base . "lingua-bridge-$version.zip" ),
						array( 'name' => "lingua-bridge-$version.zip.minisig", 'browser_download_url' => $base . "lingua-bridge-$version.zip.minisig" ),
					),
				),
				$extra
			);
			$bodies = array(
				'https://api.github.com/repos/acme/lb/releases/latest' => (string) wp_json_encode( $api ),
				$base . "lingua-bridge-$version.zip"                   => $zip,
				$base . "lingua-bridge-$version.zip.minisig"           => $sig,
			);
			if ( ! isset( $bodies[ $url ] ) ) {
				return new WP_Error( 'blocked', 'no network in tests' );
			}
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $bodies[ $url ] ); // download_url streams to a file.
			}
			return array( 'headers' => array(), 'body' => $bodies[ $url ], 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
		},
		10,
		3
	);
};
$plugin = ReleaseUpdater::basename();

try {
	$check( 'lingua-bridge/lingua-bridge.php' === $plugin && ReleaseUpdater::standalone(), 'the standalone plugin updates itself' );
	$check( false !== has_filter( 'pre_set_site_transient_update_plugins', array( ReleaseUpdater::class, 'offer' ) ), 'it is on by default' );
	$check( 'acme/lb' === ReleaseUpdater::settings()['repository'] && ReleaseUpdater::settings()['enabled'], 'wp-config chooses repository and key' );
	$check( str_contains( (string) get_file_data( LINGUA_BRIDGE_FILE, array( 'uri' => 'Update URI' ) )['uri'], 'github.com/Seventhose/histeon-lingua-bridge' ), 'Update URI: never an update from the plugin directory under the same slug' );

	// A signed release: offered, downloaded, verified.
	$zip = 'PK pacchetto di prova ' . random_bytes( 64 );
	$github( '9.1.0', $zip, $signature( $zip, 'lingua-bridge-9.1.0.zip' ) );
	$transient = ReleaseUpdater::offer( (object) array( 'response' => array() ) );
	$offer     = $transient->response[ $plugin ] ?? null;
	$check( $offer && '9.1.0' === $offer->new_version && 'https://github.com/acme/lb/releases/download/v9.1.0/lingua-bridge-9.1.0.zip' === $offer->package, 'a newer signed release is offered' );
	$file = ReleaseUpdater::download( false, $offer->package ?? '', null, array( 'plugin' => $plugin ) );
	$check( is_string( $file ) && $zip === file_get_contents( $file ), 'the verified file goes to WordPress' );
	is_string( $file ) && wp_delete_file( $file );
	$check( 'altro' === ReleaseUpdater::download( 'altro', $offer->package ?? '' ), 'another handler\'s answer is left alone' );
	$check( false === ReleaseUpdater::download( false, 'https://example.com/altro.zip', null, array( 'plugin' => 'altro/altro.php' ) ), 'other packages stay WordPress\'s' );
	$count = count( $requests );
	ReleaseUpdater::latest();
	$check( count( $requests ) === $count, 'GitHub is asked at most every 12 hours' );

	// The installed version or an older one: nothing offered.
	$github( LINGUA_BRIDGE_VERSION, $zip, $signature( $zip, 'lingua-bridge-' . LINGUA_BRIDGE_VERSION . '.zip' ) );
	$check( ! isset( ReleaseUpdater::offer( (object) array( 'response' => array() ) )->response[ $plugin ] ), 'the installed version is not offered again' );

	// Tampered file, another key, another package's signature, an altered trusted comment: never installed.
	$github( '9.2.0', $zip . ' manomesso', $signature( $zip, 'lingua-bridge-9.2.0.zip' ) );
	$result = ReleaseUpdater::download( false, ReleaseUpdater::latest()['zip'] ?? '', null, array( 'plugin' => $plugin ) );
	$check( is_wp_error( $result ) && str_contains( $result->get_error_message(), 'non corrisponde' ), 'a tampered package is refused' );
	$other = sodium_crypto_sign_secretkey( sodium_crypto_sign_keypair() );
	$github( '9.2.1', $zip, $signature( $zip, 'lingua-bridge-9.2.1.zip', $other ) );
	$result = ReleaseUpdater::download( false, ReleaseUpdater::latest()['zip'] ?? '', null, array( 'plugin' => $plugin ) );
	$check( is_wp_error( $result ), 'a signature by another key is refused' );
	foreach ( array( 'lingua-bridge-1.0.0.zip', 'lingua-bridge-9.3.0-alt.zip', 'not-lingua-bridge-9.3.0.zip' ) as $name ) {
		$github( '9.3.0', $zip, $signature( $zip, $name ) );
		$result = ReleaseUpdater::download( false, ReleaseUpdater::latest()['zip'] ?? '', null, array( 'plugin' => $plugin ) );
		$check( is_wp_error( $result ) && str_contains( $result->get_error_message(), 'altro pacchetto' ), "a genuine signature of another package ($name) is refused" );
	}
	$altered = str_replace( 'timestamp:1', 'timestamp:2', $signature( $zip, 'lingua-bridge-9.3.1.zip' ) );
	$github( '9.3.1', $zip, $altered );
	$result = ReleaseUpdater::download( false, ReleaseUpdater::latest()['zip'] ?? '', null, array( 'plugin' => $plugin ) );
	$check( is_wp_error( $result ) && str_contains( $result->get_error_message(), 'alterato' ), 'an altered trusted comment is refused' );

	// Between the offer and the install the release changes: our update is refused, not installed unchecked.
	$github( '9.4.0', $zip, $signature( $zip, 'lingua-bridge-9.4.0.zip' ) );
	$package = ReleaseUpdater::offer( (object) array( 'response' => array() ) )->response[ $plugin ]->package ?? '';
	remove_all_filters( 'pre_http_request' );
	delete_site_transient( ReleaseUpdater::CACHE );
	add_filter( 'pre_http_request', static fn () => array( 'response' => array( 'code' => 403 ), 'body' => '' ) );
	$result = ReleaseUpdater::download( false, $package, null, array( 'plugin' => $plugin ) );
	$check( is_wp_error( $result ) && 'lingua_bridge_update_signature' === $result->get_error_code(), 'a stale offer is refused' );

	// Releases without a signed ZIP from github.com are ignored.
	$check( null === ReleaseUpdater::parse( array( 'tag_name' => 'v1.2.3', 'assets' => array( array( 'name' => 'lingua-bridge-1.2.3.zip', 'browser_download_url' => 'https://github.com/a/b/z' ) ) ) ), 'no signature, no release' );
	$check( null === ReleaseUpdater::parse( array( 'tag_name' => 'v1.2.3', 'prerelease' => true ) ), 'pre-releases are ignored' );
	$check( null === ReleaseUpdater::parse( array( 'tag_name' => 'v1.2.3', 'draft' => true ) ), 'drafts are ignored' );
	$check( null === ReleaseUpdater::parse( array( 'tag_name' => 'v1.2.3', 'assets' => array( array( 'name' => 'lingua-bridge-1.2.3.zip', 'browser_download_url' => 'http://evil.example/z' ), array( 'name' => 'lingua-bridge-1.2.3.zip.minisig', 'browser_download_url' => 'https://github.com/a/b/s' ) ) ) ), 'only from github.com' );
} finally {
	remove_all_filters( 'pre_http_request' );
	delete_site_transient( ReleaseUpdater::CACHE );
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
