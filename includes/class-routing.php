<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional language-prefixed URLs for independent translated WordPress content.
 *
 * The feature is opt-in because static frontends and existing URL inventories
 * need an explicit migration plan before their public paths can change.
 */
final class Routing {
	const LANGUAGE_QUERY_VAR = 'lingua_bridge_route_language';
	const HOME_QUERY_VAR     = 'lingua_bridge_home_language';

	private static $request_language = '';
	/** /xx/ asked while the static front page has no published translation in that language. */
	private static $home_fallback = false;
	private static $route_home_page_id = 0;
	private static $route_source_front_page_id = 0;

	public static function init() {
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'request', array( __CLASS__, 'resolve_home_request' ) );
		add_filter( 'request', array( __CLASS__, 'resolve_page_slug' ), 20 );
		add_filter( 'wp_unique_post_slug', array( __CLASS__, 'shared_slug' ), 20, 6 );
		add_action( 'post_updated', array( __CLASS__, 'remember_old_slug' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_old_slug' ), 3 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_translated_base' ), 2 );
		add_filter( 'pre_option_page_on_front', array( __CLASS__, 'filter_front_page_option' ) );
		add_filter( 'post_link', array( __CLASS__, 'post_link' ), 20, 3 );
		add_filter( 'page_link', array( __CLASS__, 'page_link' ), 20, 3 );
		add_filter( 'post_type_link', array( __CLASS__, 'post_type_link' ), 20, 4 );
		add_filter( 'posts_clauses', array( __CLASS__, 'filter_language_queries' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy_translation_url' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_untranslated_home' ), 1 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'keep_language_prefix' ), 20, 2 );
		// N10: a language on its own domain (example.fr) instead of its prefix.
		add_action( 'template_redirect', array( __CLASS__, 'redirect_to_language_domain' ), 0 );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allowed_hosts' ) );
	}

	/** @return array<string, string> N10: language ⇒ its own host (with a port, when it has one), from the settings. */
	public static function language_domains() {
		$domains = array();
		foreach ( (array) ( Languages::settings()['language_domains'] ?? array() ) as $language => $host ) {
			if ( '' !== self::valid_route_language( $language ) && is_string( $host ) && '' !== $host ) {
				$domains[ $language ] = strtolower( $host );
			}
		}
		return $domains;
	}

	/** The language whose domain this request came to, or ''. */
	private static function domain_language() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		return '' !== $host ? (string) array_search( $host, self::language_domains(), true ) : '';
	}

	/** A URL of the main site moved to a language's domain: the same path, the same query. */
	private static function on_domain( $url, $host ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}
		$scheme = (string) ( $parts['scheme'] ?? wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		return $scheme . '://' . $host . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	/** /fr/… for a language that has its own domain: there, without the prefix, with a 301 (one address per page). */
	public static function redirect_to_language_domain() {
		$domains = self::language_domains();
		// With the prefix, on the main domain or on its own: the address without it, on the domain.
		if ( '' === self::$request_language || ! isset( $domains[ self::$request_language ] ) || ! self::request_has_language_prefix( self::$request_language ) || is_admin() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$prefix = self::prefix_for_language( self::$request_language );
		$relative = substr( $path, strlen( $base ) );
		if ( 0 !== strpos( trim( $relative, '/' ) . '/', $prefix . '/' ) ) {
			return;
		}
		$relative = (string) substr( ltrim( $relative, '/' ), strlen( $prefix ) );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		wp_redirect( self::on_domain( home_url( '/' . ltrim( $relative, '/' ) ), $domains[ self::$request_language ] ) . ( '' !== $query ? '?' . $query : '' ), 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- a configured domain of this site.
		exit;
	}

	public static function allowed_hosts( $hosts ) {
		return array_values( array_unique( array_merge( (array) $hosts, array_map( static fn( $host ) => (string) strtok( $host, ':' ), self::language_domains() ) ) ) );
	}

	/** The prefixed rules are in WordPress's stored rewrite rules (they go when the plugin is deactivated). */
	public static function rules_present() {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) ) {
			return false;
		}
		foreach ( array_keys( self::language_prefixes() ) as $prefix ) {
			if ( ! isset( $rules[ '^' . preg_quote( $prefix, '#' ) . '/?$' ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** A language set by an integration for what it draws now (the Histeon canvas), '' when none. */
	private static $forced_language = '';

	/** Draws lists in `$language` until reset with '' (Histeon canvas: lists in the language of its document). */
	public static function force_language( $language ) {
		$language = sanitize_text_field( (string) $language );
		self::$forced_language = isset( Languages::all()[ $language ] ) ? $language : '';
	}

	/**
	 * C3: whether the list the page shows (the blog, an archive, an author, a date) has published content without a
	 * password in `$language`: the main request (or `$vars`, the query of another list) asked again in that language
	 * for one id. Kept for the request.
	 */
	public static function list_has_content( $language, $vars = null ) {
		static $cache = array();
		global $wp_query;
		$language = sanitize_text_field( (string) $language );
		if ( ! isset( Languages::all()[ $language ] ) || ( ! is_array( $vars ) && ! $wp_query instanceof \WP_Query ) ) {
			return false;
		}
		$vars = is_array( $vars ) ? $vars : ( is_array( $wp_query->query ) ? $wp_query->query : array() );
		unset( $vars[ self::LANGUAGE_QUERY_VAR ], $vars[ self::HOME_QUERY_VAR ], $vars['paged'], $vars['page'] );
		$key = md5( wp_json_encode( $vars ) ) . ':' . $language;
		if ( ! isset( $cache[ $key ] ) ) {
			$previous = self::$forced_language;
			self::$forced_language = $language;
			$query = new \WP_Query(
				array_merge(
					$vars,
					array(
						'posts_per_page'         => 1,
						'paged'                  => 1,
						'fields'                 => 'ids',
						'post_status'            => 'publish',
						'has_password'           => false,
						'ignore_sticky_posts'    => true,
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				)
			);
			self::$forced_language = $previous;
			$cache[ $key ] = ! empty( $query->posts );
		}
		return $cache[ $key ];
	}

	/**
	 * C17: the bases of the addresses translated for `$language` (`category` => `categorie`), from the settings;
	 * only plain segments.
	 *
	 * @return array<string, string>
	 */
	public static function bases( $language ) {
		$all = (array) ( Languages::settings()['language_bases'] ?? array() );
		$out = array();
		foreach ( (array) ( $all[ $language ] ?? array() ) as $base => $translated ) {
			if ( preg_match( '/\A[a-z0-9-]{1,64}\z/', (string) $base ) && preg_match( '/\A[a-z0-9-]{1,64}\z/', (string) $translated ) && $base !== $translated ) {
				$out[ (string) $base ] = (string) $translated;
			}
		}
		return $out;
	}

	/** A path without its prefix (`category/news`) with the first segment translated for `$language`, if it is a base. */
	public static function translate_base( $relative, $language ) {
		$segments = explode( '/', (string) $relative, 2 );
		$bases = self::bases( (string) $language );
		return isset( $bases[ $segments[0] ] ) ? $bases[ $segments[0] ] . ( isset( $segments[1] ) ? '/' . $segments[1] : '' ) : (string) $relative;
	}

	/** The reverse: a path of `$language` without its prefix, with its translated base back to the original's. */
	public static function original_base( $relative, $language ) {
		$segments = explode( '/', (string) $relative, 2 );
		$base = array_search( $segments[0], self::bases( (string) $language ), true );
		return false !== $base ? $base . ( isset( $segments[1] ) ? '/' . $segments[1] : '' ) : (string) $relative;
	}

	/** C17: an address of a language with the original's base, where that language has its own: there (301). */
	public static function redirect_translated_base() {
		if ( ! self::enabled() || '' === self::$request_language || is_admin() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$prefix = self::prefix_for_language( self::$request_language );
		$base_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$relative = trim( substr( $path, strlen( $base_path ) ), '/' );
		if ( '' === $prefix || 0 !== strpos( $relative . '/', $prefix . '/' ) ) {
			return;
		}
		$inner = substr( $relative, strlen( $prefix ) + 1 );
		$moved = self::translate_base( (string) $inner, self::$request_language );
		if ( false !== $inner && '' !== (string) $inner && $moved !== $inner ) {
			$query = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_safe_redirect( home_url( '/' . $prefix . '/' . $moved . ( '/' === substr( $path, -1 ) ? '/' : '' ) ) . ( '' !== $query ? '?' . $query : '' ), 301, 'Lingua Bridge' );
			exit;
		}
	}

	/** Language of the current front-end request from its URL prefix ('' at the root, or when prefixes are off). */
	public static function request_language() {
		return self::$request_language;
	}

	/**
	 * WordPress's canonical redirect knows nothing of prefixes: it would send /en/ (whose front page id is the
	 * English one) to the root, and guess its way from /en/chi-siamo/ to the Italian page. A request under a
	 * language prefix never leaves it: a missing trailing slash is still fixed, a missing translation is a 404.
	 */
	public static function keep_language_prefix( $redirect_url, $requested_url ) {
		if ( ! self::enabled() || '' === self::$request_language || ! is_string( $redirect_url ) || '' === $redirect_url ) {
			return $redirect_url;
		}
		// N10: on a language's domain WordPress would send every page to the main host: it stays on the domain.
		if ( self::$request_language === self::domain_language() ) {
			$moved = self::on_domain( $redirect_url, self::language_domains()[ self::$request_language ] );
			return untrailingslashit( $moved ) === untrailingslashit( self::on_domain( (string) $requested_url, self::language_domains()[ self::$request_language ] ) ) ? false : $moved;
		}
		$prefix = self::prefix_for_language( self::$request_language );
		$path   = (string) parse_url( $redirect_url, PHP_URL_PATH );
		$base   = rtrim( (string) parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$first  = strtolower( (string) strtok( trim( substr( $path, strlen( $base ) ), '/' ), '/' ) );
		return $prefix === $first ? $redirect_url : false;
	}

	/**
	 * A list page of this site (home, archive, term, author) as it is in `$language`: the same path under the
	 * language prefix. Unchanged when prefixes are off or for the default language.
	 */
	public static function language_url( $url, $language ) {
		$language = self::valid_route_language( $language );
		if ( ! self::enabled() || '' === $language || ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$prefix = self::prefix_for_language( $language );
		return isset( self::language_prefixes()[ $prefix ] ) ? self::prepend_prefix( $url, $prefix ) : $url;
	}

	/**
	 * Activation runs after plugins_loaded, when init() has not hooked the rules yet: a bare flush would store
	 * WordPress's rules without the language ones, and every translated page would answer 404.
	 */
	public static function activate() {
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'rewrite_rules' ), 20 );
		flush_rewrite_rules( false );
	}

	/** Remove stored language routes before WordPress flushes rules on deactivation. */
	public static function deactivate() {
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'rewrite_rules_array', array( __CLASS__, 'rewrite_rules' ), 20 );
		}
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
	}

	public static function enabled() {
		$settings = Languages::settings();
		return ! empty( $settings['prefix_language_urls'] ) && '' !== (string) get_option( 'permalink_structure', '' );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::LANGUAGE_QUERY_VAR;
		$vars[] = self::HOME_QUERY_VAR;
		return array_values( array_unique( $vars ) );
	}

	/** Prefix every ordinary WordPress rewrite with each configured non-default language. */
	public static function rewrite_rules( $rules ) {
		if ( ! self::enabled() || ! is_array( $rules ) ) {
			return $rules;
		}

		$prefixed = array();
		foreach ( self::language_prefixes() as $prefix => $language ) {
			$home_query = 'index.php?' . self::HOME_QUERY_VAR . '=' . rawurlencode( $language ) . '&' . self::LANGUAGE_QUERY_VAR . '=' . rawurlencode( $language );
			$prefixed[ '^' . preg_quote( $prefix, '#' ) . '/?$' ] = $home_query;

			foreach ( $rules as $regex => $query ) {
				// WordPress REST endpoints have their own authentication and routing contract.
				if ( false !== strpos( $regex, 'wp-json' ) || false !== strpos( $query, 'rest_route=' ) ) {
					continue;
				}
				$suffix = ltrim( (string) $regex, '^' );
				if ( '' === $suffix ) {
					continue;
				}
				// C17: a base translated for this language (categorie for category) answers first; the original's
				// base under the prefix stays, and redirect_translated_base() moves it to the translated one.
				foreach ( self::bases( $language ) as $base => $translated ) {
					if ( 0 === strpos( $suffix, $base . '/' ) ) {
						$prefixed[ '^' . preg_quote( $prefix, '#' ) . '/' . $translated . substr( $suffix, strlen( $base ) ) ] = self::append_language_query( $query, $language );
					}
				}
				$prefixed[ '^' . preg_quote( $prefix, '#' ) . '/' . $suffix ] = self::append_language_query( $query, $language );
			}
		}

		return $prefixed + $rules;
	}

	/** Convert the /en/ root to the published translation of the configured front page. */
	public static function resolve_home_request( $query_vars ) {
		self::$request_language = '';
		self::$home_fallback = false;
		self::$route_home_page_id = 0;
		self::$route_source_front_page_id = 0;
		if ( ! self::enabled() || ! is_array( $query_vars ) ) {
			return $query_vars;
		}

		$language = self::valid_route_language( isset( $query_vars[ self::LANGUAGE_QUERY_VAR ] ) ? $query_vars[ self::LANGUAGE_QUERY_VAR ] : '' );
		// The language comes from the path (/en/…), never from a query string: ?lingua_bridge_route_language=en on an
		// Italian URL would serve another page there, and a cache keyed without the query would keep it for everyone.
		if ( '' !== $language && ! self::request_has_language_prefix( $language ) ) {
			$language = '';
		}
		// N10: or from the domain the request came to, with the paths of the main site (its root is the home).
		$domain_language = self::domain_language();
		if ( '' !== $domain_language ) {
			$language = $domain_language;
			$query_vars[ self::LANGUAGE_QUERY_VAR ] = $language;
			if ( ! array_diff_key( $query_vars, array( self::LANGUAGE_QUERY_VAR => true, self::HOME_QUERY_VAR => true ) ) ) {
				$query_vars[ self::HOME_QUERY_VAR ] = $language;
			}
		}
		if ( '' === $language ) {
			unset( $query_vars[ self::LANGUAGE_QUERY_VAR ], $query_vars[ self::HOME_QUERY_VAR ] );
			return $query_vars;
		}
		self::$request_language = $language;

		$home_language = self::valid_route_language( isset( $query_vars[ self::HOME_QUERY_VAR ] ) ? $query_vars[ self::HOME_QUERY_VAR ] : '' );
		// /en/?s=… is a search in English, not the English home page.
		if ( isset( $query_vars['s'] ) ) {
			unset( $query_vars[ self::HOME_QUERY_VAR ] );
			return $query_vars;
		}
		if ( '' === $home_language ) {
			return $query_vars;
		}

		// The home is the blog (show_on_front = posts): /en/ is the list of the English articles, as /en/page/2/.
		if ( 'page' !== get_option( 'show_on_front' ) || ! absint( get_option( 'page_on_front', 0 ) ) ) {
			unset( $query_vars[ self::HOME_QUERY_VAR ] );
			return $query_vars;
		}
		self::$route_source_front_page_id = absint( get_option( 'page_on_front', 0 ) );
		$home_id = self::translated_home_id( $home_language );
		if ( ! $home_id ) {
			// A static front page not translated yet: the original's home, not a 404 (switchers and copies lead here).
			self::$home_fallback = true;
			return $query_vars;
		}

		self::$route_home_page_id = $home_id;
		$query_vars['page_id'] = $home_id;
		$query_vars['post_type'] = 'page';
		unset( $query_vars['pagename'], $query_vars['name'], $query_vars[ self::HOME_QUERY_VAR ] );
		return $query_vars;
	}

	/**
	 * The same slug in more languages (N3 of the 1.5.10 verification): WordPress numbers a slug already used by
	 * another content of the type (newsletter-2). A translation may have the slug of a content of another language:
	 * the prefix tells them apart. Only when every content that has it is of another language, and never for the
	 * original's language without prefixes.
	 */
	public static function shared_slug( $slug, $post_id, $post_status, $post_type, $post_parent, $original_slug = '' ) {
		global $wpdb;
		// A template part is found by theme and slug (C16): two of them may never share one, in any language.
		if ( ! self::enabled() || '' === (string) $original_slug || $slug === $original_slug || in_array( $post_status, array( 'draft', 'pending', 'auto-draft' ), true ) || in_array( $post_type, array( 'wp_template_part', 'wp_template' ), true ) ) {
			return $slug;
		}
		$row = Storage::find( absint( $post_id ) );
		$language = $row ? (string) $row['language'] : Languages::default_code();
		$hierarchical = is_post_type_hierarchical( $post_type );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$others = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_name = %s AND p.post_type = %s AND p.ID <> %d" . ( $hierarchical ? ' AND p.post_parent = %d' : '' ) . " AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') LIMIT 20", array_merge( array( $original_slug, $post_type, absint( $post_id ) ), $hierarchical ? array( absint( $post_parent ) ) : array() ) ) );
		if ( empty( $others ) ) {
			return $slug;
		}
		foreach ( $others as $other ) {
			$other_row = Storage::find( absint( $other ) );
			if ( ( $other_row ? (string) $other_row['language'] : Languages::default_code() ) === $language ) {
				return $slug;
			}
		}
		return $original_slug;
	}

	/**
	 * A content asked by its slug (pagename for pages, name for the other types) is the one of that slug in the
	 * language of the request (the original's without a prefix): with the same slug in more languages, WordPress
	 * would take the first it finds, and the old address of a translation would then redirect the original away.
	 */
	public static function resolve_page_slug( $query_vars ) {
		global $wpdb;
		if ( ! self::enabled() || ! is_array( $query_vars ) || ! Storage::is_available() ) {
			return $query_vars;
		}
		$page = ! empty( $query_vars['pagename'] ) && empty( $query_vars['page_id'] );
		$named = ! $page && ! empty( $query_vars['name'] ) && empty( $query_vars['p'] );
		if ( ! $page && ! $named ) {
			return $query_vars;
		}
		$language = '' !== self::$request_language ? self::$request_language : Languages::default_code();
		$path = trim( (string) ( $page ? $query_vars['pagename'] : $query_vars['name'] ), '/' );
		$type = $page ? 'page' : ( isset( $query_vars['post_type'] ) && is_string( $query_vars['post_type'] ) && '' !== $query_vars['post_type'] ? $query_vars['post_type'] : 'post' );
		$leaf = sanitize_title( rawurldecode( (string) basename( $path ) ) );
		$table = Storage::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, l.language FROM {$wpdb->posts} p LEFT JOIN $table l ON l.object_id = p.ID WHERE p.post_name = %s AND p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') LIMIT 20", $leaf, $type ), ARRAY_A );
		if ( ! is_array( $candidates ) || count( $candidates ) < 2 ) {
			return $query_vars; // one content with that slug: WordPress finds it.
		}
		foreach ( $candidates as $candidate ) {
			$candidate_language = null === $candidate['language'] ? Languages::default_code() : (string) $candidate['language'];
			if ( $candidate_language !== $language || ( $page && trim( (string) get_page_uri( absint( $candidate['ID'] ) ), '/' ) !== $path ) ) {
				continue;
			}
			if ( $page ) {
				$query_vars['page_id'] = absint( $candidate['ID'] );
				unset( $query_vars['pagename'] );
			} else {
				$query_vars['p'] = absint( $candidate['ID'] );
				$query_vars['post_type'] = $type;
				unset( $query_vars['name'] );
			}
			break;
		}
		return $query_vars;
	}

	/** Post meta: the slugs a published translated page had before (WordPress keeps old slugs for posts only). */
	const OLD_SLUG_META = '_lingua_bridge_old_slug';

	/** A published translated page whose slug changes (newsletter-2 → newsletter) keeps its old one, for redirect_old_slug(). */
	public static function remember_old_slug( $post_id, $after, $before ) {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post || 'publish' !== $before->post_status || '' === (string) $before->post_name || $before->post_name === $after->post_name || ! is_post_type_hierarchical( $after->post_type ) ) {
			return;
		}
		$row = Storage::find( absint( $post_id ) );
		if ( $row && Languages::default_code() !== $row['language'] && ! in_array( $before->post_name, (array) get_post_meta( $post_id, self::OLD_SLUG_META ), true ) ) {
			add_post_meta( $post_id, self::OLD_SLUG_META, $before->post_name );
		}
		// The slug it has now is no old slug any more.
		delete_post_meta( $post_id, self::OLD_SLUG_META, $after->post_name );
	}

	/** A 404 under a prefix at the old slug of a translated page of that language: the page (301). */
	public static function redirect_old_slug() {
		global $wpdb;
		if ( ! self::enabled() || '' === self::$request_language || ! is_404() || ! Storage::is_available() ) {
			return;
		}
		$slug = sanitize_title( rawurldecode( (string) basename( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ) ) );
		if ( '' === $slug ) {
			return;
		}
		$table = Storage::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m INNER JOIN $table l ON l.object_id = m.post_id WHERE m.meta_key = %s AND m.meta_value = %s AND l.language = %s LIMIT 1", self::OLD_SLUG_META, $slug, self::$request_language ) );
		if ( $id && Frontend::online( $id ) ) {
			wp_safe_redirect( get_permalink( $id ), 301, 'Lingua Bridge' );
			exit;
		}
	}

	/** Let WordPress's template hierarchy treat /en/ as the translated front page. */
	public static function filter_front_page_option( $page_id ) {
		if ( self::enabled() && self::$route_home_page_id ) {
			return self::$route_home_page_id;
		}
		return $page_id;
	}

	/** Original configured homepage, even while core sees its translated front-page ID. */
	public static function source_front_page_id() {
		if ( self::$route_source_front_page_id ) {
			return self::$route_source_front_page_id;
		}
		return absint( get_option( 'page_on_front', 0 ) );
	}

	public static function post_link( $url, $post, $leavename = false ) {
		return self::filter_permalink( $url, $post );
	}

	public static function page_link( $url, $post_id, $sample = false ) {
		return self::filter_permalink( $url, $post_id );
	}

	public static function post_type_link( $url, $post, $leavename = false, $sample = false ) {
		return self::filter_permalink( $url, $post );
	}

	public static function filter_permalink( $url, $post ) {
		if ( ! self::enabled() || ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		$post = get_post( is_object( $post ) && isset( $post->ID ) ? $post->ID : $post );
		if ( ! $post ) {
			return $url;
		}
		// On a translated home WordPress sees the translation as the front page: the original's own front page is
		// still the site's root, never its slug (/casa/), in hreflang, switchers and menus.
		if ( self::$route_home_page_id && absint( $post->ID ) === self::$route_source_front_page_id && 'page' === get_option( 'show_on_front' ) ) {
			return home_url( '/' );
		}
		$row = Storage::find( absint( $post->ID ) );
		if ( ! $row || Languages::default_code() === $row['language'] ) {
			return $url;
		}
		$prefix = self::prefix_for_language( $row['language'] );
		if ( '' === $prefix ) {
			return $url;
		}

		if ( absint( $post->ID ) === self::translated_home_id( $row['language'] ) ) {
			return self::prepend_prefix( home_url( '/' ), $prefix );
		}
		return self::prepend_prefix( $url, $prefix );
	}

	/** Resolve the prefix's rewrite var before the main query is built. */
	public static function filter_language_queries( $clauses, $query ) {
		if ( ! self::enabled() || ! is_array( $clauses ) || ! is_object( $query ) || ! method_exists( $query, 'get' ) || ! self::is_language_content_query( $query ) ) {
			return $clauses;
		}
		// What an integration draws in a given language (the Histeon canvas, a REST request): that language.
		if ( '' !== self::$forced_language ) {
			if ( method_exists( $query, 'is_singular' ) && $query->is_singular() ) {
				return $clauses;
			}
			return Languages::default_code() === self::$forced_language ? self::default_language_sql( $clauses ) : self::language_sql( $clauses, self::$forced_language );
		}
		if ( is_admin() ) {
			return $clauses;
		}
		$language = self::$request_language;
		if ( '' === $language ) {
			$language = self::valid_route_language( $query->get( self::LANGUAGE_QUERY_VAR ) );
		}
		if ( '' === $language ) {
			return self::default_language_clauses( $clauses, $query );
		}

		return self::language_sql( $clauses, $language );
	}

	/** Only content linked to `$language`. */
	private static function language_sql( $clauses, $language ) {
		if ( ! Storage::is_available() ) {
			$clauses['where'] = isset( $clauses['where'] ) ? $clauses['where'] . ' AND 1 = 0 ' : ' AND 1 = 0 ';
			return $clauses;
		}
		global $wpdb;
		$table = Storage::table();
		if ( false === strpos( isset( $clauses['join'] ) ? $clauses['join'] : '', 'lb_language_route' ) ) {
			$clauses['join'] = ( isset( $clauses['join'] ) ? $clauses['join'] : '' ) . " INNER JOIN $table AS lb_language_route ON lb_language_route.object_id = {$wpdb->posts}.ID ";
		}
		$clauses['where'] = ( isset( $clauses['where'] ) ? $clauses['where'] : '' ) . $wpdb->prepare( ' AND lb_language_route.language = %s ', $language );
		return $clauses;
	}

	/**
	 * At the root (the default language) lists show the default language only: archives, search, feeds and the
	 * loops of a page. Content never linked to a language counts as default. Left alone: a single post or page
	 * (an old root URL of a translation must still be found, to be redirected to its prefix), REST requests
	 * (editors list every language), sitemaps and the command line.
	 */
	private static function default_language_clauses( $clauses, $query ) {
		// Only pages people visit: never REST, GraphQL (C33), AJAX, cron (Static Bridge lists its routes there), the CLI or
		// sitemaps.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_graphql_http_request' ) && is_graphql_http_request() ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || '' !== (string) $query->get( 'sitemap' ) || '' !== (string) get_query_var( 'sitemap' ) ) {
			return $clauses;
		}
		if ( method_exists( $query, 'is_singular' ) && $query->is_singular() ) {
			return $clauses;
		}
		return self::default_language_sql( $clauses );
	}

	/** Content of the default language, or linked to none. */
	private static function default_language_sql( $clauses ) {
		if ( ! Storage::is_available() ) {
			return $clauses;
		}
		global $wpdb;
		$table = Storage::table();
		if ( false === strpos( isset( $clauses['join'] ) ? $clauses['join'] : '', 'lb_language_default' ) ) {
			$clauses['join'] = ( isset( $clauses['join'] ) ? $clauses['join'] : '' ) . " LEFT JOIN $table AS lb_language_default ON lb_language_default.object_id = {$wpdb->posts}.ID ";
		}
		$clauses['where'] = ( isset( $clauses['where'] ) ? $clauses['where'] : '' ) . $wpdb->prepare( ' AND ( lb_language_default.language IS NULL OR lb_language_default.language = %s ) ', Languages::default_code() );
		return $clauses;
	}

	/** Keep structural WordPress records (menus, templates, media) outside content-language archives. */
	private static function is_language_content_query( $query ) {
		$post_types = $query->get( 'post_type' );
		if ( empty( $post_types ) ) {
			$post_types = array( 'post' );
		} elseif ( 'any' === $post_types ) {
			// A search looks through every public type: that is content. Other "any" queries are structural.
			return method_exists( $query, 'is_search' ) && $query->is_search();
		} elseif ( ! is_array( $post_types ) ) {
			$post_types = array( $post_types );
		}

		foreach ( $post_types as $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( '' === $post_type || 'attachment' === $post_type ) {
				return false;
			}
			$type_object = get_post_type_object( $post_type );
			if ( ! $type_object || empty( $type_object->public ) ) {
				return false;
			}
		}
		return ! empty( $post_types );
	}

	/** Redirect old root-level English URLs after the matching post has reached WordPress. */
	/** /xx/ while the static front page has no published translation in xx: the original's home, for now (302). */
	public static function redirect_untranslated_home() {
		if ( ! self::$home_fallback || is_admin() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 302, 'Lingua Bridge' );
		exit;
	}

	public static function redirect_legacy_translation_url() {
		if ( ! self::enabled() || is_admin() || ! is_singular() || ( function_exists( 'is_preview' ) && is_preview() ) ) {
			return;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}
		$post_id = Frontend::queried_post_id();
		$row = $post_id ? Storage::find( $post_id ) : null;
		// On its prefix, or on its own domain (N10), a translation is already at its address.
		if ( ! $row || Languages::default_code() === $row['language'] || self::request_has_language_prefix( $row['language'] ) || $row['language'] === self::domain_language() ) {
			return;
		}

		$target = self::filter_permalink( get_permalink( $post_id ), $post_id );
		if ( ! $target ) {
			return;
		}
		wp_safe_redirect( $target, 301, 'Lingua Bridge' );
		exit;
	}

	public static function language_prefixes() {
		$prefixes = array();
		foreach ( Languages::all() as $language => $label ) {
			if ( Languages::default_code() === $language ) {
				continue;
			}
			$prefix = self::prefix_for_language( $language );
			if ( '' !== $prefix && ! self::is_reserved_prefix( $prefix ) ) {
				$prefixes[ $prefix ] = $language;
			}
		}
		return $prefixes;
	}

	private static function prefix_for_language( $language ) {
		$prefix = strtolower( (string) $language );
		$prefix = preg_replace( '/[^a-z0-9-]+/', '-', $prefix );
		return trim( (string) $prefix, '-' );
	}

	private static function is_reserved_prefix( $prefix ) {
		return in_array( $prefix, array( 'api', 'author', 'category', 'comments', 'feed', 'page', 'search', 'tag', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-sitemap' ), true );
	}

	private static function append_language_query( $query, $language ) {
		$query = (string) $query;
		if ( false !== strpos( $query, self::LANGUAGE_QUERY_VAR . '=' ) ) {
			return $query;
		}
		$separator = false === strpos( $query, '?' ) ? '?' : '&';
		return $query . $separator . self::LANGUAGE_QUERY_VAR . '=' . rawurlencode( $language );
	}

	private static function valid_route_language( $language ) {
		$language = sanitize_text_field( (string) $language );
		if ( ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			return '';
		}
		return $language;
	}

	/** The published translation of the front page in `$language`, or 0. */
	public static function translated_home_id( $language ) {
		$front_id = self::source_front_page_id();
		$source = $front_id ? Storage::find( $front_id ) : null;
		if ( ! $source || Languages::default_code() !== $source['language'] ) {
			return 0;
		}
		$translation = Storage::find_group_language( $source['group_uuid'], $language );
		if ( ! $translation ) {
			return 0;
		}
		$post = get_post( absint( $translation['object_id'] ) );
		return $post && 'page' === $post->post_type && 'publish' === $post->post_status ? absint( $post->ID ) : 0;
	}

	private static function prepend_prefix( $url, $prefix ) {
		$url_parts = parse_url( $url );
		$home_parts = parse_url( home_url( '/' ) );
		if ( ! is_array( $url_parts ) || ! is_array( $home_parts ) || empty( $url_parts['host'] ) || empty( $home_parts['host'] ) || strtolower( $url_parts['host'] ) !== strtolower( $home_parts['host'] ) ) {
			return $url;
		}
		if ( isset( $url_parts['port'] ) && isset( $home_parts['port'] ) && (int) $url_parts['port'] !== (int) $home_parts['port'] ) {
			return $url;
		}

		$base_path = isset( $home_parts['path'] ) ? rtrim( $home_parts['path'], '/' ) : '';
		$path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
		if ( '' !== $base_path && 0 !== strpos( $path, $base_path . '/' ) && $path !== $base_path ) {
			return $url;
		}
		$relative = '' === $base_path ? trim( $path, '/' ) : trim( substr( $path, strlen( $base_path ) ), '/' );
		if ( $prefix === $relative || 0 === strpos( $relative, $prefix . '/' ) ) {
			return $url;
		}

		// N10: a language with its own domain has the main site's path there, without the prefix.
		$domains = self::language_domains();
		$language = self::language_prefixes()[ $prefix ] ?? '';
		if ( '' !== $language && isset( $domains[ $language ] ) ) {
			return self::on_domain( $url, $domains[ $language ] );
		}
		$relative = self::translate_base( $relative, $language );
		// The prefixed URL keeps the original's trailing slash (or its absence): it is already canonical.
		$new_path = '/' . $prefix . ( '' !== $relative ? '/' . $relative : '' ) . '/';
		if ( '' !== $relative && ( ! isset( $url_parts['path'] ) || '/' !== substr( $url_parts['path'], -1 ) ) ) {
			$new_path = untrailingslashit( $new_path );
		}
		$new_url = home_url( $new_path );
		if ( isset( $url_parts['query'] ) ) {
			$new_url .= '?' . $url_parts['query'];
		}
		if ( isset( $url_parts['fragment'] ) ) {
			$new_url .= '#' . $url_parts['fragment'];
		}
		return $new_url;
	}

	private static function request_has_language_prefix( $language ) {
		$prefix = self::prefix_for_language( $language );
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$request_path = parse_url( $request_uri, PHP_URL_PATH );
		$home_path = parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( ! is_string( $request_path ) || ! is_string( $home_path ) ) {
			return false;
		}
		$base_path = rtrim( $home_path, '/' );
		if ( '' !== $base_path && 0 === strpos( $request_path, $base_path . '/' ) ) {
			$request_path = substr( $request_path, strlen( $base_path ) );
		}
		$first = strtolower( (string) strtok( trim( $request_path, '/' ), '/' ) );
		return '' !== $prefix && $prefix === $first;
	}
}
