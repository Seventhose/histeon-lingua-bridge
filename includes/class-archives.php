<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The list pages of each language (/en/, /en/category/news/, /en/eventi/) for whoever copies or indexes the site.
 * Single posts already carry their language in their permalink; lists exist once per language only under the
 * prefixes, and nothing else names them.
 *
 * - Static Bridge (`static_bridge_archive_urls_for_post`): when a post changes, the lists in its language are
 *   regenerated too, not only those of the default language.
 * - WordPress sitemaps: a `lingua` sitemap names the lists of each language that has published content, for search
 *   engines and for a static export that reads the sitemaps.
 * - Static Bridge 2.2 no longer reads the sitemaps: the same lists go into its full export through
 *   `static_bridge_export_urls`.
 *
 * Only with language prefixes on: without them a list has one URL for every language.
 *
 * - Static Bridge (`static_bridge_translated_urls`, from its 2.2.9): for each page it queues for an incremental
 *   update, the same content in the other languages, so the translated pages are rewritten too.
 */
final class Archives {
	/** At most this many list URLs in the sitemap, and per changed post. */
	const MAX_URLS = 2000;
	/** At most this many translated routes per route: what Static Bridge takes. */
	const MAX_TRANSLATED_URLS = 32;

	/** @var array<string, int> Content found at a route, per request. */
	private static $content_at = array();

	/** @var array<string, int> N11: the content of each address of the sitemap being printed. */
	private static $sitemap_posts = array();

	/** @var array<string, array<string, string>> C3: the versions of each list of the `lingua` sitemap being printed. */
	private static $sitemap_lists = array();

	public static function init() {
		add_filter( 'static_bridge_archive_urls_for_post', array( __CLASS__, 'for_post' ), 10, 2 );
		add_filter( 'static_bridge_translated_urls', array( __CLASS__, 'translated_urls' ), 10, 2 );
		add_filter( 'static_bridge_export_urls', array( __CLASS__, 'export_urls' ) );
		// C5, C7: what WordPress answers with a redirect, and the hosts of the languages with a domain.
		add_filter( 'static_bridge_redirects', array( __CLASS__, 'redirects' ) );
		add_filter( 'static_bridge_hosts', array( __CLASS__, 'hosts' ) );
		add_action( 'init', array( __CLASS__, 'register_sitemap' ) );
		// N11: the versions of each content in WordPress's sitemaps (xhtml:link), with or without prefixes.
		add_filter( 'wp_sitemaps_posts_entry', array( __CLASS__, 'sitemap_entry' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'sitemap_buffer' ), 0 );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( Storage::class, 'content_changed' ) );
		}
	}

	/** WordPress's sitemap of a content type: remember which content each address is (N11). */
	public static function sitemap_entry( $entry, $post ) {
		if ( $post instanceof \WP_Post && isset( $entry['loc'] ) && Storage::is_available() ) {
			self::$sitemap_posts[ (string) $entry['loc'] ] = absint( $post->ID );
		}
		return $entry;
	}

	/** A sitemap is printed and the request ends: its addresses get their versions in the other languages. */
	public static function sitemap_buffer() {
		$sitemap = (string) get_query_var( 'sitemap' );
		if ( '' === $sitemap || 'index' === $sitemap || '' !== (string) get_query_var( 'sitemap-stylesheet' ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'sitemap_alternates' ) );
	}

	/**
	 * `xhtml:link rel="alternate"` for each address whose content has other published versions, itself and
	 * x-default included, as in the head of the page. WordPress's renderer writes only loc, lastmod, changefreq and
	 * priority: the links go into the printed XML. Rows, groups and posts are read once for the whole sitemap.
	 */
	public static function sitemap_alternates( $xml ) {
		if ( ( ! self::$sitemap_posts && ! self::$sitemap_lists ) || false === strpos( (string) $xml, '<urlset' ) ) {
			return $xml;
		}
		if ( self::$sitemap_lists ) {
			return self::list_alternates( (string) $xml );
		}
		$members = Storage::prime( array_values( self::$sitemap_posts ) );
		if ( ! $members ) {
			return $xml;
		}
		_prime_post_caches( $members, false, false );
		$default = Languages::default_code();
		$xml = (string) preg_replace( '/<urlset\b(?![^>]*xmlns:xhtml)/', '<urlset xmlns:xhtml="http://www.w3.org/1999/xhtml"', (string) $xml, 1 );
		return (string) preg_replace_callback(
			'~<loc>([^<]+)</loc>~',
			static function ( $m ) use ( $default ) {
				$post_id = self::$sitemap_posts[ html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) ] ?? 0;
				$row = $post_id ? Storage::find( $post_id ) : null;
				$links = array();
				$found = array();
				foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
					$post = get_post( absint( $member['object_id'] ) );
					if ( ! Frontend::online( $post ) || ! isset( Languages::all()[ $member['language'] ] ) ) {
						continue;
					}
					$url = (string) get_permalink( $post );
					$links[] = '<xhtml:link rel="alternate" hreflang="' . esc_attr( $member['language'] ) . '" href="' . esc_url( $url ) . '"/>';
					$found[ $member['language'] ] = $url;
				}
				$x_default = Languages::x_default( $found );
				if ( count( $links ) < 2 ) {
					return $m[0];
				}
				if ( '' !== $x_default ) {
					$links[] = '<xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url( $x_default ) . '"/>';
				}
				return $m[0] . implode( '', $links );
			},
			$xml
		);
	}

	/** C3: the `lingua` sitemap, each list with the same list in the other languages and x-default. */
	private static function list_alternates( $xml ) {
		$default = Languages::default_code();
		$xml = (string) preg_replace( '/<urlset\b(?![^>]*xmlns:xhtml)/', '<urlset xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml, 1 );
		return (string) preg_replace_callback(
			'~<loc>([^<]+)</loc>~',
			static function ( $m ) use ( $default ) {
				$versions = self::$sitemap_lists[ html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) ] ?? array();
				$links = '';
				foreach ( $versions as $language => $url ) {
					$links .= '<xhtml:link rel="alternate" hreflang="' . esc_attr( $language ) . '" href="' . esc_url( $url ) . '"/>';
				}
				if ( '' !== Languages::x_default( $versions ) ) {
					$links .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url( Languages::x_default( $versions ) ) . '"/>';
				}
				return $m[0] . $links;
			},
			$xml
		);
	}

	public static function register_sitemap() {
		if ( ! Routing::enabled() || ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( '\WP_Sitemaps_Provider' ) ) {
			return;
		}
		require_once LINGUA_BRIDGE_DIR . 'includes/class-sitemap.php';
		wp_register_sitemap_provider( Sitemap::NAME, new Sitemap() );
	}

	/** The lists that show `$post` in its language, added to those Static Bridge already regenerates. */
	public static function for_post( $added, $post ) {
		$added = is_array( $added ) ? $added : array();
		if ( ! $post instanceof \WP_Post || ! Routing::enabled() ) {
			return $added;
		}
		$row = Storage::find( $post->ID );
		$language = $row ? (string) $row['language'] : '';
		if ( '' === $language || Languages::default_code() === $language ) {
			return $added;
		}

		$urls = array();
		$home_id = Routing::translated_home_id( $language );
		// The language home: its translated front page, or the list of its articles when the home is the blog. A
		// static front page not translated yet has no home in this language (/xx/ leads to the original's).
		if ( $home_id || 'page' !== get_option( 'show_on_front' ) ) {
			$urls[] = Routing::language_url( home_url( '/' ), $language );
		}
		if ( $home_id ) {
			$urls[] = get_permalink( $home_id );
		}
		$posts_page = self::translation_of( absint( get_option( 'page_for_posts' ) ), $language );
		if ( $posts_page ) {
			$urls[] = get_permalink( $posts_page );
		}
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = Routing::language_url( $archive, $language );
		}
		if ( ! empty( $post->post_author ) ) {
			$urls[] = Routing::language_url( get_author_posts_url( (int) $post->post_author ), $language );
		}
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $taxonomy->name );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$link = get_term_link( $term, $taxonomy->name );
				if ( is_string( $link ) ) {
					$urls[] = Routing::language_url( $link, $language );
				}
			}
		}
		// Encoded as a request would send them: an author whose nicename has a space is /author/a%20b/. Not those of a
		// language with its own domain (C7): the copy is of the main site.
		$urls = array_values( array_filter( array_unique( array_map( 'esc_url_raw', array_filter( $urls, 'is_string' ) ) ), array( __CLASS__, 'same_site' ) ) );
		return array_merge( $added, array_slice( $urls, 0, self::MAX_URLS ) );
	}

	/**
	 * The list pages of every non-default language that has published content: post type archives and the terms
	 * of public taxonomies used by that language's posts.
	 *
	 * @return string[]
	 */
	public static function language_lists() {
		if ( ! Routing::enabled() || ! Storage::is_available() ) {
			return array();
		}
		// Public and cheap to ask for: kept until content, a link or a term changes (or half a day passes).
		$cache_key = 'lb_language_lists_' . self::cache_version();
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$urls = self::build_language_lists();
		set_transient( $cache_key, $urls, 12 * HOUR_IN_SECONDS );
		return $urls;
	}

	/**
	 * What the cached lists and redirects depend on: the version of the contents and the settings of the languages and
	 * of the addresses (a domain given to a language changes every address of that language).
	 */
	private static function cache_version() {
		$settings = Languages::settings();
		$addresses = array( $settings['languages'] ?? array(), $settings['default_language'] ?? '', $settings['prefix_language_urls'] ?? false, $settings['language_domains'] ?? array() );
		return absint( get_option( Storage::CONTENT_VERSION_OPTION, 0 ) ) . '_' . substr( md5( (string) wp_json_encode( $addresses ) ), 0, 8 );
	}

	/** @return string[] */
	private static function build_language_lists() {
		$urls = array();
		foreach ( array_keys( Languages::all() ) as $language ) {
			if ( Languages::default_code() === $language ) {
				continue;
			}
			$urls = array_merge( $urls, array_values( self::lists_in( $language ) ) );
			if ( count( $urls ) >= self::MAX_URLS ) {
				break;
			}
		}
		return array_slice( array_values( array_unique( array_map( 'esc_url_raw', $urls ) ) ), 0, self::MAX_URLS );
	}

	/**
	 * The lists of one language that have published content without a password: post type archives and the terms
	 * of public taxonomies its posts use. For the default language, content linked to no language counts too.
	 *
	 * @return array<string, string> The list's address in the default language => its address in `$language`.
	 */
	private static function lists_in( $language ) {
		global $wpdb;
		$taxonomies = array_values( get_taxonomies( array( 'public' => true, 'publicly_queryable' => true ) ) );
		$post_types = array_values( get_post_types( array( 'public' => true ) ) );
		if ( empty( $post_types ) ) {
			return array();
		}
		$table = Storage::table();
		$types_in = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$default = Languages::default_code() === $language;
		$join = $default ? "LEFT JOIN $table l ON l.object_id = p.ID" : "INNER JOIN $table l ON l.object_id = p.ID";
		$where = $default ? '( l.language IS NULL OR l.language = %s )' : 'l.language = %s';
		$urls = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholders only.
		$types = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.post_type FROM {$wpdb->posts} p $join WHERE $where AND p.post_status = 'publish' AND p.post_password = '' AND p.post_type IN ($types_in)", array_merge( array( $language ), $post_types ) ) );
		foreach ( is_array( $types ) ? $types : array() as $type ) {
			$archive = get_post_type_archive_link( $type );
			if ( $archive ) {
				// The blog's archive is the home, which WordPress gives without its slash.
				$archive = untrailingslashit( $archive ) === untrailingslashit( home_url( '/' ) ) ? home_url( '/' ) : $archive;
				$urls[ $archive ] = Routing::language_url( $archive, $language );
			}
		}
		if ( ! empty( $taxonomies ) ) {
			$tax_in = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholders only.
			$terms = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT tt.term_id, tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id $join WHERE $where AND p.post_status = 'publish' AND p.post_password = '' AND tt.taxonomy IN ($tax_in) ORDER BY tt.term_id LIMIT %d", array_merge( array( $language ), $taxonomies, array( self::MAX_URLS ) ) ), ARRAY_A );
			$ids = array_map( 'absint', array_column( is_array( $terms ) ? $terms : array(), 'term_id' ) );
			// One query for all the terms, then links from the loaded objects.
			$objects = empty( $ids ) ? array() : get_terms( array( 'include' => $ids, 'hide_empty' => false, 'taxonomy' => $taxonomies ) );
			foreach ( is_array( $objects ) ? $objects : array() as $term ) {
				$link = get_term_link( $term );
				// A translated term (1.6.0) stands for its original: the same list in another language.
				$row = Terms::find( $term->term_id );
				$source = $row && absint( $row['source_id'] ) !== absint( $term->term_id ) ? get_term_link( absint( $row['source_id'] ), $term->taxonomy ) : $link;
				if ( is_string( $link ) && is_string( $source ) ) {
					$urls[ $source ] = Routing::language_url( $link, $language );
				}
			}
		}
		return $urls;
	}

	/**
	 * C3: the versions of each list of the `lingua` sitemap, for its xhtml:link: address => (language => address),
	 * only the languages whose list has content. Cached as language_lists().
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function list_versions() {
		if ( ! Routing::enabled() || ! Storage::is_available() ) {
			return array();
		}
		$cache_key = 'lb_list_versions_' . self::cache_version();
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$by_list = array();
		foreach ( array_keys( Languages::all() ) as $language ) {
			foreach ( self::lists_in( $language ) as $base => $url ) {
				$by_list[ $base ][ $language ] = esc_url_raw( $url );
			}
		}
		$versions = array();
		foreach ( $by_list as $languages ) {
			if ( count( $languages ) < 2 ) {
				continue;
			}
			foreach ( $languages as $language => $url ) {
				if ( Languages::default_code() !== $language ) {
					$versions[ $url ] = $languages;
				}
			}
		}
		set_transient( $cache_key, $versions, 12 * HOUR_IN_SECONDS );
		return $versions;
	}

	/** C3: the `lingua` sitemap names this list: its versions go into the printed XML, as for contents. */
	public static function remember_list( $url ) {
		$versions = self::list_versions();
		if ( isset( $versions[ $url ] ) ) {
			self::$sitemap_lists[ $url ] = $versions[ $url ];
		}
	}

	/**
	 * The lists of each language, added to the routes of Static Bridge's full export. Those of a language with its own
	 * domain (C7) are not: Static Bridge copies the main site only, and leaves the domains of the languages to WordPress.
	 */
	public static function export_urls( $urls ) {
		return array_merge( is_array( $urls ) ? $urls : array(), array_values( array_filter( self::language_lists(), array( __CLASS__, 'same_site' ) ) ) );
	}

	/** At most this many redirects for Static Bridge. */
	const MAX_REDIRECTS = 500;

	/**
	 * C5: the redirects WordPress makes for Lingua Bridge, as pairs Static Bridge can write in the server's rules, so
	 * that they hold also where a static copy answers without WordPress (Strict mode): from => ['to' => address,
	 * 'status' => code]. The old slugs of translated pages and posts (301), the original term under a language's
	 * prefix and a translated term at the root (301), a language's home while the static front page has no
	 * translation in it (302). Only addresses of this site, at most MAX_REDIRECTS; cached until content changes.
	 *
	 * @param mixed $redirects What other plugins already give.
	 * @return mixed
	 */
	public static function redirects( $redirects ) {
		if ( ! is_array( $redirects ) || ! Routing::enabled() || ! Storage::is_available() ) {
			return $redirects;
		}
		$cache_key = 'lb_redirects_' . self::cache_version();
		$own = get_transient( $cache_key );
		if ( ! is_array( $own ) ) {
			$own = self::build_redirects();
			set_transient( $cache_key, $own, 12 * HOUR_IN_SECONDS );
		}
		foreach ( $own as $from => $to ) {
			if ( ! isset( $redirects[ $from ] ) ) {
				$redirects[ $from ] = $to;
			}
		}
		return $redirects;
	}

	/** @return array<string, array{to: string, status: int}> */
	private static function build_redirects() {
		global $wpdb;
		$out = array();
		$add = static function ( $from, $to, $status ) use ( &$out ) {
			$from = is_string( $from ) && self::same_site( $from ) ? (string) wp_parse_url( $from, PHP_URL_PATH ) : '';
			if ( '' === $from || ! is_string( $to ) || '' === $to || ! self::same_site( $to ) || isset( $out[ $from ] ) || count( $out ) >= self::MAX_REDIRECTS ) {
				return;
			}
			if ( trailingslashit( $from ) !== trailingslashit( (string) wp_parse_url( $to, PHP_URL_PATH ) ) ) {
				$out[ $from ] = array( 'to' => esc_url_raw( $to ), 'status' => (int) $status );
			}
		};
		// The old slugs of the translations (pages: Lingua Bridge's meta; posts: WordPress's own).
		$table = Storage::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m INNER JOIN $table l ON l.object_id = m.post_id WHERE m.meta_key IN (%s, %s) AND l.language <> %s ORDER BY m.meta_id DESC LIMIT %d", Routing::OLD_SLUG_META, '_wp_old_slug', Languages::default_code(), self::MAX_REDIRECTS ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = absint( $row['post_id'] );
			$link = self::is_public( $id ) ? (string) get_permalink( $id ) : '';
			$slug = sanitize_title( (string) $row['meta_value'] );
			$post = get_post( $id );
			if ( '' === $link || '' === $slug || ! $post || $slug === $post->post_name ) {
				continue;
			}
			// The same address with the old slug in place of the new one, as the visitor had it.
			$old = (string) preg_replace( '~/' . preg_quote( rawurlencode( urldecode( $post->post_name ) ), '~' ) . '/?$~', '/' . rawurlencode( $slug ) . '/', $link );
			if ( $old !== $link ) {
				$add( $old, $link, 301 );
			}
		}
		// The terms: the original under a language's prefix, and a translated term at the root, lead to the term of
		// that language (Terms::redirect()).
		if ( class_exists( __NAMESPACE__ . '\\Terms' ) && Terms::available() ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$terms = $wpdb->get_results( $wpdb->prepare( 'SELECT term_id, source_id, language, taxonomy FROM ' . Terms::table() . ' WHERE term_id <> source_id ORDER BY term_id LIMIT %d', self::MAX_REDIRECTS ), ARRAY_A );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$translated = get_term_link( absint( $term['term_id'] ), (string) $term['taxonomy'] );
				$original = get_term_link( absint( $term['source_id'] ), (string) $term['taxonomy'] );
				if ( ! is_string( $translated ) || ! is_string( $original ) || ! isset( Languages::all()[ $term['language'] ] ) ) {
					continue;
				}
				$add( Routing::language_url( $original, (string) $term['language'] ), $translated, 301 );
				$add( home_url( (string) preg_replace( '~^' . preg_quote( (string) wp_parse_url( Routing::language_url( home_url( '/' ), (string) $term['language'] ), PHP_URL_PATH ), '~' ) . '~', '/', (string) wp_parse_url( $translated, PHP_URL_PATH ) ) ), $translated, 301 );
			}
		}
		// A language's home while the static front page has no translation in it: the original's home, for now.
		if ( 'page' === get_option( 'show_on_front' ) && absint( get_option( 'page_on_front' ) ) ) {
			foreach ( array_keys( Languages::all() ) as $code ) {
				if ( Languages::default_code() !== $code && ! Routing::translated_home_id( $code ) ) {
					$add( Routing::language_url( home_url( '/' ), $code ), home_url( '/' ), 302 );
				}
			}
		}
		return $out;
	}

	/**
	 * C7: the hosts of the languages that have their own domain (language => host). Static Bridge serves its copy of
	 * the main site on the main host only: on these hosts WordPress answers, in the right language.
	 *
	 * @param mixed $hosts
	 * @return mixed
	 */
	public static function hosts( $hosts ) {
		if ( ! is_array( $hosts ) || ! Routing::enabled() ) {
			return $hosts;
		}
		foreach ( Routing::language_domains() as $code => $host ) {
			if ( ! isset( $hosts[ $code ] ) ) {
				$hosts[ $code ] = (string) $host;
			}
		}
		return $hosts;
	}

	/**
	 * The addresses of the published translations of the content at `$url`, added to `$urls` (doc 23 of Histos
	 * Publish, item 181). When a page changes, Static Bridge rewrites its static copy and, with these, the copies of
	 * its translations, whose switcher and hreflang name it. Only when `$url` is the permalink of public content
	 * (published, without a password) and only public translations: a list page, a withdrawn or a deleted post get
	 * nothing, so a removal never takes a translation that is still published with it. Same site only, at most
	 * MAX_TRANSLATED_URLS in all.
	 *
	 * @param mixed  $urls What Static Bridge (TranslatePress) and other plugins already give.
	 * @param string $url  A route of this site that Static Bridge queued.
	 * @return mixed
	 */
	public static function translated_urls( $urls, $url ) {
		if ( ! is_array( $urls ) || ! is_string( $url ) || '' === $url || ! Storage::is_available() ) {
			return $urls;
		}
		$room = self::MAX_TRANSLATED_URLS - count( $urls );
		$post_id = $room > 0 ? self::content_at( $url ) : 0;
		$row = $post_id ? Storage::find( $post_id ) : null;
		if ( ! $row ) {
			return $urls;
		}
		foreach ( Storage::group_rows( $row['group_uuid'] ) as $member ) {
			$id = absint( $member['object_id'] );
			$link = $id && $id !== $post_id && self::is_public( $id ) ? get_permalink( $id ) : '';
			if ( is_string( $link ) && '' !== $link && self::same_site( $link ) && ! in_array( $link, $urls, true ) ) {
				$urls[] = $link;
				if ( --$room < 1 ) {
					break;
				}
			}
		}
		return $urls;
	}

	/** The public content whose permalink is `$url` (the translated front page at /en/ too), or 0. */
	private static function content_at( $url ) {
		// What url_to_postid() found is kept for the request; whether it is public is asked each time (a bulk edit
		// changes several posts in one request).
		if ( ! isset( self::$content_at[ $url ] ) ) {
			if ( count( self::$content_at ) > 500 ) {
				self::$content_at = array();
			}
			self::$content_at[ $url ] = self::resolve( $url );
		}
		$post_id = self::$content_at[ $url ];
		return $post_id && self::is_public( $post_id ) && self::same_route( get_permalink( $post_id ), $url ) ? $post_id : 0;
	}

	private static function resolve( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		// Static Bridge also queues every page of each list: /page/2/ is never the permalink of content.
		if ( ! self::same_site( $url ) || null !== wp_parse_url( $url, PHP_URL_QUERY ) || preg_match( '~/page/[0-9]+/?$~', $path ) ) {
			return 0;
		}
		$post_id = absint( url_to_postid( $url ) );
		if ( ! $post_id && Routing::enabled() ) {
			// url_to_postid() does not resolve the language root, where the translated front page answers.
			foreach ( array_keys( Languages::all() ) as $language ) {
				$root = Routing::language_url( home_url( '/' ), $language );
				if ( $root !== home_url( '/' ) && self::same_route( $root, $url ) ) {
					return Routing::translated_home_id( $language );
				}
			}
		}
		return $post_id;
	}

	private static function is_public( $post_id ) {
		$post = get_post( $post_id );
		return $post && Frontend::online( $post ) && '' === (string) $post->post_password;
	}

	private static function same_site( $url ) {
		$parts = wp_parse_url( $url );
		$home = wp_parse_url( home_url( '/' ) );
		return is_array( $parts ) && is_array( $home ) && ! empty( $parts['host'] ) && ! empty( $home['host'] )
			&& strtolower( $parts['host'] ) === strtolower( $home['host'] )
			&& ( isset( $parts['port'] ) ? (int) $parts['port'] : 0 ) === ( isset( $home['port'] ) ? (int) $home['port'] : 0 );
	}

	private static function same_route( $left, $right ) {
		if ( ! is_string( $left ) || '' === $left || ! self::same_site( $left ) ) {
			return false;
		}
		return trim( (string) wp_parse_url( $left, PHP_URL_PATH ), '/' ) === trim( (string) wp_parse_url( $right, PHP_URL_PATH ), '/' );
	}

	private static function translation_of( $post_id, $language ) {
		$row = $post_id ? Storage::find( $post_id ) : null;
		$variant = $row ? Storage::find_group_language( $row['group_uuid'], $language ) : null;
		$post = $variant ? get_post( absint( $variant['object_id'] ) ) : null;
		return $post && 'publish' === $post->post_status ? absint( $post->ID ) : 0;
	}
}
