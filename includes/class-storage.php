<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage {
	const SCHEMA_VERSION = '6';
	const SCHEMA_OPTION  = 'lingua_bridge_schema_version';
	/** Bumped when content or a language link changes: summaries cached under the old value are left to expire. */
	const CONTENT_VERSION_OPTION = 'lingua_bridge_content_version';
	private static $available = null;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lingua_bridge_translations';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		$collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $table (
			object_id bigint(20) unsigned NOT NULL,
			group_uuid char(36) NOT NULL,
			language varchar(35) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			source_hash char(64) NOT NULL DEFAULT '',
			review_state varchar(24) NOT NULL DEFAULT 'needs_review',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (object_id),
			UNIQUE KEY group_language (group_uuid,language),
			KEY source_id (source_id),
			KEY language_review (language,review_state)
		) $collate;";
		dbDelta( $sql );
		// Strings outside contents (site title, menu labels, texts of the theme): Strings.
		$strings = Strings::table();
		dbDelta(
			"CREATE TABLE $strings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			context varchar(20) NOT NULL,
			source_hash char(40) NOT NULL,
			source text NOT NULL,
			language varchar(35) NOT NULL,
			translation text NOT NULL,
			status varchar(12) NOT NULL DEFAULT 'draft',
			updated_at datetime NOT NULL,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY string_language (source_hash,language),
			KEY language_status (language,status)
		) $collate;"
		);
		// Translation memory: sentence pairs per language, from translations that kept the original's structure.
		$memory = Memory::table();
		dbDelta(
			"CREATE TABLE $memory (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_hash char(40) NOT NULL,
			language varchar(35) NOT NULL,
			source text NOT NULL,
			target text NOT NULL,
			origin varchar(12) NOT NULL DEFAULT 'person',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_language (source_hash,language)
		) $collate;"
		);
		// The terms in every language (1.6.0): their own relations table.
		Terms::install();
		if ( ! self::verify_schema() ) {
			self::$available = false;
			return false;
		}
		// Read on every request: autoloaded, so it costs no query of its own.
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
		self::$available = true;
		return true;
	}

	/**
	 * On every request: the recorded schema version is enough (no query). The table itself is checked again only
	 * in the administration, where a missing table can be reported and repaired.
	 */
	public static function maybe_upgrade() {
		if ( self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '' ) || ( is_admin() && ! self::table_exists() ) ) {
			return self::install();
		}
		// Installs before 1.2 stored the version without autoload: fixed once (the check reads memory, no query).
		if ( function_exists( 'wp_set_option_autoload' ) && ! array_key_exists( self::SCHEMA_OPTION, wp_load_alloptions() ) ) {
			wp_set_option_autoload( self::SCHEMA_OPTION, true );
		}
		// The settings are read on every page too: sites up to 1.5.10 saved them without autoload.
		if ( function_exists( 'wp_set_option_autoload' ) && ! array_key_exists( Languages::OPTION, wp_load_alloptions() ) && false !== get_option( Languages::OPTION, false ) ) {
			wp_set_option_autoload( Languages::OPTION, true );
		}
		self::$available = true;
		return true;
	}

	public static function is_available() {
		if ( null === self::$available ) {
			self::$available = self::table_exists();
		}
		return self::$available;
	}

	private static function table_exists() {
		global $wpdb;
		$table = self::table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function verify_schema() {
		global $wpdb;
		$table = self::table();
		if ( ! self::table_exists() ) {
			return false;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
		$required_columns = array( 'object_id', 'group_uuid', 'language', 'source_id', 'source_hash', 'review_state', 'created_at', 'updated_at' );
		if ( ! is_array( $columns ) || array_diff( $required_columns, $columns ) ) {
			return false;
		}
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A );
		$index_columns = array();
		$index_non_unique = array();
		foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
			if ( ! isset( $index['Key_name'], $index['Column_name'], $index['Seq_in_index'], $index['Non_unique'] ) ) {
				continue;
			}
			$key = $index['Key_name'];
			$index_columns[ $key ][ absint( $index['Seq_in_index'] ) ] = $index['Column_name'];
			$index_non_unique[ $key ] = (int) $index['Non_unique'];
		}
		foreach ( $index_columns as &$columns ) {
			ksort( $columns );
			$columns = array_values( $columns );
		}
		unset( $columns );
		return isset( $index_columns['PRIMARY'], $index_columns['group_language'], $index_columns['source_id'], $index_columns['language_review'] )
			&& array( 'object_id' ) === $index_columns['PRIMARY']
			&& 0 === $index_non_unique['PRIMARY']
			&& array( 'group_uuid', 'language' ) === $index_columns['group_language']
			&& 0 === $index_non_unique['group_language']
			&& array( 'source_id' ) === $index_columns['source_id']
			&& array( 'language', 'review_state' ) === $index_columns['language_review'];
	}

	public static function find( $object_id ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return null;
		}
		$object_id = absint( $object_id );
		$cache_key = 'object_' . $object_id;
		$cached = wp_cache_get( $cache_key, 'lingua_bridge' );
		if ( false !== $cached ) {
			return $cached;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE object_id = %d', $object_id ), ARRAY_A );
		wp_cache_set( $cache_key, $row, 'lingua_bridge', 300 );
		return $row;
	}

	public static function group_rows( $group_uuid ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return array();
		}
		$group_uuid = sanitize_text_field( $group_uuid );
		$cache_key = 'group_' . md5( $group_uuid );
		$cached = wp_cache_get( $cache_key, 'lingua_bridge' );
		if ( false !== $cached ) {
			return $cached;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE group_uuid = %s ORDER BY language ASC', $group_uuid ), ARRAY_A );
		wp_cache_set( $cache_key, $rows, 'lingua_bridge', 300 );
		return $rows;
	}

	/**
	 * Loads in one go the relations of many contents and of their groups, into the same cache find() and
	 * group_rows() read: a list of 20 rows costs two queries instead of one per row and per language (P1 of the
	 * 1.5.10 verification). Returns the ids of every member of those groups, for _prime_post_caches().
	 *
	 * @return list<int>
	 */
	public static function prime( $object_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $object_ids ) ) ) );
		if ( ! $ids || ! self::is_available() ) {
			return array();
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		$rows = $wpdb->get_results( "SELECT * FROM $table WHERE object_id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$found[ absint( $row['object_id'] ) ] = $row;
		}
		foreach ( $ids as $id ) {
			wp_cache_set( 'object_' . $id, $found[ $id ] ?? null, 'lingua_bridge', 300 );
		}
		$groups = array_values( array_unique( array_column( $found, 'group_uuid' ) ) );
		if ( ! $groups ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $groups ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholders only.
		$members = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE group_uuid IN ($placeholders) ORDER BY language ASC", $groups ), ARRAY_A );
		$by_group = array();
		$member_ids = array();
		foreach ( is_array( $members ) ? $members : array() as $member ) {
			$by_group[ $member['group_uuid'] ][] = $member;
			$member_ids[] = absint( $member['object_id'] );
			wp_cache_set( 'object_' . absint( $member['object_id'] ), $member, 'lingua_bridge', 300 );
		}
		foreach ( $groups as $group ) {
			wp_cache_set( 'group_' . md5( $group ), $by_group[ $group ] ?? array(), 'lingua_bridge', 300 );
		}
		return array_values( array_unique( $member_ids ) );
	}

	public static function find_group_language( $group_uuid, $language ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE group_uuid = %s AND language = %s LIMIT 1',
				sanitize_text_field( $group_uuid ),
				sanitize_text_field( $language )
			),
			ARRAY_A
		);
	}

	public static function used_languages() {
		global $wpdb;
		$table = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $exists ) {
			return array();
		}
		$languages = $wpdb->get_col( "SELECT DISTINCT language FROM $table" );
		return is_array( $languages ) ? array_map( 'strval', $languages ) : array();
	}

	/** How many contents each language has (settings: a language in use cannot be removed). */
	public static function language_counts() {
		global $wpdb;
		if ( ! self::is_available() ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT language, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY language', ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['language'] ] = (int) $row['total'];
		}
		return $counts;
	}

	public static function insert( $row ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$data = array(
			'object_id'    => absint( $row['object_id'] ),
			'group_uuid'   => sanitize_text_field( $row['group_uuid'] ),
			'language'     => sanitize_text_field( $row['language'] ),
			'source_id'    => absint( $row['source_id'] ),
			'source_hash'  => sanitize_text_field( $row['source_hash'] ),
			'review_state' => sanitize_key( $row['review_state'] ),
			'created_at'   => $now,
			'updated_at'   => $now,
		);
		$result = $wpdb->insert( self::table(), $data, array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		self::clear_cache( $data['object_id'], $data['group_uuid'] );
		return false !== $result;
	}

	public static function insert_group( $rows ) {
		if ( ! self::is_available() || ! is_array( $rows ) || count( $rows ) < 2 ) {
			return false;
		}
		$objects = array();
		$languages = array();
		$group_uuid = '';
		foreach ( $rows as $row ) {
			$object_id = isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0;
			$language = isset( $row['language'] ) ? sanitize_text_field( $row['language'] ) : '';
			$row_group = isset( $row['group_uuid'] ) ? sanitize_text_field( $row['group_uuid'] ) : '';
			if ( ! $object_id || '' === $language || '' === $row_group || isset( $objects[ $object_id ] ) || isset( $languages[ $language ] ) ) {
				return false;
			}
			if ( '' === $group_uuid ) {
				$group_uuid = $row_group;
			}
			if ( $group_uuid !== $row_group || self::find( $object_id ) ) {
				return false;
			}
			$objects[ $object_id ] = true;
			$languages[ $language ] = true;
		}

		$inserted = array();
		foreach ( $rows as $row ) {
			if ( ! self::insert( $row ) ) {
				self::delete_imported_rows( $inserted, $group_uuid );
				return false;
			}
			$inserted[] = absint( $row['object_id'] );
		}
		return true;
	}

	public static function delete( $object_id ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return false;
		}
		$object_id = absint( $object_id );
		$row = self::find( $object_id );
		if ( ! $row ) {
			return true;
		}
		$result = $wpdb->delete( self::table(), array( 'object_id' => $object_id ), array( '%d' ) );
		if ( false === $result ) {
			return false;
		}
		self::clear_cache( $object_id, $row['group_uuid'] );
		return true;
	}

	private static function delete_imported_rows( $object_ids, $group_uuid ) {
		global $wpdb;
		$object_ids = array_values( array_filter( array_map( 'absint', $object_ids ) ) );
		if ( empty( $object_ids ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $object_ids ), '%d' ) );
		$query = 'DELETE FROM ' . self::table() . " WHERE group_uuid = %s AND object_id IN ($placeholders)";
		$wpdb->query( $wpdb->prepare( $query, array_merge( array( sanitize_text_field( $group_uuid ) ), $object_ids ) ) );
		foreach ( $object_ids as $object_id ) {
			self::clear_cache( $object_id, $group_uuid );
		}
	}

	public static function ensure_source( $object_id, $language, $source_hash ) {
		$row = self::find( $object_id );
		if ( $row ) {
			return $row;
		}
		$group_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'lingua-', true );
		$inserted = self::insert(
			array(
				'object_id'    => $object_id,
				'group_uuid'   => $group_uuid,
				'language'     => $language,
				'source_id'    => $object_id,
				'source_hash'  => $source_hash,
				'review_state' => 'source',
			)
		);
		if ( ! $inserted ) {
			return self::find( $object_id );
		}
		return self::find( $object_id );
	}

	public static function update_review_state( $object_id, $state ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return;
		}
		$row = self::find( $object_id );
		$wpdb->update(
			self::table(),
			array(
				'review_state' => sanitize_key( $state ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'object_id' => absint( $object_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( $row ) {
			self::clear_cache( $object_id, $row['group_uuid'] );
			// C26: whoever the translation is assigned to hears of it.
			if ( $row['review_state'] !== sanitize_key( $state ) ) {
				do_action( 'lingua_bridge_review_state', absint( $object_id ), sanitize_key( $state ) );
			}
		}
	}

	/** The original's version a translation follows now (it is up to date again). */
	public static function update_source_hash( $object_id, $source_hash ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return;
		}
		$row = self::find( $object_id );
		$wpdb->update( self::table(), array( 'source_hash' => sanitize_text_field( $source_hash ), 'updated_at' => current_time( 'mysql', true ) ), array( 'object_id' => absint( $object_id ) ), array( '%s', '%s' ), array( '%d' ) );
		if ( $row ) {
			self::clear_cache( $object_id, $row['group_uuid'] );
		}
	}

	public static function update_group_source( $group_uuid, $source_id, $source_hash ) {
		global $wpdb;
		if ( ! self::is_available() ) {
			return false;
		}
		$rows = self::group_rows( $group_uuid );
		$result = $wpdb->update(
			self::table(),
			array(
				'source_id'   => absint( $source_id ),
				'source_hash' => sanitize_text_field( $source_hash ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'group_uuid' => sanitize_text_field( $group_uuid ) ),
			array( '%d', '%s', '%s' ),
			array( '%s' )
		);
		foreach ( $rows as $row ) {
			self::clear_cache( $row['object_id'], $group_uuid );
		}
		return false !== $result;
	}

	public static function clear_cache( $object_id, $group_uuid ) {
		wp_cache_delete( 'object_' . absint( $object_id ), 'lingua_bridge' );
		wp_cache_delete( 'group_' . md5( $group_uuid ), 'lingua_bridge' );
		self::content_changed();
	}

	/** Something translations depend on changed (a saved post, a link). */
	public static function content_changed() {
		update_option( self::CONTENT_VERSION_OPTION, absint( get_option( self::CONTENT_VERSION_OPTION, 0 ) ) + 1, false );
	}
}
