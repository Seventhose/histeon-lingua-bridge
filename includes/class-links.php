<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internal links of a page in another language lead to that language (docs/SWITCHER.md, «I link interni»). Menus
 * of a builder (an Etch header), buttons and links written in the content point to the original's pages: on a
 * translated page each internal link to a content with a published version in the page's language becomes that
 * version's address, and a link to a list (home, category, author, archive) gets the language prefix. Anything
 * else stays as it is. Links between languages (hreflang, the switchers) are never touched.
 *
 * The page is rewritten once, when it is printed (an output buffer, like the site's strings); each address is
 * resolved once and kept until content changes (a transient per language and content version).
 */
final class Links {
	/** Setting (URL e SEO): on unless turned off. */
	const SETTING = 'rewrite_links';
	/** At most this many addresses are kept per language. */
	const MAX_CACHED = 2000;

	/** @var array<string, string> Path ⇒ address in the page's language ('' = leave as it is). */
	private static $map = array();
	private static $map_key = '';
	private static $dirty = false;

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 999 );
		add_action( 'shutdown', array( __CLASS__, 'save' ) );
	}

	public static function enabled() {
		return Routing::enabled() && false !== ( Languages::settings()[ self::SETTING ] ?? true );
	}

	/** Only on pages in another language than the original's. */
	public static function start_buffer() {
		$language = Frontend::current_language();
		if ( ! self::enabled() || Languages::default_code() === $language || is_feed() || is_robots() ) {
			return;
		}
		ob_start(
			static function ( $html ) use ( $language ) {
				return is_string( $html ) ? self::apply_html( $html, $language ) : $html;
			}
		);
	}

	/**
	 * Every href of an <a> or <area> outside scripts, styles and comments, with no hreflang (a link to another
	 * language on purpose), rewritten by url().
	 */
	public static function apply_html( $html, $language ) {
		if ( '' === $html || false === stripos( $html, 'href' ) ) {
			return $html;
		}
		$result = preg_replace_callback(
			'~(<!--.*?-->|<(script|style|textarea|code|pre)\b[^>]*>.*?</\2\s*>)|<(?:a|area)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~is',
			static function ( $m ) use ( $language ) {
				if ( ( isset( $m[1] ) && '' !== $m[1] ) || preg_match( '~\shreflang\s*=~i', $m[0] ) ) {
					return $m[0];
				}
				return (string) preg_replace_callback(
					'~(\shref\s*=\s*)(["\'])(.*?)\2~is',
					static function ( $h ) use ( $language ) {
						$url = html_entity_decode( $h[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$to  = self::url( $url, $language );
						return '' === $to || $to === $url ? $h[0] : $h[1] . $h[2] . esc_url( $to ) . $h[2];
					},
					$m[0]
				);
			},
			$html
		);
		return null === $result ? $html : $result;
	}

	/** The address of `$url` in `$language`, or '' when it stays as it is (outside the site, a file, another language). */
	public static function url( $url, $language ) {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url[0] || ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		// Absolute on this host, or a path from the root («/chi-siamo/»); never another site, never «//host».
		if ( isset( $parts['host'] ) ) {
			if ( strtolower( $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) || ( isset( $parts['port'] ) && (int) $parts['port'] !== (int) ( $home['port'] ?? $parts['port'] ) ) ) {
				return '';
			}
		} elseif ( isset( $parts['scheme'] ) || 0 !== strpos( $url, '/' ) || 0 === strpos( $url, '//' ) ) {
			return '';
		}
		$base = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		$path = (string) ( $parts['path'] ?? '/' );
		if ( '' !== $base && 0 !== strpos( $path, $base . '/' ) && $path !== $base ) {
			return '';
		}
		$relative = '/' . ltrim( (string) substr( $path, strlen( $base ) ), '/' );
		$first = strtolower( (string) strtok( trim( $relative, '/' ), '/' ) );
		// Already in a language, WordPress's own files and screens, a file of the uploads: as they are.
		if ( isset( Routing::language_prefixes()[ $first ] ) || preg_match( '~\A(?:wp-admin|wp-content|wp-includes|wp-json|wp-login\.php|xmlrpc\.php|wp-cron\.php|feed)\z~', $first ) || preg_match( '~\.[a-z0-9]{2,5}\z~i', $relative ) ) {
			return '';
		}
		$resolved = self::resolve( $relative, $language );
		if ( '' === $resolved ) {
			return '';
		}
		return $resolved . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/** A path of this site in `$language`: the published version of its content, or the list under the prefix. */
	private static function resolve( $relative, $language ) {
		$key = 'lb_links_' . md5( $language . '|' . absint( get_option( Storage::CONTENT_VERSION_OPTION, 0 ) ) );
		if ( self::$map_key !== $key ) {
			self::save();
			$cached = get_transient( $key );
			self::$map = is_array( $cached ) ? $cached : array();
			self::$map_key = $key;
		}
		if ( isset( self::$map[ $relative ] ) ) {
			return self::$map[ $relative ];
		}
		self::$map[ $relative ] = self::lookup( $relative, $language );
		self::$dirty = true;
		return self::$map[ $relative ];
	}

	private static function lookup( $relative, $language ) {
		$absolute = home_url( $relative );
		if ( '/' === $relative ) {
			$home = Routing::translated_home_id( $language );
			if ( $home ) {
				return (string) get_permalink( $home );
			}
			return 'page' === get_option( 'show_on_front' ) ? '' : Routing::language_url( $absolute, $language );
		}
		// The original's page: found as it is, not among the contents of the language being shown.
		Routing::force_language( Languages::default_code() );
		$post_id = url_to_postid( $absolute );
		$blog = $post_id ? array( 0, '' ) : self::posts_page_at( $relative );
		Routing::force_language( '' );
		// The page of the articles is a list for WordPress, not a page: url_to_postid() never finds it.
		if ( $blog[0] ) {
			$row = Storage::find( $blog[0] );
			$member = $row ? Storage::find_group_language( $row['group_uuid'], $language ) : null;
			$post = $member ? get_post( absint( $member['object_id'] ) ) : null;
			if ( $post && $post->ID !== $blog[0] && Frontend::online( $post ) && '' === (string) $post->post_password ) {
				return '' === $blog[1] ? (string) get_permalink( $post ) : trailingslashit( (string) get_permalink( $post ) ) . $blog[1];
			}
			return '';
		}
		if ( $post_id ) {
			// An address without a prefix is the original's, even when a translation has the same slug (N3).
			$row = Storage::find( $post_id );
			if ( ! $row ) {
				return '';
			}
			$member = Storage::find_group_language( $row['group_uuid'], $language );
			$post = $member ? get_post( absint( $member['object_id'] ) ) : null;
			return $post && Frontend::online( $post ) && '' === (string) $post->post_password ? (string) get_permalink( $post ) : '';
		}
		// The archive of a term with a translation in this language: that term's address.
		$term = self::term_at( $relative );
		if ( $term && Terms::translation( $term->term_id, $language ) && Terms::translation( $term->term_id, $language ) !== $term->term_id ) {
			$link = get_term_link( Terms::translation( $term->term_id, $language ), $term->taxonomy );
			if ( is_string( $link ) ) {
				return $link;
			}
		}
		return self::is_list( $relative ) ? Routing::language_url( $absolute, $language ) : '';
	}

	/**
	 * The page of the articles (page_for_posts) when `$relative` is its address, or one of its pages («page/2/»).
	 *
	 * @return array{0: int, 1: string} Its id (0 when it is not) and what follows its address.
	 */
	private static function posts_page_at( $relative ) {
		$id = absint( get_option( 'page_for_posts' ) );
		if ( ! $id || 'page' !== get_option( 'show_on_front' ) ) {
			return array( 0, '' );
		}
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
		$own = trim( (string) substr( $path, strlen( rtrim( $home, '/' ) ) ), '/' );
		$asked = trim( $relative, '/' );
		if ( '' === $own || 0 !== strpos( $asked, $own ) ) {
			return array( 0, '' );
		}
		if ( $asked === $own ) {
			return array( $id, '' );
		}
		return preg_match( '~\A/page/(\d+)\z~', substr( $asked, strlen( $own ) ), $m ) ? array( $id, 'page/' . absint( $m[1] ) . '/' ) : array( 0, '' );
	}

	/** The term whose archive is at `$relative` (base of a taxonomy, then the slug), or null. */
	private static function term_at( $relative ) {
		$parts = array_values( array_filter( explode( '/', trim( $relative, '/' ) ), 'strlen' ) );
		if ( count( $parts ) < 2 ) {
			return null;
		}
		foreach ( Terms::taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$base = 'category' === $taxonomy ? ( get_option( 'category_base' ) ? get_option( 'category_base' ) : 'category' ) : ( 'post_tag' === $taxonomy ? ( get_option( 'tag_base' ) ? get_option( 'tag_base' ) : 'tag' ) : ( $object && ! empty( $object->rewrite['slug'] ) ? $object->rewrite['slug'] : '' ) );
			if ( '' !== $base && trim( (string) $base, '/' ) === $parts[0] ) {
				$term = get_term_by( 'slug', sanitize_title( rawurldecode( (string) end( $parts ) ) ), $taxonomy );
				return $term instanceof \WP_Term ? $term : null;
			}
		}
		return null;
	}

	/** A list page of WordPress: a term, an author, a date, a post type archive, a page of the blog. */
	private static function is_list( $relative ) {
		global $wp_rewrite;
		$first = strtolower( (string) strtok( trim( $relative, '/' ), '/' ) );
		if ( preg_match( '~\A(?:\d{4}|page)\z~', $first ) ) {
			return true;
		}
		$bases = array( $wp_rewrite instanceof \WP_Rewrite ? $wp_rewrite->author_base : 'author', get_option( 'category_base' ) ? get_option( 'category_base' ) : 'category', get_option( 'tag_base' ) ? get_option( 'tag_base' ) : 'tag' );
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( ! empty( $taxonomy->rewrite['slug'] ) ) {
				$bases[] = $taxonomy->rewrite['slug'];
			}
		}
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( ! empty( $type->has_archive ) ) {
				$bases[] = true === $type->has_archive ? ( $type->rewrite['slug'] ?? $type->name ) : $type->has_archive;
			}
		}
		foreach ( $bases as $list_base ) {
			if ( strtolower( (string) strtok( trim( (string) $list_base, '/' ), '/' ) ) === $first ) {
				return true;
			}
		}
		return false;
	}

	/** The addresses found on this request, kept for the next ones (until content changes). */
	public static function save() {
		if ( self::$dirty && '' !== self::$map_key ) {
			set_transient( self::$map_key, array_slice( self::$map, -self::MAX_CACHED, null, true ), 12 * HOUR_IN_SECONDS );
		}
		self::$dirty = false;
	}
}
