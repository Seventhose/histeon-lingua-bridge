<?php
/**
 * The static copy of Static Bridge served as its Apache and Nginx rules serve it (dev/compose.static.yml, C9): the
 * pages from the copy (a folder by its index.html, a missing page is the copy's 404.html with status 404), the files
 * of wp-content and wp-includes from the site itself, nothing runs. Static Bridge answers on the site's own address;
 * here the copy has another port, so in its HTML the site's address (LB_ORIGIN) becomes the address asked for.
 */
// The copy in use at each request: Static Bridge moves its `current` link to every new release.
clearstatcache( true );
$root = rtrim( (string) realpath( '/srv/wp/wp-content/static-bridge/current' ), '/' );
$site = '/srv/wp';
$path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
$types = array( 'html' => 'text/html; charset=UTF-8', 'xml' => 'application/xml; charset=UTF-8', 'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'txt' => 'text/plain; charset=UTF-8', 'svg' => 'image/svg+xml', 'woff2' => 'font/woff2' );
$send = static function ( $file ) use ( $types ) {
	$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	header( 'Content-Type: ' . ( $types[ $extension ] ?? ( mime_content_type( $file ) ?: 'application/octet-stream' ) ) );
	$body = (string) file_get_contents( $file );
	$origin = (string) getenv( 'LB_ORIGIN' );
	echo 'html' === $extension && '' !== $origin ? str_replace( $origin, 'http://' . $_SERVER['HTTP_HOST'], $body ) : $body;
	return true;
};
if ( preg_match( '~^/wp-(content|includes)/~', $path ) && ! preg_match( '/\.php$/i', $path ) ) {
	$file = realpath( $site . $path );
	if ( false !== $file && 0 === strpos( $file, $site . '/' ) && is_file( $file ) && false === strpos( $file, '/static-bridge/' ) ) {
		return $send( $file );
	}
}
$file = realpath( $root . $path );
if ( false !== $file && is_dir( $file ) ) {
	if ( '/' !== substr( $path, -1 ) ) {
		header( 'Location: ' . $path . '/', true, 301 );
		return true;
	}
	$file = realpath( $file . '/index.html' );
}
if ( false === $file || 0 !== strpos( $file, $root . '/' ) || ! is_file( $file ) || preg_match( '/\.php$/i', $file ) ) {
	http_response_code( 404 );
	$missing = $root . '/404.html';
	return is_file( $missing ) ? $send( $missing ) : print 'Not found';
}
return $send( $file );
