<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strings from another multilingual plugin (docs/STRINGS.md): what Polylang, WPML or TranslatePress translated
 * outside contents (site title, widgets, texts of the theme and plugins) becomes Lingua Bridge strings. Read from
 * the database, so it works also after the other plugin is deactivated. Nothing of theirs is changed.
 *
 * Done by a person there: published here. Done by a machine (TranslatePress) or not complete (WPML): drafts.
 */
final class StringImport {
	const MAX_ROWS = 3000;

	public static function init() {
		add_action( 'admin_post_lingua_bridge_import_strings', array( __CLASS__, 'handle' ) );
	}

	/** What each source has, by what its data looks like in the database. */
	public static function detect() {
		global $wpdb;
		$out = array( 'polylang' => 0, 'wpml' => 0, 'translatepress' => 0 );
		$out['polylang'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'polylang_mo'" );
		if ( self::table_exists( $wpdb->prefix . 'icl_string_translations' ) && self::table_exists( $wpdb->prefix . 'icl_strings' ) ) {
			$out['wpml'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}icl_string_translations WHERE value IS NOT NULL AND value <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
		}
		$out['translatepress'] = count( self::translatepress_tables() );
		return $out;
	}

	public static function handle() {
		check_admin_referer( 'lingua_bridge_import_strings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$stats = self::run( $source );
		$message = is_wp_error( $stats ) ? $stats->get_error_message() : sprintf(
			/* translators: 1: published strings, 2: drafts, 3: skipped. */
			__( 'Stringhe importate: %1$d pubblicate, %2$d bozze da approvare, %3$d saltate (lingua non configurata, testo dei contenuti o già tradotte qui).', 'lingua-bridge' ),
			$stats['published'],
			$stats['drafts'],
			$stats['skipped']
		);
		set_transient( 'lingua_bridge_import_strings_' . get_current_user_id(), $message, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lingua-bridge-import', 'lb_strings' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** The last result, once. */
	public static function last_message() {
		$key = 'lingua_bridge_import_strings_' . get_current_user_id();
		$message = get_transient( $key );
		delete_transient( $key );
		return is_string( $message ) ? $message : '';
	}

	/**
	 * Imports the strings of one source.
	 *
	 * @return array{published: int, drafts: int, skipped: int}|\WP_Error
	 */
	public static function run( $source ) {
		$stats = array( 'published' => 0, 'drafts' => 0, 'skipped' => 0 );
		if ( 'polylang' === $source ) {
			foreach ( self::polylang_pairs() as $pair ) {
				self::add( $pair, $stats );
			}
		} elseif ( 'wpml' === $source ) {
			foreach ( self::wpml_pairs() as $pair ) {
				self::add( $pair, $stats );
			}
		} elseif ( 'translatepress' === $source ) {
			$content = self::content_texts();
			foreach ( self::translatepress_pairs() as $pair ) {
				if ( isset( $content[ sha1( Strings::normalize( $pair['source'] ) ) ] ) ) {
					++$stats['skipped'];
					continue;
				}
				self::add( $pair, $stats );
			}
		} else {
			return new \WP_Error( 'lingua_import_strings_source', __( 'Sorgente sconosciuta.', 'lingua-bridge' ) );
		}
		return $stats;
	}

	/** One translation in: registered for every language, saved for its own (a published one here is kept). */
	private static function add( $pair, &$stats ) {
		global $wpdb;
		$language = self::language( $pair['language'] );
		$source = Strings::normalize( $pair['source'] );
		$translation = Strings::clean( $pair['translation'] );
		if ( '' === $language || '' === $source || '' === $translation || mb_strlen( $source ) > Strings::MAX_LENGTH ) {
			++$stats['skipped'];
			return;
		}
		$context = in_array( $source, array( Strings::normalize( get_option( 'blogname', '' ) ), Strings::normalize( get_option( 'blogdescription', '' ) ) ), true ) ? 'option' : 'html';
		Strings::register( $context, $source );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status FROM ' . Strings::table() . ' WHERE language = %s AND source_hash = %s', $language, Strings::key( $context, $source ) ), ARRAY_A );
		if ( ! $row || 'published' === $row['status'] ) {
			++$stats['skipped'];
			return;
		}
		Strings::save( absint( $row['id'] ), $translation, $pair['published'] ? 'published' : 'draft' );
		++$stats[ $pair['published'] ? 'published' : 'drafts' ];
	}

	/** A code of another plugin (en, en_US, en-gb) as one of the site's languages, or ''. */
	public static function language( $code ) {
		$code = strtolower( str_replace( '_', '-', trim( (string) $code ) ) );
		$languages = Languages::all();
		$found = isset( $languages[ $code ] ) ? $code : ( isset( $languages[ strtok( $code, '-' ) ] ) ? strtok( $code, '-' ) : '' );
		return Languages::default_code() === $found ? '' : $found;
	}

	/** Polylang: a `polylang_mo_<term id>` post per language, the pairs in its meta. */
	private static function polylang_pairs() {
		global $wpdb;
		$pairs = array();
		$posts = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'polylang_mo'", ARRAY_A );
		foreach ( is_array( $posts ) ? $posts : array() as $post ) {
			if ( ! preg_match( '/\Apolylang_mo_(\d+)\z/', $post['post_title'], $m ) ) {
				continue;
			}
			$slug = $wpdb->get_var( $wpdb->prepare( "SELECT t.slug FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = 'language'", absint( $m[1] ) ) );
			// get_post_meta() already unserializes; a value still serialized is read without objects.
			$strings = get_post_meta( absint( $post['ID'] ), '_pll_strings_translations', true );
			if ( is_string( $strings ) && is_serialized( $strings ) ) {
				$strings = @unserialize( $strings, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions -- no objects allowed.
			}
			foreach ( is_array( $strings ) && $slug ? $strings : array() as $entry ) {
				if ( is_array( $entry ) && isset( $entry[0], $entry[1] ) && is_string( $entry[0] ) && is_string( $entry[1] ) ) {
					$pairs[] = array( 'source' => $entry[0], 'translation' => $entry[1], 'language' => $slug, 'published' => true );
				}
				if ( count( $pairs ) >= self::MAX_ROWS ) {
					break 2;
				}
			}
		}
		return $pairs;
	}

	/** WPML: icl_strings and their translations; complete (status 10) published, the rest drafts. */
	private static function wpml_pairs() {
		global $wpdb;
		if ( ! self::table_exists( $wpdb->prefix . 'icl_string_translations' ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.value AS source, t.value AS translation, t.language, t.status FROM {$wpdb->prefix}icl_string_translations t INNER JOIN {$wpdb->prefix}icl_strings s ON s.id = t.string_id WHERE t.value IS NOT NULL AND t.value <> '' LIMIT %d", self::MAX_ROWS ), ARRAY_A );
		$pairs = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$pairs[] = array( 'source' => (string) $row['source'], 'translation' => (string) $row['translation'], 'language' => (string) $row['language'], 'published' => 10 === (int) $row['status'] );
		}
		return $pairs;
	}

	/** TranslatePress: its dictionary and gettext tables; human (status 2) published, machine (1) drafts. */
	private static function translatepress_pairs() {
		global $wpdb;
		$pairs = array();
		foreach ( self::translatepress_tables() as $table => $locale ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a table name matched by translatepress_tables().
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT original, translated, status FROM `$table` WHERE translated IS NOT NULL AND translated <> '' AND status IN (1, 2) LIMIT %d", self::MAX_ROWS ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$pairs[] = array( 'source' => wp_strip_all_tags( (string) $row['original'] ), 'translation' => (string) $row['translated'], 'language' => $locale, 'published' => 2 === (int) $row['status'] );
			}
		}
		return $pairs;
	}

	/** trp_dictionary_<source>_<target> and trp_gettext_<target> tables, with the target locale of each. */
	private static function translatepress_tables() {
		global $wpdb;
		$tables = array();
		$like = $wpdb->esc_like( $wpdb->prefix . 'trp_' ) . '%';
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) as $table ) {
			if ( preg_match( '/\A' . preg_quote( $wpdb->prefix, '/' ) . 'trp_dictionary_([a-z]{2,3}(?:_[a-z0-9]+)?)_([a-z]{2,3}(?:_[a-z0-9]+)?)\z/i', $table, $m ) ) {
				$tables[ $table ] = $m[2];
			} elseif ( preg_match( '/\A' . preg_quote( $wpdb->prefix, '/' ) . 'trp_gettext_([a-z]{2,3}(?:_[a-z0-9]+)?)\z/i', $table, $m ) ) {
				$tables[ $table ] = $m[1];
			}
		}
		foreach ( array_keys( $tables ) as $table ) {
			$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a matched table name.
			if ( array_diff( array( 'original', 'translated', 'status' ), $columns ) ) {
				unset( $tables[ $table ] );
			}
		}
		return $tables;
	}

	/** The texts of the published contents in the original language (they are translated as pages, not strings). */
	private static function content_texts() {
		$texts = array();
		$offset = 0;
		do {
			$ids = get_posts( array( 'post_type' => Admin::editable_post_types(), 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 200, 'offset' => $offset, 'suppress_filters' => true, 'orderby' => 'ID' ) );
			foreach ( $ids as $id ) {
				$row = Storage::find( $id );
				if ( $row && Languages::default_code() !== $row['language'] ) {
					continue;
				}
				$post = get_post( $id );
				$texts[ sha1( Strings::normalize( $post->post_title ) ) ] = true;
				foreach ( Xliff::segment( $post->post_content )['segments'] as $segment ) {
					$texts[ sha1( Strings::normalize( $segment['text'] ) ) ] = true;
				}
			}
			$offset += 200;
		} while ( count( $ids ) === 200 && $offset < 20000 );
		return $texts;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
