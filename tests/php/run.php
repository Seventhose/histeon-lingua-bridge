<?php
/**
 * Lightweight regression tests for Lingua Bridge's WordPress-facing domain logic.
 * Run with: php tests/php/run.php
 *
 * These stubs exercise plugin decisions without claiming a full WordPress integration test.
 */
define( 'ABSPATH', __DIR__ . '/wordpress-stub/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

class FakeWpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $rows = array();
	public $fail_insert_number = 0;
	public $fail_object_id = 0;
	public $valid_schema = true;
	private $insert_count = 0;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}
		$index = 0;
		return preg_replace_callback(
			'/(?<!%)%[ds]/',
			function ( $match ) use ( $args, &$index ) {
				$value = isset( $args[ $index ] ) ? $args[ $index ] : '';
				++$index;
				return '%d' === $match[0] ? (string) absint( $value ) : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function get_charset_collate() { return ''; }
	public function get_var( $query ) { return false !== strpos( $query, 'SHOW TABLES LIKE' ) ? $this->prefix . 'lingua_bridge_translations' : null; }

	public function get_row( $query, $output = null ) {
		if ( preg_match( '/WHERE object_id = (\d+)/', $query, $match ) ) {
			return isset( $this->rows[ (int) $match[1] ] ) ? $this->rows[ (int) $match[1] ] : null;
		}
		if ( preg_match( "/WHERE group_uuid = '([^']*)' AND language = '([^']*)'/", $query, $match ) ) {
			foreach ( $this->rows as $row ) {
				if ( $row['group_uuid'] === stripslashes( $match[1] ) && $row['language'] === stripslashes( $match[2] ) ) {
					return $row;
				}
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		if ( false !== strpos( $query, 'SHOW INDEX' ) ) {
			return $this->valid_schema ? array(
				array( 'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'object_id' ),
				array( 'Key_name' => 'group_language', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'group_uuid' ),
				array( 'Key_name' => 'group_language', 'Non_unique' => 0, 'Seq_in_index' => 2, 'Column_name' => 'language' ),
				array( 'Key_name' => 'source_id', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'source_id' ),
				array( 'Key_name' => 'language_review', 'Non_unique' => 1, 'Seq_in_index' => 1, 'Column_name' => 'language' ),
				array( 'Key_name' => 'language_review', 'Non_unique' => 1, 'Seq_in_index' => 2, 'Column_name' => 'review_state' ),
			) : array();
		}
		if ( preg_match( "/WHERE group_uuid = '([^']*)'/", $query, $match ) ) {
			return array_values(
				array_filter(
					$this->rows,
					static function ( $row ) use ( $match ) { return $row['group_uuid'] === stripslashes( $match[1] ); }
				)
			);
		}
		return array();
	}

	public function get_col( $query, $column = 0 ) {
		if ( false !== strpos( $query, 'SHOW COLUMNS' ) ) {
			return $this->valid_schema ? array( 'object_id', 'group_uuid', 'language', 'source_id', 'source_hash', 'review_state', 'created_at', 'updated_at' ) : array( 'object_id' );
		}
		if ( false !== strpos( $query, 'SELECT DISTINCT language' ) ) {
			return array_values( array_unique( array_column( $this->rows, 'language' ) ) );
		}
		return array();
	}

	public function insert( $table, $data, $formats = null ) {
		++$this->insert_count;
		if ( $this->fail_insert_number === $this->insert_count || $this->fail_object_id === (int) $data['object_id'] || isset( $this->rows[ $data['object_id'] ] ) ) {
			return false;
		}
		foreach ( $this->rows as $row ) {
			if ( $row['group_uuid'] === $data['group_uuid'] && $row['language'] === $data['language'] ) {
				return false;
			}
		}
		$this->rows[ $data['object_id'] ] = $data;
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$changed = 0;
		foreach ( $this->rows as $id => $row ) {
			$matches = isset( $where['group_uuid'] ) ? $row['group_uuid'] === $where['group_uuid'] : ( isset( $where['object_id'] ) && (int) $id === (int) $where['object_id'] );
			if ( $matches ) {
				$this->rows[ $id ] = array_merge( $row, $data );
				++$changed;
			}
		}
		return $changed;
	}

	public function delete( $table, $where, $formats = null ) {
		$id = isset( $where['object_id'] ) ? absint( $where['object_id'] ) : 0;
		if ( ! isset( $this->rows[ $id ] ) ) {
			return 0;
		}
		unset( $this->rows[ $id ] );
		return 1;
	}

	public function query( $query ) {
		if ( preg_match( "/WHERE group_uuid = '([^']*)' AND object_id IN \(([^)]*)\)/", $query, $match ) ) {
			$group = stripslashes( $match[1] );
			$ids = array_map( 'intval', explode( ',', $match[2] ) );
			foreach ( $ids as $id ) {
				if ( isset( $this->rows[ $id ] ) && $this->rows[ $id ]['group_uuid'] === $group ) {
					unset( $this->rows[ $id ] );
				}
			}
		}
		return 1;
	}
}

function dbDelta( $sql ) { return array(); }

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['lb_options'] = array();
$GLOBALS['lb_cache'] = array();
$GLOBALS['lb_posts'] = array();
$GLOBALS['lb_meta'] = array();
$GLOBALS['lb_inserted_posts'] = 0;
$GLOBALS['lb_hooks'] = array();
$GLOBALS['lb_hook_events'] = array();
$GLOBALS['lb_rewrite_flushes'] = 0;

function absint( $value ) { return abs( (int) $value ); }
function __( $text, $domain = null ) { return $text; }
function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, is_array( $args ) ? $args : array() ); }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['lb_hooks'][ $hook ][ $priority ][] = $callback; }
function remove_filter( $hook, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['lb_hooks'][ $hook ][ $priority ] ) ) { return false; }
	$GLOBALS['lb_hooks'][ $hook ][ $priority ] = array_values( array_filter( $GLOBALS['lb_hooks'][ $hook ][ $priority ], static function ( $registered ) use ( $callback ) { return $registered !== $callback; } ) );
	return true;
}
function flush_rewrite_rules( $hard = true ) { ++$GLOBALS['lb_rewrite_flushes']; $GLOBALS['lb_hook_events'][] = 'flush'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function is_admin() { return false; }
function get_bloginfo( $show = '' ) { return 'description' === $show ? 'Un sito di prova con residenze universitarie' : 'Sito di prova'; }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['lb_options'] ) ? $GLOBALS['lb_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['lb_options'][ $key ] = $value; return true; }
function wp_cache_get( $key, $group = '' ) { return isset( $GLOBALS['lb_cache'][ $group ][ $key ] ) ? $GLOBALS['lb_cache'][ $group ][ $key ] : false; }
function wp_cache_set( $key, $value, $group = '', $expiration = 0 ) { $GLOBALS['lb_cache'][ $group ][ $key ] = $value; return true; }
function wp_cache_delete( $key, $group = '' ) { unset( $GLOBALS['lb_cache'][ $group ][ $key ] ); return true; }
function current_time( $type, $gmt = false ) { return '2026-10-03 12:00:00'; }
function wp_generate_uuid4() { static $i = 0; ++$i; return sprintf( '00000000-0000-4000-8000-%012d', $i ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_post( $post ) { if ( is_object( $post ) ) { return $post; } $id = absint( $post ); return isset( $GLOBALS['lb_posts'][ $id ] ) ? $GLOBALS['lb_posts'][ $id ] : null; }
function get_permalink( $post ) { $post = get_post( $post ); return $post ? home_url( '/' . trim( $post->post_name, '/' ) . '/' ) : ''; }
function get_posts( $args = array() ) {
	$matches = array();
	foreach ( $GLOBALS['lb_posts'] as $id => $post ) {
		if ( isset( $args['post_type'] ) && $args['post_type'] !== $post->post_type ) { continue; }
		if ( isset( $args['post_status'] ) && 'any' !== $args['post_status'] ) {
			$statuses = is_array( $args['post_status'] ) ? $args['post_status'] : array( $args['post_status'] );
			if ( ! in_array( $post->post_status, $statuses, true ) ) { continue; }
		}
		$matches_meta = true;
		foreach ( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array() as $condition ) {
			if ( ! is_array( $condition ) || ! isset( $condition['key'], $condition['value'] ) ) { continue; }
			if ( (string) get_post_meta( $id, $condition['key'], true ) !== (string) $condition['value'] ) {
				$matches_meta = false;
				break;
			}
		}
		if ( $matches_meta ) { $matches[] = 'ids' === ( isset( $args['fields'] ) ? $args['fields'] : '' ) ? absint( $id ) : $post; }
	}
	if ( isset( $args['posts_per_page'] ) && (int) $args['posts_per_page'] > -1 ) {
		$matches = array_slice( $matches, 0, (int) $args['posts_per_page'] );
	}
	return $matches;
}
function get_post_type_object( $type ) { return in_array( $type, array( 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block', 'attachment', 'revision' ), true ) ? (object) array( 'public' => false, 'show_ui' => true, 'cap' => (object) array( 'create_posts' => 'edit_posts', 'edit_posts' => 'edit_posts' ) ) : (object) array( 'public' => true, 'show_ui' => true, 'cap' => (object) array( 'create_posts' => 'edit_posts', 'edit_posts' => 'edit_posts' ) ); }
function post_type_supports( $type, $feature ) { return 'editor' === $feature; }
function get_post_types( $args = array(), $output = 'names' ) {
	$types = array();
	foreach ( array( 'post', 'page', 'wp_block', 'lb_private_submission' ) as $name ) {
		$types[ $name ] = 'lb_private_submission' === $name ? (object) array( 'public' => false, 'publicly_queryable' => false, 'show_ui' => true ) : get_post_type_object( $name );
	}
	return 'objects' === $output ? $types : array_keys( $types );
}
function current_user_can( $capability, $object_id = null ) {
	// Translators::map_meta_cap() is not hooked here: translating an original is editing it, as for an editor.
	$capability = 'lingua_bridge_translate_post' === $capability ? 'edit_post' : $capability;
	return ! in_array( $capability, isset( $GLOBALS['lb_denied_caps'] ) ? $GLOBALS['lb_denied_caps'] : array(), true );
}
function get_current_user_id() { return 1; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_slash( $value ) { return $value; }
function get_post_thumbnail_id( $post_id ) { return 0; }
function set_post_thumbnail( $post_id, $thumbnail_id ) { update_post_meta( $post_id, '_thumbnail_id', absint( $thumbnail_id ) ); return true; }
function get_object_taxonomies( $post_type, $output = 'names' ) { return array(); }
function taxonomy_exists( $taxonomy ) { return true; }
function wp_get_object_terms( $post_id, $taxonomy, $args = array() ) { return array(); }
function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) { return array(); }
function get_post_type( $post_id ) { $post = get_post( $post_id ); return $post ? $post->post_type : false; }
function metadata_exists( $type, $post_id, $key ) { return isset( $GLOBALS['lb_meta'][ absint( $post_id ) ][ $key ] ); }
function get_post_meta( $post_id, $key = '', $single = false ) {
	$meta = isset( $GLOBALS['lb_meta'][ absint( $post_id ) ] ) ? $GLOBALS['lb_meta'][ absint( $post_id ) ] : array();
	if ( '' === $key ) { return array_map( static function ( $value ) { return array( $value ); }, $meta ); }
	$value = isset( $meta[ $key ] ) ? $meta[ $key ] : '';
	return $single ? $value : array( $value );
}
function update_post_meta( $post_id, $key, $value ) { $GLOBALS['lb_meta'][ absint( $post_id ) ][ $key ] = $value; return true; }
function delete_post_meta( $post_id, $key ) { unset( $GLOBALS['lb_meta'][ absint( $post_id ) ][ $key ] ); return true; }
function add_post_meta( $post_id, $key, $value ) { $GLOBALS['lb_meta'][ absint( $post_id ) ][ $key ] = $value; return true; }
function maybe_unserialize( $value ) { return $value; }
function wp_kses_post( $value ) { return $value; }
function wp_strip_all_tags( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/i', '-', strtolower( $value ) ), '-' ); }
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook, ...$args ) {}
function wp_insert_post( $data, $wp_error = false ) {
	$id = 100 + ++$GLOBALS['lb_inserted_posts'];
	$defaults = array( 'ID' => $id, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '', 'post_excerpt' => '', 'post_name' => '', 'post_parent' => 0, 'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed' );
	$GLOBALS['lb_posts'][ $id ] = (object) array_merge( $defaults, $data, array( 'ID' => $id ) );
	return $id;
}
function wp_update_post( $data, $wp_error = false ) {
	$id = absint( $data['ID'] );
	if ( ! isset( $GLOBALS['lb_posts'][ $id ] ) ) { return new WP_Error( 'missing_post', 'Post missing.' ); }
	foreach ( $data as $key => $value ) { $GLOBALS['lb_posts'][ $id ]->$key = $value; }
	return $id;
}
function wp_delete_post( $post_id, $force_delete = false ) { unset( $GLOBALS['lb_posts'][ absint( $post_id ) ] ); return true; }

require_once dirname( __DIR__, 2 ) . '/includes/class-languages.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-storage.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-strings.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-xliff.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-memory.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-updates.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-fields.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-translations.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-importer.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-abilities.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-routing.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-terms.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-frontend.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-translators.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-sync.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-acf.php';

class FakeWpQuery {
	private $vars;
	public function __construct( $vars = array() ) { $this->vars = $vars; }
	public function get( $key ) { return isset( $this->vars[ $key ] ) ? $this->vars[ $key ] : ''; }
}

function assert_true( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

function make_post( $id, $title, $status = 'publish', $content = 'Source block content' ) {
	$GLOBALS['lb_posts'][ $id ] = (object) array(
		'ID' => $id,
		'post_type' => 'page',
		'post_status' => $status,
		'post_title' => $title,
		'post_content' => $content,
		'post_excerpt' => '',
		'post_password' => '',
		'post_name' => sanitize_title( $title ),
		'post_parent' => 0,
		'menu_order' => 0,
		'comment_status' => 'closed',
		'ping_status' => 'closed',
	);
}

function reset_fixture() {
	$GLOBALS['wpdb']->rows = array();
	$GLOBALS['wpdb']->fail_insert_number = 0;
	$GLOBALS['wpdb']->fail_object_id = 0;
	$GLOBALS['wpdb']->valid_schema = true;
	$availability = new ReflectionProperty( '\\LinguaBridge\\Storage', 'available' );
	$availability->setAccessible( true );
	$availability->setValue( null, null );
	$GLOBALS['lb_cache'] = array();
	$GLOBALS['lb_posts'] = array();
	$GLOBALS['lb_meta'] = array();
	$GLOBALS['lb_inserted_posts'] = 0;
	$GLOBALS['lb_options'] = array();
	$GLOBALS['lb_hooks'] = array();
	$GLOBALS['lb_hook_events'] = array();
	$GLOBALS['lb_rewrite_flushes'] = 0;
	$GLOBALS['lb_denied_caps'] = array();
}

$tests = array();
$tests['database install only records a verified schema'] = static function () {
	reset_fixture();
	$GLOBALS['wpdb']->valid_schema = true;
	assert_true( true === \LinguaBridge\Storage::install(), 'Expected a valid schema install.' );
	assert_true( \LinguaBridge\Storage::SCHEMA_VERSION === get_option( \LinguaBridge\Storage::SCHEMA_OPTION ), 'Verified schema version should be stored.' );
	reset_fixture();
	$GLOBALS['wpdb']->valid_schema = false;
	assert_true( false === \LinguaBridge\Storage::install(), 'Incomplete schema should fail installation.' );
	assert_true( false === get_option( \LinguaBridge\Storage::SCHEMA_OPTION ), 'Failed schema install must not be marked complete.' );
};
$tests['failed multi-row imports roll back partial relationships'] = static function () {
	reset_fixture();
	$GLOBALS['wpdb']->fail_object_id = 2;
	$inserted = \LinguaBridge\Storage::insert_group(
		array(
			array( 'object_id' => 1, 'group_uuid' => 'atomic-group', 'language' => 'it', 'source_id' => 1, 'source_hash' => 'hash', 'review_state' => 'source' ),
			array( 'object_id' => 2, 'group_uuid' => 'atomic-group', 'language' => 'en', 'source_id' => 1, 'source_hash' => 'hash', 'review_state' => 'needs_review' ),
		)
	);
	assert_true( false === $inserted, 'Expected the failed row to reject the whole import.' );
	assert_true( empty( $GLOBALS['wpdb']->rows ), 'A partial language group must not remain after failure.' );
};
$tests['BCP-47 language tags parse and normalize'] = static function () {
	$parsed = \LinguaBridge\Languages::parse_language_lines( "it|Italiano\nen-GB|English (UK)\nzh-Hans|简体中文\nes-419|Español\ninvalid code|Nope" );
	assert_true( array_keys( $parsed ) === array( 'it', 'en-gb', 'zh-hans', 'es-419' ), 'Expected valid language tags to be retained in lowercase.' );
};
$tests['settings accept BCP-47 glossaries'] = static function () {
	reset_fixture();
	$result = \LinguaBridge\Languages::save_from_request(
		array(
			'languages' => "it|Italiano\nen|English\nzh-Hans|Simplified Chinese",
			'default_language' => 'it',
			'translation_glossary' => 'it|Università degli Studi|en|University of Studies|Official name',
		)
	);
	assert_true( true === $result, 'Expected settings to save.' );
	$settings = get_option( \LinguaBridge\Languages::OPTION );
	assert_true( isset( $settings['languages']['zh-hans'] ), 'Expected script-subtag language in saved settings.' );
	assert_true( 'en' === $settings['translation_glossary'][0]['target_language'], 'Expected glossary language to normalize.' );
};
$tests['language URL prefix setting requires readable permalinks'] = static function () {
	reset_fixture();
	$result = \LinguaBridge\Languages::save_from_request( array( 'languages' => "it|Italiano\nen|English", 'default_language' => 'it', 'prefix_language_urls' => '1' ) );
	assert_true( is_wp_error( $result ) && 'lingua_pretty_permalinks_required' === $result->get_error_code(), 'Prefix routing must reject plain permalinks.' );
	$GLOBALS['lb_options']['permalink_structure'] = '/%postname%/';
	$result = \LinguaBridge\Languages::save_from_request( array( 'languages' => "it|Italiano\nen|English", 'default_language' => 'it', 'prefix_language_urls' => '1' ) );
	assert_true( true === $result && \LinguaBridge\Languages::prefix_urls_enabled(), 'Prefix routing should save with readable permalinks.' );
	$result = \LinguaBridge\Languages::save_from_request( array( 'languages' => "it|Italiano\napi|API", 'default_language' => 'it', 'prefix_language_urls' => '1' ) );
	assert_true( is_wp_error( $result ) && 'lingua_reserved_language_prefix' === $result->get_error_code(), 'Reserved WordPress paths must not be accepted as language prefixes.' );
};
$tests['language rewrite rules include locale routes but leave REST routes alone'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][ \LinguaBridge\Languages::OPTION ] = array( 'default_language' => 'it', 'prefix_language_urls' => true, 'languages' => array( 'it' => 'Italiano', 'en' => 'English' ) );
	$GLOBALS['lb_options']['permalink_structure'] = '/%postname%/';
	$rules = \LinguaBridge\Routing::rewrite_rules( array( '^(.?.+?)/?$' => 'index.php?pagename=$matches[1]', '^wp-json/?$' => 'index.php?rest_route=/' ) );
	assert_true( isset( $rules['^en/?$'] ), 'Expected a language-specific homepage rule.' );
	assert_true( isset( $rules['^en/(.?.+?)/?$'] ) && false !== strpos( $rules['^en/(.?.+?)/?$'], 'lingua_bridge_route_language=en' ), 'Expected prefixed page rules to retain core captures and add a language query var.' );
	assert_true( ! isset( $rules['^en/wp-json/?$'] ), 'REST routes must not be cloned under language prefixes.' );
};
$tests['language routing stays inert by default'] = static function () {
	reset_fixture();
	$original_rules = array( '^(.?.+?)/?$' => 'index.php?pagename=$matches[1]' );
	assert_true( $original_rules === \LinguaBridge\Routing::rewrite_rules( $original_rules ), 'Default settings must preserve the site\'s existing rewrite rules.' );
	$clauses = \LinguaBridge\Routing::filter_language_queries( array( 'join' => '', 'where' => ' WHERE 1=1 ' ), new FakeWpQuery( array( 'lingua_bridge_route_language' => 'en' ) ) );
	assert_true( '' === $clauses['join'], 'Disabled prefix routing must not filter normal WordPress queries.' );
};
$tests['translated language homepage uses the translated front page and front-page option'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][ \LinguaBridge\Languages::OPTION ] = array( 'default_language' => 'it', 'prefix_language_urls' => true, 'languages' => array( 'it' => 'Italiano', 'en' => 'English' ) );
	$GLOBALS['lb_options']['permalink_structure'] = '/%postname%/';
	$GLOBALS['lb_options']['page_on_front'] = 1;
	$GLOBALS['lb_options']['show_on_front'] = 'page';
	make_post( 1, 'Homepage italiana' );
	make_post( 2, 'English homepage' );
	$GLOBALS['wpdb']->rows[1] = array( 'object_id' => 1, 'group_uuid' => 'home-group', 'language' => 'it', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'source' );
	$GLOBALS['wpdb']->rows[2] = array( 'object_id' => 2, 'group_uuid' => 'home-group', 'language' => 'en', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'source' );
	$_SERVER['REQUEST_URI'] = '/?lingua_bridge_route_language=en&lingua_bridge_home_language=en';
	$forged = \LinguaBridge\Routing::resolve_home_request( array( 'lingua_bridge_home_language' => 'en', 'lingua_bridge_route_language' => 'en' ) );
	assert_true( ! isset( $forged['page_id'] ) && ! isset( $forged['lingua_bridge_route_language'] ), 'The language never comes from the query string, only from the path.' );
	$_SERVER['REQUEST_URI'] = '/en/';
	$query = \LinguaBridge\Routing::resolve_home_request( array( 'lingua_bridge_home_language' => 'en', 'lingua_bridge_route_language' => 'en' ) );
	assert_true( 2 === $query['page_id'] && 'page' === $query['post_type'], 'The /en/ route must query the translated page.' );
	assert_true( 2 === \LinguaBridge\Routing::filter_front_page_option( 1 ), 'WordPress must see the English page as page_on_front so the front-page template is selected.' );
	assert_true( 1 === \LinguaBridge\Routing::source_front_page_id(), 'Plugin logic must retain the original configured homepage ID.' );
	assert_true( 'https://example.test/en/' === \LinguaBridge\Routing::filter_permalink( 'https://example.test/english-homepage/', 2 ), 'The translated homepage permalink must be the language root.' );
};
$tests['a language home is the list of its articles when the home is the blog, the original home when not translated'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][ \LinguaBridge\Languages::OPTION ] = array( 'default_language' => 'it', 'prefix_language_urls' => true, 'languages' => array( 'it' => 'Italiano', 'en' => 'English' ) );
	$GLOBALS['lb_options']['permalink_structure'] = '/%postname%/';
	$GLOBALS['lb_options']['show_on_front'] = 'posts';
	$_SERVER['REQUEST_URI'] = '/en/';
	$query = \LinguaBridge\Routing::resolve_home_request( array( 'lingua_bridge_home_language' => 'en', 'lingua_bridge_route_language' => 'en' ) );
	assert_true( ! isset( $query['error'] ) && ! isset( $query['lingua_bridge_home_language'] ) && 'en' === $query['lingua_bridge_route_language'], 'With the blog as home, /en/ is the list of English articles, not a 404.' );
	assert_true( 'en' === \LinguaBridge\Routing::request_language(), 'The list is in English.' );
	$GLOBALS['lb_options']['show_on_front'] = 'page';
	$GLOBALS['lb_options']['page_on_front'] = 1;
	make_post( 1, 'Homepage italiana' );
	$GLOBALS['wpdb']->rows[1] = array( 'object_id' => 1, 'group_uuid' => 'home-only', 'language' => 'it', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'source' );
	$query = \LinguaBridge\Routing::resolve_home_request( array( 'lingua_bridge_home_language' => 'en', 'lingua_bridge_route_language' => 'en' ) );
	$fallback = new ReflectionProperty( '\\LinguaBridge\\Routing', 'home_fallback' );
	$fallback->setAccessible( true );
	assert_true( ! isset( $query['error'] ) && true === $fallback->getValue(), 'A static front page not translated yet: /en/ leads to the original home, not a 404.' );
};
$tests['language route limits frontend queries to the requested language'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][ \LinguaBridge\Languages::OPTION ] = array( 'default_language' => 'it', 'prefix_language_urls' => true, 'languages' => array( 'it' => 'Italiano', 'en' => 'English' ) );
	$GLOBALS['lb_options']['permalink_structure'] = '/%postname%/';
	$clauses = \LinguaBridge\Routing::filter_language_queries( array( 'join' => '', 'where' => ' WHERE 1=1 ' ), new FakeWpQuery( array( 'lingua_bridge_route_language' => 'en' ) ) );
	assert_true( false !== strpos( $clauses['join'], 'wp_lingua_bridge_translations AS lb_language_route' ), 'Language archives need the indexed relationship join.' );
	assert_true( false !== strpos( $clauses['where'], "lb_language_route.language = 'en'" ), 'Language archives must exclude source-language content.' );
	$menu_clauses = \LinguaBridge\Routing::filter_language_queries( array( 'join' => '', 'where' => ' WHERE 1=1 ' ), new FakeWpQuery( array( 'post_type' => 'nav_menu_item' ) ) );
	assert_true( '' === $menu_clauses['join'], 'WordPress navigation menu records must not be hidden by language filtering.' );
	$template_clauses = \LinguaBridge\Routing::filter_language_queries( array( 'join' => '', 'where' => ' WHERE 1=1 ' ), new FakeWpQuery( array( 'post_type' => 'wp_template' ) ) );
	assert_true( '' === $template_clauses['join'], 'Global Site Editor templates must not be hidden by language filtering.' );
};
$tests['deactivation removes prefixed rewrites before flushing WordPress rules'] = static function () {
	reset_fixture();
	$callback = array( \LinguaBridge\Routing::class, 'rewrite_rules' );
	add_filter( 'rewrite_rules_array', $callback, 20, 1 );
	\LinguaBridge\Routing::deactivate();
	assert_true( empty( $GLOBALS['lb_hooks']['rewrite_rules_array'][20] ), 'Stored route generation must be removed before deactivation flush.' );
	assert_true( 1 === $GLOBALS['lb_rewrite_flushes'], 'Deactivation should flush WordPress rewrite rules once.' );
};
$tests['activation hooks the language rules before flushing'] = static function () {
	reset_fixture();
	\LinguaBridge\Routing::activate();
	assert_true( array( array( \LinguaBridge\Routing::class, 'rewrite_rules' ) ) === ( $GLOBALS['lb_hooks']['rewrite_rules_array'][20] ?? null ), 'The language rules must be hooked when the activation flush runs.' );
	assert_true( 1 === $GLOBALS['lb_rewrite_flushes'], 'Activation flushes once.' );
};
$tests['translation source uses the site context and only the site glossary'] = static function () {
	reset_fixture();
	make_post( 1, 'Residenze universitarie' );
	$result = \LinguaBridge\Abilities::get_source( array( 'post_id' => 1, 'target_language' => 'en' ) );
	assert_true( ! is_wp_error( $result ), 'Expected the translation source to be readable.' );
	assert_true( false !== strpos( $result['translation_prompt'], 'CONTESTO DEL SITO: Sito:' ), 'Expected the site name and description as the default context.' );
	assert_true( false === strpos( $result['translation_prompt'], 'GLOSSARIO OBBLIGATORIO:' ), 'No glossary is built in.' );
};
$tests['invalid draft fields fail before creating a WordPress post'] = static function () {
	reset_fixture();
	make_post( 1, 'Original title' );
	$result = \LinguaBridge\Translations::create_or_update_draft(
		1,
		'en',
		array( 'title' => 'Translated title', 'content' => 'Translated content', 'fields' => 'not-a-list' )
	);
	assert_true( is_wp_error( $result ) && 'lingua_invalid_fields' === $result->get_error_code(), 'Expected invalid fields to be rejected.' );
	assert_true( 1 === count( $GLOBALS['lb_posts'] ), 'Invalid input must not leave an orphan draft.' );
	assert_true( empty( $GLOBALS['wpdb']->rows ), 'Invalid input must not create a relationship row.' );
};
$tests['new translation is a normal editable linked draft'] = static function () {
	reset_fixture();
	make_post( 1, 'Original title' );
	$result = \LinguaBridge\Translations::create_draft( 1, 'en' );
	assert_true( 101 === $result, 'Expected a new WordPress post ID.' );
	assert_true( 'draft' === get_post( $result )->post_status, 'Translation must begin as a draft.' );
	assert_true( 'Source block content' === get_post( $result )->post_content, 'Gutenberg/Etch content must be copied verbatim.' );
	assert_true( 'en' === \LinguaBridge\Storage::find( $result )['language'], 'Expected target language relation.' );
	assert_true( 1 === absint( get_post_meta( $result, '_lingua_bridge_source_id', true ) ), 'Expected source ID metadata for field providers.' );
};
$tests['hierarchical content uses the translated parent when available'] = static function () {
	reset_fixture();
	make_post( 10, 'Italian parent' );
	make_post( 11, 'English parent', 'publish' );
	make_post( 1, 'Italian child' );
	$GLOBALS['lb_posts'][1]->post_parent = 10;
	$GLOBALS['wpdb']->rows[10] = array( 'object_id' => 10, 'group_uuid' => 'parent-group', 'language' => 'it', 'source_id' => 10, 'source_hash' => '', 'review_state' => 'source' );
	$GLOBALS['wpdb']->rows[11] = array( 'object_id' => 11, 'group_uuid' => 'parent-group', 'language' => 'en', 'source_id' => 10, 'source_hash' => '', 'review_state' => 'source' );
	$result = \LinguaBridge\Translations::create_draft( 1, 'en' );
	assert_true( 11 === get_post( $result )->post_parent, 'Translated page should inherit its translated parent.' );
};
$tests['Codex can create and update a human-editable draft'] = static function () {
	reset_fixture();
	make_post( 1, 'Original title' );
	$result = \LinguaBridge\Translations::create_or_update_draft(
		1,
		'en',
		array( 'title' => 'Translated title', 'content' => 'Translated block content', 'excerpt' => 'Short summary', 'slug' => 'translated-page' )
	);
	assert_true( 101 === $result, 'Expected the translated draft ID.' );
	assert_true( 'draft' === get_post( 101 )->post_status, 'Translated content must remain a draft.' );
	assert_true( 'Translated title' === get_post( 101 )->post_title, 'Expected translated title to be saved.' );
	assert_true( 'translated-page' === get_post( 101 )->post_name, 'Expected the translated slug to be saved.' );
};
$tests['Polylang and WPML imports mark a schema source for field adapters'] = static function () {
	reset_fixture();
	make_post( 1, 'Italian page' );
	make_post( 2, 'English page' );
	$method = new ReflectionMethod( '\\LinguaBridge\\Importer', 'save_group' );
	$method->setAccessible( true );
	$result = $method->invoke( null, array( 'it' => 1, 'en' => 2 ) );
	assert_true( 'imported' === $result, 'Expected linked language group.' );
	assert_true( 1 === absint( get_post_meta( 2, '_lingua_bridge_source_id', true ) ), 'Imported translation must resolve source ACF and Etch definitions.' );
	delete_post_meta( 2, '_lingua_bridge_source_id' );
	$result = $method->invoke( null, array( 'it' => 1, 'en' => 2 ) );
	assert_true( 'already' === $result, 'Reimport should remain idempotent.' );
	assert_true( 1 === absint( get_post_meta( 2, '_lingua_bridge_source_id', true ) ), 'Reimport should repair missing provider source metadata.' );
};
$tests['published translations cannot be overwritten by the connector'] = static function () {
	reset_fixture();
	make_post( 1, 'Original title' );
	make_post( 2, 'Published translation', 'publish' );
	$GLOBALS['wpdb']->rows[1] = array( 'object_id' => 1, 'group_uuid' => 'group-a', 'language' => 'it', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'source' );
	$GLOBALS['wpdb']->rows[2] = array( 'object_id' => 2, 'group_uuid' => 'group-a', 'language' => 'en', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'reviewed' );
	$result = \LinguaBridge\Translations::create_or_update_draft( 1, 'en', array( 'title' => 'Overwritten', 'content' => 'Overwritten' ) );
	assert_true( is_wp_error( $result ) && 'lingua_non_draft_target' === $result->get_error_code(), 'Expected non-draft targets to be refused.' );
	assert_true( 'Published translation' === get_post( 2 )->post_title, 'Published content must remain unchanged.' );
};
$tests['correction drafts are separate, idempotent, and preserve the published translation'] = static function () {
	reset_fixture();
	make_post( 1, 'Italian source', 'publish', 'Italian source content' );
	make_post( 2, 'Published English', 'publish', 'Live English content' );
	$GLOBALS['wpdb']->rows[1] = array( 'object_id' => 1, 'group_uuid' => 'correction-group', 'language' => 'it', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'source' );
	$GLOBALS['wpdb']->rows[2] = array( 'object_id' => 2, 'group_uuid' => 'correction-group', 'language' => 'en', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'reviewed' );

	$first = \LinguaBridge\Translations::create_or_update_correction_draft( 1, 'en', array( 'title' => 'Corrected English', 'content' => 'Corrected draft content' ) );
	assert_true( 101 === $first, 'Expected a separate correction draft.' );
	assert_true( 'draft' === get_post( $first )->post_status, 'Correction copy must remain a draft.' );
	assert_true( 'Corrected English' === get_post( $first )->post_title, 'Correction copy should receive the revised title.' );
	assert_true( 'Published English' === get_post( 2 )->post_title, 'Published title must remain unchanged.' );
	assert_true( 'Live English content' === get_post( 2 )->post_content, 'Published content must remain unchanged.' );
	assert_true( 2 === \LinguaBridge\Storage::find_group_language( 'correction-group', 'en' )['object_id'], 'The published translation must remain the active language-group member.' );
	assert_true( null === \LinguaBridge\Storage::find( $first ), 'A review copy must not replace the language-group relationship.' );
	assert_true( 2 === absint( get_post_meta( $first, '_lingua_bridge_correction_target_id', true ) ), 'Review copy must identify the published translation it corrects.' );
	assert_true( 1 === absint( get_post_meta( $first, '_lingua_bridge_correction_source_id', true ) ), 'Review copy must identify its source content.' );

	$second = \LinguaBridge\Translations::create_or_update_correction_draft( 1, 'en', array( 'title' => 'Updated correction', 'content' => 'Updated correction content' ) );
	assert_true( $first === $second, 'Repeated saves should update the same correction draft.' );
	assert_true( 3 === count( $GLOBALS['lb_posts'] ), 'Repeated saves must not create duplicate correction drafts.' );
	assert_true( 'Updated correction' === get_post( $first )->post_title, 'The existing correction draft should be editable.' );
	assert_true( 'Live English content' === get_post( 2 )->post_content, 'Updating a correction draft must never touch the live post.' );
};
$tests['correction drafts require an existing published linked translation'] = static function () {
	reset_fixture();
	make_post( 1, 'Italian source' );
	make_post( 2, 'English pending', 'pending' );
	$GLOBALS['wpdb']->rows[1] = array( 'object_id' => 1, 'group_uuid' => 'pending-group', 'language' => 'it', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'source' );
	$GLOBALS['wpdb']->rows[2] = array( 'object_id' => 2, 'group_uuid' => 'pending-group', 'language' => 'en', 'source_id' => 1, 'source_hash' => '', 'review_state' => 'needs_review' );
	$result = \LinguaBridge\Translations::create_or_update_correction_draft( 1, 'en', array( 'title' => 'Correction', 'content' => 'Draft' ) );
	assert_true( is_wp_error( $result ) && 'lingua_correction_target_not_published' === $result->get_error_code(), 'Non-published targets must not create correction copies.' );
	assert_true( 2 === count( $GLOBALS['lb_posts'] ), 'Refusing a non-published target must not create an orphan draft.' );
};
$tests['standalone correction drafts are separate and idempotent for unlinked published content'] = static function () {
	reset_fixture();
	make_post( 20, 'Published English page', 'publish', 'Live English content' );
	$first = \LinguaBridge\Translations::create_or_update_standalone_correction_draft( 20, 'en', array( 'title' => 'Corrected page', 'content' => 'Review copy' ) );
	assert_true( 101 === $first, 'Expected a separate review draft for the unlinked published page.' );
	assert_true( 'draft' === get_post( $first )->post_status, 'A standalone review copy must stay a draft.' );
	assert_true( 'Live English content' === get_post( 20 )->post_content, 'The published page must remain unchanged.' );
	assert_true( 20 === absint( get_post_meta( $first, '_lingua_bridge_correction_target_id', true ) ), 'Expected a link back to the published page.' );
	assert_true( 0 === absint( get_post_meta( $first, '_lingua_bridge_correction_source_id', true ) ), 'A standalone review copy must not invent a source relationship.' );
	assert_true( null === \LinguaBridge\Storage::find( 20 ) && null === \LinguaBridge\Storage::find( $first ), 'A standalone review copy must not create language-group rows.' );
	$second = \LinguaBridge\Translations::create_or_update_standalone_correction_draft( 20, 'en', array( 'title' => 'Revised page', 'content' => 'Revised review copy' ) );
	assert_true( $first === $second, 'Repeated review saves should update the same draft.' );
	assert_true( 2 === count( $GLOBALS['lb_posts'] ), 'Repeated review saves must not duplicate the draft.' );
	assert_true( 'Revised review copy' === get_post( $first )->post_content, 'The review draft should remain editable.' );
};
$tests['standalone correction drafts require published editable content'] = static function () {
	reset_fixture();
	make_post( 20, 'Draft page', 'draft', 'Unpublished content' );
	$result = \LinguaBridge\Translations::create_or_update_standalone_correction_draft( 20, 'en', array( 'title' => 'Review', 'content' => 'Review copy' ) );
	assert_true( is_wp_error( $result ) && 'lingua_review_target_not_published' === $result->get_error_code(), 'Standalone review copies must require a published target.' );
	assert_true( 1 === count( $GLOBALS['lb_posts'] ), 'A rejected review request must not create a draft.' );
};
$tests['deleting a source keeps surviving language relations usable'] = static function () {
	reset_fixture();
	make_post( 2, 'English page' );
	$GLOBALS['wpdb']->rows[1] = array( 'object_id' => 1, 'group_uuid' => 'group-b', 'language' => 'it', 'source_id' => 1, 'source_hash' => 'old', 'review_state' => 'source' );
	$GLOBALS['wpdb']->rows[2] = array( 'object_id' => 2, 'group_uuid' => 'group-b', 'language' => 'en', 'source_id' => 1, 'source_hash' => 'old', 'review_state' => 'reviewed' );
	\LinguaBridge\Translations::on_post_deleted( 1 );
	assert_true( ! isset( $GLOBALS['wpdb']->rows[1] ), 'Deleted post relation must be removed.' );
	assert_true( 2 === $GLOBALS['wpdb']->rows[2]['source_id'], 'Surviving relation must point to its fallback root.' );
	assert_true( 2 === absint( get_post_meta( 2, '_lingua_bridge_source_id', true ) ), 'Provider field adapters must use the surviving root.' );
	assert_true( hash( 'sha256', wp_json_encode( array( 'post_title' => 'English page', 'post_content' => 'Source block content', 'post_excerpt' => '', 'fields' => array() ) ) ) === $GLOBALS['wpdb']->rows[2]['source_hash'], 'Fallback fingerprint must be refreshed.' );
};
$tests['explicit custom field allowlist is enforced'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][\LinguaBridge\Languages::OPTION] = array(
		'default_language' => 'it',
		'languages' => array( 'it' => 'Italiano', 'en' => 'English' ),
		'translatable_meta_keys' => array( 'public_label' ),
	);
	make_post( 1, 'Source' );
	make_post( 2, 'Translation', 'draft' );
	update_post_meta( 2, '_lingua_bridge_source_id', 1 );
	$result = \LinguaBridge\Fields::save_translatable_values(
		2,
		array(
			array( 'provider' => 'post_meta', 'key' => 'public_label', 'value' => 'Accessible text' ),
			array( 'provider' => 'post_meta', 'key' => 'private_token', 'value' => 'must not save' ),
		)
	);
	assert_true( true === $result, 'Expected field save to succeed.' );
	assert_true( 'Accessible text' === get_post_meta( 2, 'public_label', true ), 'Configured field should be translated.' );
	assert_true( '' === get_post_meta( 2, 'private_token', true ), 'Unconfigured fields must be ignored.' );
};
$tests['a javascript: link hidden with tabs, new lines or entities without semicolon is refused'] = static function () {
	reset_fixture();
	$orig = array( 'title' => 'Titolo', 'content' => '<p>Ciao <a href="https://example.test/">link</a></p>' );
	$vectors = array(
		"<p>Hello <a href=\"jav\tascript:alert(1)\">link</a></p>",
		"<p>Hello <a href=\"java\nscript:alert(1)\">link</a></p>",
		"<p>Hello <a href=\"\x01javascript:alert(1)\">link</a></p>",
		'<p>Hello <a href="&#106avascript:alert(1)">link</a></p>',
		'<p>Hello <a href="javascript&#58alert(1)">link</a></p>',
		'<p>Hello <a href="java&#9script:alert(1)">link</a></p>',
		'<p>Hello <a href="javascrip&#x74:alert(1)">link</a></p>',
		'<p>Hello <a href="java&Tab;script&colon;alert(1)">link</a></p>',
		'<!-- wp:etch/element {"tag":"a","attributes":{"href":"java\tscript:alert(1)"}} --><p>Hello</p><!-- /wp:etch/element -->',
		'<!-- wp:etch/element {"tag":"a","attributes":{"href":"java\u0009script:alert(1)"}} --><p>Hello</p><!-- /wp:etch/element -->',
	);
	foreach ( $vectors as $i => $content ) {
		$result = \LinguaBridge\Translations::check_active_markup( $orig, array( 'title' => 'Title', 'content' => $content ) );
		assert_true( is_wp_error( $result ) && 'lingua_active_markup' === $result->get_error_code(), "Vector $i must be refused: " . addcslashes( $content, "\0..\37" ) );
	}
	$prose = array( 'title' => 'T', 'content' => "<p>javascript: un linguaggio.\nCiao</p><a href=\"javascript:void(0)\">x</a>" );
	assert_true( true === \LinguaBridge\Translations::check_active_markup( $prose, array( 'title' => 'T', 'content' => "<p>javascript: a language.\nHello</p><a href=\"javascript:void(0)\">y</a>" ) ), 'Prose about javascript: and a link kept from the original are accepted.' );
};
$tests['a new shortcode or a new expression of Etch and Histeon is refused, the original\'s stay'] = static function () {
	reset_fixture();
	$GLOBALS['shortcode_tags'] = array( 'embed' => '__return_empty_string', 'gallery' => '__return_empty_string' );
	$orig = array( 'title' => 'Titolo {this.title}', 'content' => '<p>Ciao [gallery ids="1,2"] e {props.nome}</p><!-- wp:etch/text {"content":"Scritto da {item.author.name}"} /-->' );
	$kept = \LinguaBridge\Translations::check_active_markup( $orig, array( 'title' => 'Title {this.title}', 'content' => '<p>Hello [gallery ids="1,2"] and {props.nome}</p><!-- wp:etch/text {"content":"Written by {item.author.name}"} /--><p>[1] a note, {a} brace, [not a shortcode]</p>' ) );
	assert_true( true === $kept, 'The original\'s shortcodes and expressions, notes in brackets and plain braces are accepted.' );
	foreach ( array(
		'<p>Hello [gallery ids="1,2"] and {props.nome}</p><!-- wp:etch/text {"content":"Written by {item.author.name}"} /-->[embed]https://evil.example/[/embed]',
		'<p>Hello [gallery ids="1,2"] [gallery ids="3"] and {props.nome}</p><!-- wp:etch/text {"content":"Written by {item.author.name}"} /-->',
		'<p>Hello [gallery ids="1,2"] and {props.nome} {user.email}</p><!-- wp:etch/text {"content":"Written by {item.author.name}"} /-->',
		'<p>Hello [gallery ids="1,2"] and {props.secret}</p><!-- wp:etch/text {"content":"Written by {item.author.name}"} /-->',
		'<p>Hello [gallery ids="1,2"] and {props.nome}</p><!-- wp:etch/text {"content":"Written by {item.author.email}"} /-->',
	) as $i => $content ) {
		$result = \LinguaBridge\Translations::check_active_markup( $orig, array( 'title' => 'Title {this.title}', 'content' => $content ) );
		assert_true( is_wp_error( $result ) && 'lingua_active_markup' === $result->get_error_code(), "Vector $i must be refused: $content" );
	}
	$title = \LinguaBridge\Translations::check_active_markup( $orig, array( 'title' => 'Title {site.admin_email}' ) );
	assert_true( is_wp_error( $title ), 'A different expression in the title is refused.' );
	unset( $GLOBALS['shortcode_tags'] );
};
$tests['the switcher options of the shortcode and of the block are read the same way'] = static function () {
	$defaults = \LinguaBridge\Frontend::switcher_options( '' );
	assert_true( array( 'floating' => '', 'style' => 'list', 'label' => 'name', 'hide_current' => false, 'show_missing' => false ) === $defaults, 'No options: a list of names.' );
	assert_true( '' === \LinguaBridge\Frontend::switcher_options( array( 'floating' => 'bottom-left' ) )['floating'], 'C1: a shortcode or a block never makes itself the floating switcher.' );
	$shortcode = \LinguaBridge\Frontend::switcher_options( array( 'style' => 'Dropdown', 'label' => 'code', 'hide_current' => 'yes', 'show_missing' => '1' ) );
	$block = \LinguaBridge\Frontend::switcher_options( array( 'style' => 'dropdown', 'label' => 'code', 'hideCurrent' => true, 'showMissing' => true ) );
	assert_true( $shortcode === $block && 'dropdown' === $block['style'] && $block['hide_current'] && $block['show_missing'], 'The shortcode and the block give the same options.' );
	$odd = \LinguaBridge\Frontend::switcher_options( array( 'style' => '<script>', 'label' => 'flag', 'hide_current' => 'no' ) );
	assert_true( 'list' === $odd['style'] && 'name' === $odd['label'] && false === $odd['hide_current'], 'Unknown values fall back to the defaults.' );
};
$tests['the glossary goes out as CSV and comes back from CSV or TBX'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][\LinguaBridge\Languages::OPTION] = array( 'default_language' => 'it', 'languages' => array( 'it' => 'Italiano', 'en' => 'English', 'fr' => 'Français' ) );
	$entries = array(
		array( 'source_language' => 'it', 'source_term' => 'Chiostro', 'target_language' => 'en', 'target_term' => 'Cloister', 'note' => 'sempre maiuscolo' ),
		array( 'source_language' => 'it', 'source_term' => '=HYPERLINK("x")', 'target_language' => 'en', 'target_term' => 'Link, "quoted"', 'note' => '' ),
	);
	$csv = \LinguaBridge\Languages::glossary_csv( $entries );
	assert_true( 0 === strpos( $csv, "\xEF\xBB\xBFsource_language,source_term,target_language,target_term,note\n" ), 'A header row after the UTF-8 mark.' );
	assert_true( false !== strpos( $csv, "'=HYPERLINK" ), 'A cell that would be a formula starts with a quote.' );
	assert_true( $entries === \LinguaBridge\Languages::glossary_from_file( $csv ), 'The CSV comes back as the same glossary.' );
	$semicolons = "source_language;source_term;target_language;target_term;note\r\nit;Refettorio;fr;Réfectoire;\r\nit;;fr;vuoto;\r\nxx yy;A;fr;B;\r\n";
	$read = \LinguaBridge\Languages::glossary_from_file( $semicolons );
	assert_true( 1 === count( $read ) && 'Réfectoire' === $read[0]['target_term'], 'A spreadsheet CSV with semicolons; empty terms and bad languages are left out.' );
	assert_true( is_wp_error( \LinguaBridge\Languages::glossary_from_file( '' ) ) && is_wp_error( \LinguaBridge\Languages::glossary_from_file( "a\0b" ) ), 'An empty file or one with null bytes is refused.' );
	if ( ! class_exists( 'DOMDocument' ) ) {
		return; // The TBX checks need PHP's DOM extension; tests/wp/xliff-run.php runs them on WordPress too.
	}
	$tbx = '<?xml version="1.0" encoding="UTF-8"?><tbx type="TBX-Basic" xml:lang="it"><text><body><termEntry id="t1"><langSet xml:lang="it"><tig><term>Chiostro</term></tig></langSet><langSet xml:lang="en-GB"><tig><term>Cloister</term></tig></langSet><langSet xml:lang="fr"><tig><term>Cloître</term></tig></langSet><langSet xml:lang="de"><tig><term>Kreuzgang</term></tig></langSet></termEntry></body></text></tbx>';
	$from_tbx = \LinguaBridge\Languages::glossary_from_file( $tbx );
	assert_true( array( array( 'it', 'Chiostro', 'en', 'Cloister' ), array( 'it', 'Chiostro', 'fr', 'Cloître' ) ) === array_map( static fn( $e ) => array( $e['source_language'], $e['source_term'], $e['target_language'], $e['target_term'] ), $from_tbx ), 'A TBX gives the original\'s term towards each configured language (de is not one).' );
	assert_true( is_wp_error( \LinguaBridge\Languages::glossary_from_file( '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><tbx>&e;</tbx>' ) ), 'A TBX with a DOCTYPE is refused.' );
};
$tests['a correction copy keeps the password and the protection of the page it corrects'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][\LinguaBridge\Languages::OPTION] = array( 'default_language' => 'it', 'languages' => array( 'it' => 'Italiano', 'en' => 'English' ) );
	make_post( 2, 'Locked page' );
	$GLOBALS['lb_posts'][2]->post_password = 'segreta';
	$copy = \LinguaBridge\Translations::create_or_update_standalone_correction_draft( 2, 'en', array( 'title' => 'Locked page', 'content' => 'Fixed copy' ) );
	assert_true( is_int( $copy ) && 'segreta' === get_post( $copy )->post_password, 'The correction copy must keep the password.' );
	assert_true( array( 'publish', 'segreta' ) === \LinguaBridge\Translations::protection( $copy ), 'Publishing the copy must keep the password of the page it corrects.' );
	$GLOBALS['lb_posts'][2]->post_password = '';
	$GLOBALS['lb_posts'][2]->post_status = 'private';
	assert_true( array( 'private', '' ) === \LinguaBridge\Translations::protection( $copy ), 'A copy of a private page stays private.' );
};
$tests['a correction copy needs the right to create items of the type'] = static function () {
	reset_fixture();
	$GLOBALS['lb_options'][\LinguaBridge\Languages::OPTION] = array( 'default_language' => 'it', 'languages' => array( 'it' => 'Italiano', 'en' => 'English' ) );
	make_post( 2, 'Published page' );
	$GLOBALS['lb_denied_caps'] = array( 'edit_posts' );
	$copy = \LinguaBridge\Translations::create_or_update_standalone_correction_draft( 2, 'en', array( 'title' => 'Published page', 'content' => 'Fixed copy' ) );
	assert_true( is_wp_error( $copy ) && 'lingua_forbidden' === $copy->get_error_code(), 'Without create_posts no copy is created.' );
	assert_true( 1 === count( $GLOBALS['lb_posts'] ), 'A refused copy leaves nothing behind.' );
};
$tests['an XLIFF file in UTF-16 is refused before it is parsed'] = static function () {
	reset_fixture();
	$xml = '<?xml version="1.0"?><xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2"></xliff>';
	foreach ( array( "\xFF\xFE" . mb_convert_encoding( $xml, 'UTF-16LE', 'UTF-8' ), mb_convert_encoding( $xml, 'UTF-16BE', 'UTF-8' ), "\xFE\xFF" . mb_convert_encoding( $xml, 'UTF-16BE', 'UTF-8' ) ) as $i => $utf16 ) {
		$result = \LinguaBridge\Xliff::import( $utf16 );
		assert_true( is_wp_error( $result ) && 'lingua_xliff_encoding' === $result->get_error_code(), "UTF-16 file $i must be refused as an encoding error." );
	}
};
$tests['an Etch page gives only the texts people see and read aloud'] = static function () {
	reset_fixture();
	$content = '<!-- wp:etch/element {"metadata":{"name":"Hero sezione"},"tag":"section","attributes":{"class":"hero","aria-label":"Approfondimenti"}} -->'
		. '<!-- wp:etch/text {"metadata":{"name":"Text"},"content":"Benvenuti nel centro"} /-->'
		. '<!-- wp:etch/element {"metadata":{"name":"Ricerca","bindings":{"x":{"source":"y"}}},"tag":"form","attributes":{"action":"/","aria-description":"Cerca fra le pagine"}} -->'
		. '<!-- wp:etch/element {"metadata":{"name":"span"},"tag":"input","attributes":{"type":"search","name":"s","placeholder":"Scrivi qui","title":"Campo di ricerca"}} /-->'
		. '<!-- wp:etch/element {"tag":"input","attributes":{"type":"submit","value":"Cerca"}} /-->'
		. '<!-- wp:etch/element {"tag":"input","attributes":{"type":"hidden","name":"lang","value":"italiano"}} /-->'
		. '<!-- /wp:etch/element --><!-- /wp:etch/element -->';
	$texts = array_column( \LinguaBridge\Xliff::segment( $content )['segments'], 'text' );
	$expected = array( 'Approfondimenti', 'Benvenuti nel centro', 'Cerca fra le pagine', 'Scrivi qui', 'Campo di ricerca', 'Cerca' );
	assert_true( $expected === $texts, 'Segments must be the visible and accessible texts only, got: ' . implode( ' | ', $texts ) );
};
$tests['a draft written by a machine notes links that now lead to another site'] = static function () {
	reset_fixture();
	$notes = \LinguaBridge\Translations::structure_differences( '<p><a href="https://example.test/chi-siamo/">Chi siamo</a> <img src="https://cdn.example.org/a.jpg"></p>', '<p><a href="https://phish.example/about/">About</a> <img src="https://cdn.example.org/a.jpg"> <a href="//other.example/x">x</a></p>' );
	assert_true( 2 === count( $notes ) && false !== strpos( $notes[0], 'phish.example' ) && false !== strpos( $notes[1], 'other.example' ), 'Links to new hosts are noted, the site and hosts already linked are not: ' . implode( ' | ', $notes ) );
	assert_true( array() === \LinguaBridge\Translations::structure_differences( '<p><a href="https://example.test/a/">a</a></p>', '<p><a href="https://example.test/en/a/">a</a></p>' ), 'A link to this site, even to another page, is not a note.' );
};
$tests['the language of the contents is guessed from their words, or not at all'] = static function () {
	$it = array( 'Il Centro Studi', 'Una prospettiva sull’Italia e sul mondo: la ricerca, la formazione e il confronto per chi vuole capire il presente. Gli incontri sono aperti a tutti e la scuola è per chi studia le istituzioni, la politica e le relazioni internazionali con il metodo della ricerca.' );
	assert_true( 'it' === \LinguaBridge\Languages::guess_language( $it ), 'Italian contents are Italian.' );
	$en = array( 'About the centre', 'The centre is a place for research and for the people who want to understand the present. Meetings are open to everyone, and the school is for those who study institutions, politics and international relations with the method of research.' );
	assert_true( 'en' === \LinguaBridge\Languages::guess_language( $en ), 'English contents are English.' );
	assert_true( '' === \LinguaBridge\Languages::guess_language( array( 'Home', 'Contatti', 'Newsletter' ) ), 'Too few words: no guess.' );
	assert_true( '' === \LinguaBridge\Languages::guess_language( array( str_repeat( 'LB da publish ', 13 ) ) ), 'One word repeated is no language.' );
};

$failures = 0;
foreach ( $tests as $name => $test ) {
	try {
		$test();
		fwrite( STDOUT, "PASS $name\n" );
	} catch ( Throwable $error ) {
		++$failures;
		fwrite( STDERR, "FAIL $name: " . $error->getMessage() . "\n" );
	}
}
fwrite( STDOUT, sprintf( "\n%d tests, %d failures\n", count( $tests ), $failures ) );
exit( $failures ? 1 : 0 );
