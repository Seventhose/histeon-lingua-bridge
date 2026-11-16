<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menus on a page in another language (docs/STRINGS.md): a link to a content goes to its published translation
 * and takes its title; a custom label is a `menu` string; a link to a list (home, term, archive) goes to the
 * same list in the language. Classic menus and the navigation block alike. Without a translation, the link stays.
 */
final class Menus {
	public static function init() {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'wp_setup_nav_menu_item', array( __CLASS__, 'item' ) );
		add_filter( 'theme_mod_nav_menu_locations', array( __CLASS__, 'locations' ) );
		add_filter( 'render_block_data', array( __CLASS__, 'navigation_link' ) );
	}

	/** The published translation of a content in a language, or 0. */
	public static function translation( $post_id, $language ) {
		$row = Storage::find( absint( $post_id ) );
		if ( ! $row ) {
			return 0;
		}
		if ( $language === $row['language'] ) {
			return absint( $post_id );
		}
		$member = Storage::find_group_language( $row['group_uuid'], $language );
		$post = $member ? get_post( absint( $member['object_id'] ) ) : null;
		// The same rule as the switcher and hreflang: a variation is online only when its product is.
		return Frontend::online( $post ) ? absint( $post->ID ) : 0;
	}

	/**
	 * C32: a language with its own menu in a place of the theme (`language_menus`, as Polylang and WPML had them):
	 * on its pages that place shows that menu. Without one, the original's menu, translated item by item.
	 */
	public static function locations( $locations ) {
		$language = Frontend::current_language();
		$own = (array) ( ( Languages::settings()['language_menus'] ?? array() )[ $language ] ?? array() );
		if ( Languages::default_code() === $language || empty( $own ) || ! is_array( $locations ) ) {
			return $locations;
		}
		foreach ( $own as $place => $menu ) {
			if ( is_nav_menu( absint( $menu ) ) ) {
				$locations[ $place ] = absint( $menu );
			}
		}
		return $locations;
	}

	public static function item( $item ) {
		$language = Strings::language();
		if ( '' === $language || ! is_object( $item ) || empty( $item->ID ) ) {
			return $item;
		}
		$custom_label = '' !== trim( (string) get_post_field( 'post_title', $item->ID, 'raw' ) );
		if ( 'post_type' === ( $item->type ?? '' ) && ! empty( $item->object_id ) ) {
			$translation = self::translation( $item->object_id, $language );
			if ( $translation ) {
				$item->url = (string) get_permalink( $translation );
				if ( ! $custom_label ) {
					$item->title = get_the_title( $translation );
				}
			}
		} elseif ( 'taxonomy' === ( $item->type ?? '' ) && ! empty( $item->object_id ) && Terms::translation( $item->object_id, $language ) && Terms::translation( $item->object_id, $language ) !== absint( $item->object_id ) ) {
			// A translated term: its own address and, unless the item has its own label, its name.
			$translated = get_term( Terms::translation( $item->object_id, $language ) );
			if ( $translated instanceof \WP_Term ) {
				$item->url = (string) get_term_link( $translated );
				if ( ! $custom_label ) {
					$item->title = $translated->name;
				}
			}
		} elseif ( 'taxonomy' === ( $item->type ?? '' ) || 'post_type_archive' === ( $item->type ?? '' ) ) {
			$item->url = (string) Routing::language_url( (string) $item->url, $language );
		} elseif ( 'custom' === ( $item->type ?? '' ) && untrailingslashit( (string) $item->url ) === untrailingslashit( home_url( '/' ) ) ) {
			$home = Routing::translated_home_id( $language );
			$item->url = $home ? (string) get_permalink( $home ) : (string) Routing::language_url( home_url( '/' ), $language );
		}
		if ( $custom_label ) {
			$label = Strings::translate( 'menu', (string) $item->title, $language );
			if ( null !== $label ) {
				$item->title = $label;
			}
		}
		return $item;
	}

	public static function navigation_link( $block ) {
		if ( ! in_array( $block['blockName'] ?? '', array( 'core/navigation-link', 'core/navigation-submenu' ), true ) ) {
			return $block;
		}
		$language = Strings::language();
		if ( '' === $language ) {
			return $block;
		}
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$label = isset( $attrs['label'] ) ? (string) $attrs['label'] : '';
		$id = isset( $attrs['id'] ) ? absint( $attrs['id'] ) : 0;
		if ( $id && 'post-type' === ( $attrs['kind'] ?? '' ) ) {
			$translation = self::translation( $id, $language );
			if ( $translation && $translation !== $id ) {
				$attrs['id'] = $translation;
				$attrs['url'] = (string) get_permalink( $translation );
				// get_the_title() goes through wptexturize (’ for '): compare the raw title too.
				if ( in_array( html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, 'UTF-8' ), array( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), (string) get_post_field( 'post_title', $id, 'raw' ) ), true ) ) {
					$attrs['label'] = esc_html( get_the_title( $translation ) );
					$label = '';
				}
			}
		}
		if ( '' !== $label ) {
			$translated = Strings::translate( 'menu', wp_strip_all_tags( $label ), $language );
			if ( null !== $translated ) {
				$attrs['label'] = esc_html( $translated );
			}
		}
		$block['attrs'] = $attrs;
		return $block;
	}
}
