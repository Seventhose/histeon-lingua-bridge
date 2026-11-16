<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * «Trova i testi da tradurre» (Stringhe): the texts of the theme and of the builder's header and footer that a page
 * in another language still shows in the original's language. The main pages of the language (its home, its latest
 * translations, a list) are asked as a visitor sees them, next to their originals: a text that is the same on both,
 * outside the contents, is not translated yet and becomes a string to translate. Then the translation service can
 * write drafts of the missing strings, which a person approves (the machines write only drafts).
 */
final class StringScan {
	/** Pages asked per language, originals included in the pairs. */
	const MAX_PAIRS = 8;
	/** Strings added by one search at most. */
	const MAX_FOUND = 300;
	/** Strings sent to the service by one click at most. */
	const MAX_MACHINE = 100;
	/** A page larger than this is not read. */
	const MAX_BYTES = 2097152;

	/**
	 * Finds and registers the strings of `$language`.
	 *
	 * @return array{pages: int, found: int, added: int}|\WP_Error
	 */
	public static function scan( $language ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi necessari.', 'lingua-bridge' ) );
		}
		if ( ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language || ! Storage::is_available() ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}
		$pages = 0;
		$found = array();
		foreach ( self::pairs( $language ) as $pair ) {
			$original = self::fetch( $pair['original'] );
			$translated = '' === $original ? '' : self::fetch( $pair['translated'] );
			if ( '' === $translated ) {
				continue;
			}
			++$pages;
			$same = array_intersect_key( self::texts( $translated ), self::texts( $original ) );
			$found += array_diff_key( $same, $pair['content'] );
		}
		foreach ( Languages::all() as $code => $label ) {
			unset( $found[ Strings::normalize( $label ) ], $found[ Strings::normalize( strtoupper( $code ) ) ] );
		}
		$found = array_slice( $found, 0, self::MAX_FOUND, true );
		$before = array_sum( Strings::counts( $language ) );
		foreach ( $found as $text ) {
			Strings::register( 'html', $text );
		}
		return array( 'pages' => $pages, 'found' => count( $found ), 'added' => array_sum( Strings::counts( $language ) ) - $before );
	}

	/**
	 * The pages to compare: the home of the language, its latest published translations, a list of its articles.
	 * Each with the texts of the two contents themselves, which are not strings (they are translated as contents).
	 *
	 * @return list<array{original: string, translated: string, content: array<string, true>}>
	 */
	public static function pairs( $language ) {
		global $wpdb;
		$pairs = array();
		$home = Routing::translated_home_id( $language );
		if ( $home || 'page' !== get_option( 'show_on_front' ) ) {
			$pairs[] = array(
				'original'   => home_url( '/' ),
				'translated' => $home ? (string) get_permalink( $home ) : Routing::language_url( home_url( '/' ), $language ),
				'content'    => $home ? self::content_texts( array( $home, Routing::source_front_page_id() ) ) : array(),
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT l.object_id, l.source_id FROM ' . Storage::table() . " l INNER JOIN {$wpdb->posts} p ON p.ID = l.object_id WHERE l.language = %s AND l.object_id <> l.source_id AND p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ('post', 'page') ORDER BY p.post_modified_gmt DESC LIMIT %d", $language, self::MAX_PAIRS * 2 ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$translation = absint( $row['object_id'] );
			$source = get_post( absint( $row['source_id'] ) );
			if ( $translation === $home || ! $source || ! Frontend::online( $source ) || '' !== (string) $source->post_password ) {
				continue;
			}
			$pairs[] = array( 'original' => (string) get_permalink( $source ), 'translated' => (string) get_permalink( $translation ), 'content' => self::content_texts( array( $translation, $source->ID ) ) );
			if ( count( $pairs ) >= self::MAX_PAIRS ) {
				break;
			}
		}
		return array_slice( $pairs, 0, self::MAX_PAIRS );
	}

	/** @return array<string, true> The normalized texts of the contents (title, excerpt, segments). */
	private static function content_texts( $ids ) {
		$texts = array();
		foreach ( $ids as $id ) {
			$post = $id ? get_post( absint( $id ) ) : null;
			if ( ! $post ) {
				continue;
			}
			foreach ( array_merge( array( $post->post_title, $post->post_excerpt ), array_column( Xliff::segment( $post->post_content )['segments'], 'text' ) ) as $text ) {
				$texts[ Strings::normalize( wp_strip_all_tags( (string) $text ) ) ] = true;
			}
		}
		return $texts;
	}

	/** A page of this site as a visitor sees it, or '' (not this site, not HTML, not 200, too large). */
	private static function fetch( $url ) {
		if ( ! is_string( $url ) || '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) {
			return '';
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => self::MAX_BYTES,
				// The site asking itself, as WordPress's own loopback checks (Site Health) do.
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || false === stripos( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'html' ) ) {
			return '';
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * The texts of a page's body that people read: text nodes and the alt, title, placeholder, aria-label
	 * attributes, outside head, scripts, styles, code, SVG, comments and the admin bar.
	 *
	 * @return array<string, string> Normalized text ⇒ text.
	 */
	public static function texts( $html ) {
		$body = preg_replace( '~\A.*?<body\b[^>]*>~is', '', (string) $html, 1 );
		$body = preg_replace( '~<!--.*?-->|<(script|style|noscript|template|svg|code|pre|textarea)\b[^>]*>.*?</\1\s*>|<div id="wpadminbar".*?</ul>\s*</div>\s*</div>~is', ' ', (string) $body );
		$out = array();
		$add = static function ( $raw ) use ( &$out ) {
			$text = Strings::normalize( html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $text && mb_strlen( $text ) <= 200 && preg_match( '/\p{L}/u', $text ) && ! preg_match( '/\A\{[^{}]*\}\z/u', $text ) ) {
				$out[ $text ] = $text;
			}
		};
		if ( preg_match_all( '~>([^<>]+)<~u', (string) $body, $nodes ) ) {
			array_map( $add, $nodes[1] );
		}
		if ( preg_match_all( '~\s(?:alt|title|placeholder|aria-label)="([^"]*)"~u', (string) $body, $attributes ) ) {
			array_map( $add, $attributes[1] );
		}
		return $out;
	}

	/**
	 * Drafts of the missing strings of `$language` from the translation service, to approve in «Stringhe».
	 *
	 * @return array{sent: int, saved: int}|\WP_Error
	 */
	public static function machine( $language ) {
		if ( ! current_user_can( 'manage_options' ) || ! MachineTranslation::can_use() ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi necessari.', 'lingua-bridge' ) );
		}
		if ( ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}
		$missing = Strings::query( $language, array( 'status' => array( 'missing' ), 'limit' => self::MAX_MACHINE ) )['items'];
		$missing = array_values( array_filter( $missing, static fn( $row ) => 'menu' !== $row['context'] ) );
		if ( empty( $missing ) ) {
			return array( 'sent' => 0, 'saved' => 0 );
		}
		$translated = MachineTranslation::translate_texts( array_column( $missing, 'source' ), Languages::default_code(), $language );
		if ( is_wp_error( $translated ) ) {
			return $translated;
		}
		$saved = 0;
		foreach ( $missing as $i => $row ) {
			$text = isset( $translated[ $i ] ) ? (string) $translated[ $i ] : '';
			if ( '' !== trim( $text ) && ! is_wp_error( Strings::save( absint( $row['id'] ), $text, 'draft' ) ) ) {
				++$saved;
			}
		}
		return array( 'sent' => count( $missing ), 'saved' => $saved );
	}
}
