<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C8 of the competition list (1.11.0): the search on a static copy.
 *
 * A static copy has no `?s=`: a static host answers `/en/?s=word` with the home page. Lingua Bridge publishes an
 * index for each language, a page `/<prefix>/lingua-bridge-cerca/` (HTML, so Static Bridge copies it as any page)
 * with the published contents of that language as JSON: title, a short excerpt, address. With Static Bridge active
 * a small script goes on every page; where a page shows `?s=` but is not WordPress's page of results (the static
 * copy), it reads the index of the page's language and shows what it finds. On the live site nothing changes.
 */
final class StaticSearch {
	const SLUG = 'lingua-bridge-cerca';
	/** At most so many contents in an index, and words in an excerpt. */
	const MAX = 2000;
	const WORDS = 30;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'static_bridge_export_urls', array( __CLASS__, 'export_urls' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ) );
	}

	/** Whether the static search is on: with Static Bridge, unless the filter `lingua_bridge_static_search` says no. */
	public static function enabled() {
		return (bool) apply_filters( 'lingua_bridge_static_search', class_exists( '\\Static_Bridge_Plugin' ) );
	}

	/**
	 * Whether this request asks for an index: `/lingua-bridge-cerca/` under the site, after the language's prefix.
	 * No rewrite rule (WordPress answers 404 first, then this page): nothing to flush on an update.
	 */
	public static function is_index_request() {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = trim( substr( $path, strlen( $base ) ), '/' );
		$language = Routing::request_language();
		if ( '' !== $language ) {
			$prefix = array_search( $language, Routing::language_prefixes(), true );
			$path = false !== $prefix && 0 === strpos( $path . '/', $prefix . '/' ) ? trim( substr( $path, strlen( (string) $prefix ) ), '/' ) : $path;
		}
		return self::SLUG === $path;
	}

	/** The address of the index of `$language`. */
	public static function url( $language ) {
		return Routing::language_url( home_url( '/' . self::SLUG . '/' ), $language );
	}

	/**
	 * The index of `$language`: its published contents without a password, of the translatable types, newest first.
	 *
	 * @return list<array{t: string, e: string, u: string}>
	 */
	public static function index( $language ) {
		$types = array_values( array_filter( Translations::translatable_types(), static fn( $type ) => is_post_type_viewable( $type ) ) );
		if ( empty( $types ) ) {
			return array();
		}
		$posts = get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'has_password' => false, 'numberposts' => self::MAX * 2, 'orderby' => 'date', 'order' => 'DESC', 'suppress_filters' => true, 'no_found_rows' => true ) );
		$out = array();
		foreach ( $posts as $post ) {
			$row = Storage::find( $post->ID );
			if ( ( $row ? $row['language'] : Languages::default_code() ) !== $language ) {
				continue;
			}
			$excerpt = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : strip_shortcodes( excerpt_remove_blocks( $post->post_content ) );
			$out[] = array(
				't' => wp_strip_all_tags( get_the_title( $post ) ),
				'e' => wp_trim_words( wp_strip_all_tags( $excerpt ), self::WORDS, '…' ),
				'u' => wp_make_link_relative( (string) get_permalink( $post ) ),
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/** The page of the index: HTML (Static Bridge copies only HTML), the JSON inside, never indexed. */
	public static function serve() {
		if ( ! self::is_index_request() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$language = '' !== Routing::request_language() ? Routing::request_language() : Languages::default_code();
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo '<!doctype html><html lang="' . esc_attr( $language ) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . esc_html( get_bloginfo( 'name' ) ) . '</title></head><body><script type="application/json" id="lb-search-index">' . wp_json_encode( self::index( $language ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < and & escaped.
		exit;
	}

	/** Static Bridge copies the index of every language. */
	public static function export_urls( $urls ) {
		$urls = is_array( $urls ) ? $urls : array();
		if ( self::enabled() ) {
			foreach ( array_keys( Languages::all() ) as $language ) {
				$urls[] = self::url( $language );
			}
		}
		return $urls;
	}

	public static function script() {
		if ( ! self::enabled() ) {
			return;
		}
		$language = Frontend::current_language();
		wp_enqueue_script( 'lingua-bridge-static-search', plugins_url( 'assets/static-search.js', LINGUA_BRIDGE_FILE ), array(), LINGUA_BRIDGE_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script(
			'lingua-bridge-static-search',
			'lbStaticSearch',
			array(
				'index'   => wp_make_link_relative( self::url( $language ) ),
				'lang'    => $language,
				'title'   => __( 'Risultati della ricerca', 'lingua-bridge' ),
				'none'    => __( 'Nessun risultato.', 'lingua-bridge' ),
				'close'   => __( 'Chiudi', 'lingua-bridge' ),
			)
		);
	}
}
