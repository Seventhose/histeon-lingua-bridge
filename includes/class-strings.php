<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strings outside contents (docs/STRINGS.md): the site title and tagline, menu labels, and any text a page shows
 * that is not part of a translated content (a footer, a header, a fixed text of the theme or of a plugin).
 *
 * One row per source text and language. A translation applies only when published: what an AI agent writes is
 * a draft until someone approves it. On a page in another language:
 * - `option`: the site title and tagline (option filters);
 * - `menu`: the custom label of a menu item (Menus);
 * - `html`: a text node or a text attribute (alt, title, placeholder, aria-label) whose whole text is a source,
 *   replaced in the page's HTML. Script, style, textarea, code and pre are never touched.
 *
 * Translations are plain text, always escaped where they land: a string can never add markup to a page.
 */
final class Strings {
	const CONTEXTS = array( 'html', 'option', 'menu' );
	const STATES = array( 'missing', 'draft', 'published' );
	const VERSION_OPTION = 'lingua_bridge_strings_version';
	const MAX_LENGTH = 2000;

	/** @var array<string, array<string, string>> Published translations by language, then by key. */
	private static $maps = array();

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lingua_bridge_strings';
	}

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'option_blogname', array( __CLASS__, 'translate_blogname' ) );
		add_filter( 'option_blogdescription', array( __CLASS__, 'translate_blogdescription' ) );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1000 );
	}

	/** Whitespace-insensitive: the same words with other spacing are the same string. */
	public static function normalize( $text ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	public static function key( $context, $source ) {
		return sha1( $context . "\0" . self::normalize( $source ) );
	}

	/** A translation as it may be stored: plain text, no angle brackets, bounded. */
	public static function clean( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = str_replace( array( '<', '>' ), '', $text );
		return mb_substr( trim( $text ), 0, self::MAX_LENGTH );
	}

	/** The language of the page being drawn, '' for the original language or before it is known. */
	public static function language() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		$language = Routing::request_language();
		if ( '' === $language && did_action( 'wp' ) ) {
			$language = Frontend::current_language();
		}
		return Languages::default_code() !== $language && isset( Languages::all()[ $language ] ) ? $language : '';
	}

	/** Published translations of a language, keyed by key(): loaded once per request, cached until a change. */
	public static function map( $language ) {
		if ( isset( self::$maps[ $language ] ) ) {
			return self::$maps[ $language ];
		}
		global $wpdb;
		$cache_key = 'lb_strings_' . md5( $language ) . '_' . absint( get_option( self::VERSION_OPTION, 0 ) );
		$map = get_transient( $cache_key );
		if ( ! is_array( $map ) ) {
			$map = array();
			if ( Storage::is_available() ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_hash, translation FROM ' . self::table() . " WHERE language = %s AND status = 'published' AND translation <> ''", $language ), ARRAY_A );
				foreach ( is_array( $rows ) ? $rows : array() as $row ) {
					$map[ $row['source_hash'] ] = (string) $row['translation'];
				}
			}
			set_transient( $cache_key, $map, DAY_IN_SECONDS );
		}
		self::$maps[ $language ] = $map;
		return $map;
	}

	/** The published translation of a source in the page's language, or null. */
	public static function translate( $context, $source, $language = null ) {
		$language = null === $language ? self::language() : $language;
		if ( '' === $language || '' === self::normalize( $source ) ) {
			return null;
		}
		$map = self::map( $language );
		$key = self::key( $context, $source );
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
	}

	/** As WordPress stores these options (sanitize_option: esc_html), so themes that print them raw stay safe. */
	public static function translate_blogname( $value ) {
		$translated = self::translate( 'option', $value );
		return null === $translated ? $value : esc_html( $translated );
	}

	public static function translate_blogdescription( $value ) {
		$translated = self::translate( 'option', $value );
		return null === $translated ? $value : esc_html( $translated );
	}

	/** The `html` layer: only on pages in another language that has published `html` strings. */
	public static function start_buffer() {
		$language = self::language();
		if ( '' === $language || empty( self::map( $language ) ) || is_feed() || is_robots() ) {
			return;
		}
		ob_start(
			static function ( $html ) use ( $language ) {
				return is_string( $html ) ? self::apply_html( $html, $language ) : $html;
			}
		);
	}

	/**
	 * Replaces whole text nodes and text attributes that are a known source. Blocks of script, style, textarea,
	 * code and pre (and HTML comments) are copied as they are.
	 */
	public static function apply_html( $html, $language ) {
		$map = self::map( $language );
		if ( empty( $map ) || '' === $html ) {
			return $html;
		}
		$lookup = static function ( $text ) use ( $map ) {
			$key = self::key( 'html', $text );
			return isset( $map[ $key ] ) ? $map[ $key ] : null;
		};
		$result = preg_replace_callback(
			'~(<!--.*?-->|<(script|style|textarea|code|pre)\b[^>]*>.*?</\2\s*>)|(?<=>)([^<>]+)(?=<)|(\s(?:alt|title|placeholder|aria-label)=")([^"]*)(")~is',
			static function ( $m ) use ( $lookup ) {
				if ( '' !== $m[1] ) {
					return $m[0];
				}
				if ( isset( $m[3] ) && '' !== $m[3] ) {
					if ( '' === trim( $m[3] ) ) {
						return $m[0];
					}
					$translated = $lookup( $m[3] );
					if ( null === $translated ) {
						return $m[0];
					}
					preg_match( '/^\s*/u', $m[3], $lead );
					preg_match( '/\s*$/u', $m[3], $trail );
					return $lead[0] . esc_html( $translated ) . $trail[0];
				}
				$translated = $lookup( $m[5] );
				return null === $translated ? $m[0] : $m[4] . esc_attr( $translated ) . $m[6];
			},
			$html
		);
		return null === $result ? $html : $result;
	}

	/** A source known for every language but the original (a row without translation, 'missing'). */
	public static function register( $context, $source ) {
		global $wpdb;
		$source = self::normalize( $source );
		if ( ! in_array( $context, self::CONTEXTS, true ) || '' === $source || mb_strlen( $source ) > self::MAX_LENGTH || ! Storage::is_available() ) {
			return;
		}
		$hash = self::key( $context, $source );
		foreach ( array_keys( Languages::all() ) as $language ) {
			if ( Languages::default_code() === $language ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . " (context, source_hash, source, language, translation, status, updated_at, updated_by) VALUES (%s, %s, %s, %s, '', 'missing', %s, 0)", $context, $hash, $source, $language, current_time( 'mysql', true ) ) );
		}
	}

	/**
	 * Saves a translation. 'published' applies at once; 'draft' waits for approval (what an AI writes); an empty
	 * translation is 'missing'. Returns the row, or a WP_Error.
	 */
	public static function save( $id, $translation, $status ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new \WP_Error( 'lingua_string_missing', __( 'Stringa non trovata.', 'lingua-bridge' ) );
		}
		$translation = self::clean( $translation );
		$status = '' === $translation ? 'missing' : ( 'published' === $status ? 'published' : 'draft' );
		$wpdb->update(
			self::table(),
			array(
				'translation' => $translation,
				'status'      => $status,
				'updated_at'  => current_time( 'mysql', true ),
				'updated_by'  => get_current_user_id(),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
		self::changed();
		if ( 'published' === $status || 'published' === $row['status'] ) {
			do_action( 'lingua_bridge_strings_changed' );
		}
		return self::get( $id );
	}

	/** Approves the drafts of a language (or of some rows). */
	public static function publish_drafts( $language, $ids = null ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'published' WHERE status = 'draft' AND translation <> '' AND language = %s", $language );
		if ( is_array( $ids ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			if ( empty( $ids ) ) {
				return 0;
			}
			$sql .= ' AND id IN (' . implode( ',', $ids ) . ')';
		}
		$count = (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above, ids are integers.
		self::changed();
		if ( $count ) {
			do_action( 'lingua_bridge_strings_changed' );
		}
		return $count;
	}

	public static function remove( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( $row ) {
			$wpdb->delete( self::table(), array( 'source_hash' => $row['source_hash'] ), array( '%s' ) );
			self::changed();
		}
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', absint( $id ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows of a language, filtered by status, context and text, oldest first.
	 *
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	public static function query( $language, $args = array() ) {
		global $wpdb;
		$where = array( $wpdb->prepare( 'language = %s', $language ) );
		$states = isset( $args['status'] ) ? array_values( array_intersect( (array) $args['status'], self::STATES ) ) : array();
		if ( $states ) {
			$where[] = "status IN ('" . implode( "','", $states ) . "')";
		}
		if ( ! empty( $args['context'] ) && in_array( $args['context'], self::CONTEXTS, true ) ) {
			$where[] = $wpdb->prepare( 'context = %s', $args['context'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare( '(source LIKE %s OR translation LIKE %s)', $like, $like );
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$table = self::table();
		$sql_where = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- every part prepared above.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $sql_where" );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT id, context, source, translation, status FROM $table WHERE $sql_where ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		// phpcs:enable
		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => $total );
	}

	/** Counts by status for a language. */
	public static function counts( $language ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM ' . self::table() . ' WHERE language = %s GROUP BY status', $language ), ARRAY_A );
		$counts = array_fill_keys( self::STATES, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
		return $counts;
	}

	/** The strings every site has: title, tagline, the custom labels of its menus and navigation blocks. */
	public static function seed() {
		foreach ( array( 'blogname', 'blogdescription' ) as $option ) {
			$value = (string) get_option( $option, '' );
			if ( '' !== trim( $value ) ) {
				self::register( 'option', $value );
			}
		}
		foreach ( get_posts( array( 'post_type' => 'nav_menu_item', 'numberposts' => 500, 'post_status' => 'publish', 'suppress_filters' => true ) ) as $item ) {
			if ( '' !== trim( $item->post_title ) ) {
				self::register( 'menu', $item->post_title );
			}
		}
		foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'numberposts' => 50, 'post_status' => 'publish', 'suppress_filters' => true ) ) as $navigation ) {
			foreach ( self::navigation_labels( parse_blocks( $navigation->post_content ) ) as $label ) {
				self::register( 'menu', $label );
			}
		}
	}

	private static function navigation_labels( $blocks ) {
		$labels = array();
		foreach ( $blocks as $block ) {
			if ( in_array( $block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true ) && ! empty( $block['attrs']['label'] ) ) {
				$labels[] = wp_strip_all_tags( (string) $block['attrs']['label'] );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$labels = array_merge( $labels, self::navigation_labels( $block['innerBlocks'] ) );
			}
		}
		return $labels;
	}

	/** A string changed: new caches, and Static Bridge's copy depends on every page. */
	private static function changed() {
		update_option( self::VERSION_OPTION, absint( get_option( self::VERSION_OPTION, 0 ) ) + 1, true );
		self::$maps = array();
		Storage::content_changed();
	}
}
