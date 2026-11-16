<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C16 of the competition list (1.9.0): the template parts of a block theme (header, footer) are translatable
 * contents, as Etch components are. A translated part is a normal `wp_template_part` of the same theme and area,
 * with a slug of its own (`header-en`: WordPress finds a part by theme and slug, so two parts may never share one).
 * On a page of that language the template-part block draws the published translation instead of the original.
 *
 * Only parts saved in the database (edited in the Site Editor) are contents; a part that is still only a file of the
 * theme is translated through the strings. Templates (`wp_template`) are not: they hold the layout, and their texts
 * come from parts, patterns and contents.
 */
final class TemplateParts {
	const TYPE = 'wp_template_part';

	/** @var array<string, string> original slug:language => slug to draw, per request. */
	private static $swaps = array();

	public static function init() {
		add_filter( 'lingua_bridge_post_types', array( __CLASS__, 'translatable' ) );
		add_action( 'lingua_bridge_translation_created', array( __CLASS__, 'translation_created' ), 15, 3 );
		add_filter( 'render_block_data', array( __CLASS__, 'swap' ), 10, 2 );
	}

	/** The template parts are translatable, as `wp_block` and `wp_navigation` are. */
	public static function translatable( $types ) {
		$types = is_array( $types ) ? $types : array();
		if ( post_type_exists( self::TYPE ) && ! in_array( self::TYPE, $types, true ) ) {
			$types[] = self::TYPE;
		}
		return $types;
	}

	/** A translated part belongs to the same theme and area as its original, with its own slug. */
	public static function translation_created( $target_id, $source_id, $language ) {
		$source = get_post( $source_id );
		if ( ! $source || self::TYPE !== $source->post_type ) {
			return;
		}
		foreach ( array( 'wp_theme', 'wp_template_part_area' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( absint( $target_id ), $terms, $taxonomy );
			}
		}
		wp_update_post( array( 'ID' => absint( $target_id ), 'post_name' => sanitize_title( $source->post_name . '-' . $language ) ) );
	}

	/** On a page in another language, the template-part block draws the published part of that language. */
	public static function swap( $parsed_block, $source_block = null ) {
		if ( is_admin() || 'core/template-part' !== ( $parsed_block['blockName'] ?? '' ) || empty( $parsed_block['attrs']['slug'] ) ) {
			return $parsed_block;
		}
		$language = Frontend::current_language();
		if ( Languages::default_code() === $language ) {
			return $parsed_block;
		}
		$slug = (string) $parsed_block['attrs']['slug'];
		$theme = (string) ( $parsed_block['attrs']['theme'] ?? get_stylesheet() );
		$key = $theme . '//' . $slug . ':' . $language;
		if ( ! isset( self::$swaps[ $key ] ) ) {
			self::$swaps[ $key ] = '';
			$template = function_exists( 'get_block_template' ) ? get_block_template( $theme . '//' . $slug, self::TYPE ) : null;
			$row = $template && ! empty( $template->wp_id ) ? Storage::find( absint( $template->wp_id ) ) : null;
			$variant = $row ? Storage::find_group_language( $row['group_uuid'], $language ) : null;
			$post = $variant ? get_post( absint( $variant['object_id'] ) ) : null;
			if ( $post && self::TYPE === $post->post_type && 'publish' === $post->post_status ) {
				self::$swaps[ $key ] = (string) $post->post_name;
			}
		}
		if ( '' !== self::$swaps[ $key ] ) {
			$parsed_block['attrs']['slug'] = self::$swaps[ $key ];
		}
		return $parsed_block;
	}
}
