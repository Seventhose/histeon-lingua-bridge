<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C13 and C15 of the competition list (1.9.0): what each custom field does in a translation, and the data that is
 * no text kept the same as the original's.
 *
 * - Field modes (`field_modes`, key => mode): `translate` (sent to translation, as the keys of
 *   `translatable_meta_keys`), `copy` (copied at creation and again whenever the original is saved), `copy_once`
 *   (copied at creation, then left alone: what every public field did before), `ignore` (never copied: the
 *   translation keeps its own, or none).
 * - Synchronization (`sync`, off by default): when an original is saved, its featured image, date, parent page,
 *   template, order, comment status, terms (through the translated ones) and the fields in `copy` reach every
 *   translation, published ones too: none of these is text, so the rule of drafts is untouched. Each translation is
 *   changed only if the person saving may edit it.
 */
final class Sync {
	const MODES = array( 'translate', 'copy', 'copy_once', 'ignore' );
	const DATA = array( 'thumbnail', 'date', 'parent', 'template', 'menu_order', 'comments', 'terms' );

	private static $running = false;

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'original_saved' ), 40, 2 );
	}

	/** @return array<string, string> meta key => mode, only valid ones. */
	public static function field_modes() {
		$modes = (array) ( Languages::settings()['field_modes'] ?? array() );
		$out = array();
		foreach ( $modes as $key => $mode ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && in_array( $mode, self::MODES, true ) ) {
				$out[ $key ] = (string) $mode;
			}
		}
		return $out;
	}

	/** The mode of one field: its own, else `translate` for the keys listed to translate, else `copy_once`. */
	public static function mode( $key ) {
		$modes = self::field_modes();
		if ( isset( $modes[ $key ] ) ) {
			return $modes[ $key ];
		}
		return in_array( $key, (array) ( Languages::settings()['translatable_meta_keys'] ?? array() ), true ) ? 'translate' : 'copy_once';
	}

	/** @return string[] The data kept in sync with the original (none by default). */
	public static function synced() {
		return array_values( array_intersect( self::DATA, (array) ( Languages::settings()['sync'] ?? array() ) ) );
	}

	/**
	 * The custom fields of the translatable contents, for the table of the settings: the 60 most used public meta keys,
	 * without those of Lingua Bridge, of the SEO plugins (translated by themselves) and of WordPress.
	 *
	 * @return array<string, int> key => how many contents have it
	 */
	public static function known_fields() {
		global $wpdb;
		$types = Translations::translatable_types();
		if ( empty( $types ) ) {
			return array();
		}
		$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- placeholders only; a settings screen.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_key, COUNT(*) AS n FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ($in) AND m.meta_key NOT LIKE %s GROUP BY m.meta_key ORDER BY n DESC LIMIT 80", array_merge( $types, array( $wpdb->esc_like( '_' ) . '%' ) ) ), ARRAY_A );
		$seo = array_map( static fn( $key ) => (string) strtok( (string) $key, ':' ), array_keys( Fields::seo_fields() ) );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = (string) $row['meta_key'];
			if ( sanitize_key( $key ) !== $key || in_array( $key, $seo, true ) || 0 === strpos( $key, 'lingua_bridge' ) ) {
				continue;
			}
			$out[ $key ] = (int) $row['n'];
			if ( count( $out ) >= 60 ) {
				break;
			}
		}
		return $out;
	}

	/** An original saved: what is chosen to stay the same goes to its translations. */
	public static function original_saved( $post_id, $post ) {
		if ( self::$running || ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$synced = self::synced();
		$copy_fields = array_keys( array_filter( self::field_modes(), static fn( $mode ) => 'copy' === $mode ) );
		if ( empty( $synced ) && empty( $copy_fields ) ) {
			return;
		}
		$row = Storage::find( $post_id );
		if ( ! $row || Languages::default_code() !== $row['language'] ) {
			return;
		}
		self::$running = true;
		try {
			foreach ( Storage::group_rows( $row['group_uuid'] ) as $member ) {
				$id = absint( $member['object_id'] );
				if ( $id !== absint( $post_id ) && get_post_type( $id ) === $post->post_type && current_user_can( 'edit_post', $id ) ) {
					self::apply( $post, $id, (string) $member['language'], $synced, $copy_fields );
				}
			}
		} finally {
			self::$running = false;
		}
	}

	/** What `$synced` names, from the original `$source` to its translation `$target` in `$language`. */
	public static function apply( \WP_Post $source, $target, $language, $synced, $copy_fields ) {
		$update = array();
		if ( in_array( 'date', $synced, true ) ) {
			$update['post_date'] = $source->post_date;
			$update['post_date_gmt'] = $source->post_date_gmt;
		}
		if ( in_array( 'menu_order', $synced, true ) ) {
			$update['menu_order'] = $source->menu_order;
		}
		if ( in_array( 'comments', $synced, true ) ) {
			$update['comment_status'] = $source->comment_status;
			$update['ping_status'] = $source->ping_status;
		}
		if ( in_array( 'parent', $synced, true ) ) {
			// The parent page of the translation: the parent's version in the same language, if it has one.
			$parent = $source->post_parent ? Storage::find( $source->post_parent ) : null;
			$version = $parent ? Storage::find_group_language( $parent['group_uuid'], $language ) : null;
			$update['post_parent'] = $source->post_parent ? ( $version ? absint( $version['object_id'] ) : absint( $source->post_parent ) ) : 0;
		}
		if ( $update ) {
			$update['ID'] = absint( $target );
			wp_update_post( wp_slash( $update ) );
		}
		if ( in_array( 'thumbnail', $synced, true ) ) {
			$thumbnail = absint( get_post_thumbnail_id( $source ) );
			if ( $thumbnail ) {
				set_post_thumbnail( $target, $thumbnail );
			} else {
				delete_post_thumbnail( $target );
			}
		}
		if ( in_array( 'template', $synced, true ) ) {
			$template = (string) get_post_meta( $source->ID, '_wp_page_template', true );
			if ( '' !== $template ) {
				update_post_meta( $target, '_wp_page_template', $template );
			} else {
				delete_post_meta( $target, '_wp_page_template' );
			}
		}
		if ( in_array( 'terms', $synced, true ) ) {
			foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
				if ( in_array( $taxonomy, array( 'post_translations', 'language' ), true ) ) {
					continue;
				}
				$ids = wp_get_object_terms( $source->ID, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $ids ) ) {
					continue;
				}
				$mapped = array();
				foreach ( $ids as $term_id ) {
					$translated = class_exists( __NAMESPACE__ . '\\Terms' ) ? Terms::translation( $term_id, $language ) : 0;
					$mapped[] = $translated ? absint( $translated ) : absint( $term_id );
				}
				wp_set_object_terms( $target, $mapped, $taxonomy );
			}
		}
		if ( $copy_fields ) {
			foreach ( $copy_fields as $key ) {
				if ( metadata_exists( 'post', $source->ID, $key ) ) {
					update_post_meta( $target, $key, wp_slash( get_post_meta( $source->ID, $key, true ) ) );
				} else {
					delete_post_meta( $target, $key );
				}
			}
		}
	}
}
