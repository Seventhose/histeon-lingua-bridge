<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C14 of the competition list (1.9.0): ACF and Secure Custom Fields in full.
 *
 * - The texts inside repeaters, flexible contents and groups go to translation one by one (provider `acf_sub`, key
 *   `parent field key|row|sub field key`, nested as deep as the fields are) and come back into the same rows: the
 *   structure of the translation stays the original's.
 * - Options pages have a version per language, kept by ACF itself as `options_<language>` through its own setting
 *   `current_language`: the mechanism ACF offers to multilingual plugins, no format of Lingua Bridge. A language
 *   without its own value reads the original's. On an options page, the language is chosen with `lb_lang`.
 */
final class Acf {
	const TEXT_TYPES = array( 'text', 'textarea', 'wysiwyg' );
	const CONTAINERS = array( 'repeater', 'flexible_content', 'group' );

	public static function init() {
		add_filter( 'acf/settings/default_language', array( __CLASS__, 'default_language' ) );
		add_filter( 'acf/settings/current_language', array( __CLASS__, 'current_language' ) );
		add_filter( 'acf/load_value', array( __CLASS__, 'options_fallback' ), 5, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'options_languages' ) );
	}

	private static function active() {
		// WPML and Polylang set ACF's language themselves.
		return function_exists( 'acf_get_field' ) && ! defined( 'ICL_SITEPRESS_VERSION' ) && ! defined( 'POLYLANG_VERSION' );
	}

	public static function default_language( $language ) {
		return self::active() ? Languages::default_code() : $language;
	}

	/** On the site the page's language; on an options page of the administration the one chosen with `lb_lang`. */
	public static function current_language( $language ) {
		if ( ! self::active() ) {
			return $language;
		}
		if ( is_admin() ) {
			$chosen = isset( $_GET['lb_lang'] ) ? sanitize_key( wp_unslash( $_GET['lb_lang'] ) ) : ( isset( $_POST['lb_lang'] ) ? sanitize_key( wp_unslash( $_POST['lb_lang'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification -- which version of the options page is shown; ACF checks its own nonce on save.
			return isset( Languages::all()[ $chosen ] ) ? $chosen : Languages::default_code();
		}
		return Frontend::current_language();
	}

	/** An options field of a language that has no value of its own reads the original's. */
	public static function options_fallback( $value, $post_id, $field ) {
		if ( ( null === $value || false === $value || '' === $value ) && is_string( $post_id ) && preg_match( '/\Aoptions_([a-z0-9-]+)\z/', $post_id, $match ) && isset( Languages::all()[ $match[1] ] ) && function_exists( 'acf_get_metadata' ) && is_array( $field ) && ! empty( $field['name'] ) ) {
			$original = acf_get_metadata( 'options', $field['name'] );
			return null !== $original ? $original : $value;
		}
		return $value;
	}

	/** On an ACF options page: one link per language, the one shown marked. */
	public static function options_languages() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::active() || ! $screen || '' === $page || ! function_exists( 'acf_get_options_page' ) || ! acf_get_options_page( $page ) || count( Languages::all() ) < 2 ) {
			return;
		}
		$current = self::current_language( '' );
		$links = array();
		foreach ( Languages::all() as $code => $label ) {
			$url = add_query_arg( array( 'page' => $page, 'lb_lang' => $code ), admin_url( 'admin.php' ) );
			$links[] = $code === $current ? '<strong aria-current="page">' . esc_html( $label ) . '</strong>' : '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Lingua di queste opzioni:', 'lingua-bridge' ) . ' ' . implode( ' · ', $links ) . '</p><p class="description">' . esc_html__( 'Ogni lingua ha la sua versione; finché non la salvi, una lingua mostra le opzioni dell’originale.', 'lingua-bridge' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var f=document.querySelector("form#post");if(f){var i=document.createElement("input");i.type="hidden";i.name="lb_lang";i.value=' . wp_json_encode( $current ) . ';f.appendChild(i);}});</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON-encoded code.
	}

	/**
	 * The texts inside the repeaters, flexible contents and groups of a post, one field each.
	 *
	 * @return list<array{provider: string, key: string, label: string, type: string, value: string}>
	 */
	public static function sub_fields( $post_id ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) || ! function_exists( 'get_field' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_field_groups( array( 'post_id' => absint( $post_id ) ) ) as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( is_array( $field ) && in_array( $field['type'] ?? '', self::CONTAINERS, true ) && 'ignore' !== Sync::mode( (string) ( $field['name'] ?? '' ) ) ) {
					self::collect( $field, get_field( $field['key'], absint( $post_id ), false ), array( $field['key'] ), (string) ( $field['label'] ?? $field['name'] ), $out );
				}
			}
		}
		return $out;
	}

	/** The text sub fields of `$value` (the raw value of `$field`), with their path. */
	private static function collect( $field, $value, $path, $label, &$out ) {
		$subs = self::sub_definitions( $field );
		if ( 'group' === $field['type'] ) {
			$rows = array( '' => $value );
		} else {
			$rows = is_array( $value ) ? $value : array();
		}
		foreach ( $rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$layout = (string) ( $row['acf_fc_layout'] ?? '' );
			foreach ( $subs as $sub ) {
				if ( '' !== $layout && isset( $sub['parent_layout'] ) && ! self::in_layout( $field, $sub, $layout ) ) {
					continue;
				}
				$sub_value = $row[ $sub['key'] ] ?? null;
				$sub_path = array_merge( $path, 'group' === $field['type'] ? array() : array( (string) $index ), array( $sub['key'] ) );
				$sub_label = $label . ' › ' . ( $sub['label'] ?? $sub['name'] ) . ( 'group' === $field['type'] ? '' : ' ' . ( (int) $index + 1 ) );
				if ( in_array( $sub['type'] ?? '', self::TEXT_TYPES, true ) && is_string( $sub_value ) && '' !== trim( $sub_value ) ) {
					$out[] = array( 'provider' => 'acf_sub', 'key' => implode( '|', $sub_path ), 'label' => $sub_label, 'type' => (string) $sub['type'], 'value' => $sub_value );
				} elseif ( in_array( $sub['type'] ?? '', self::CONTAINERS, true ) ) {
					self::collect( $sub, $sub_value, $sub_path, $sub_label, $out );
				}
			}
		}
	}

	/** The sub fields of a container, the layouts' ones for a flexible content. */
	private static function sub_definitions( $field ) {
		if ( 'flexible_content' === ( $field['type'] ?? '' ) ) {
			$subs = array();
			foreach ( (array) ( $field['layouts'] ?? array() ) as $layout ) {
				foreach ( (array) ( $layout['sub_fields'] ?? array() ) as $sub ) {
					$sub['parent_layout'] = (string) ( $layout['key'] ?? '' );
					$sub['layout_name'] = (string) ( $layout['name'] ?? '' );
					$subs[] = $sub;
				}
			}
			return $subs;
		}
		return array_values( array_filter( (array) ( $field['sub_fields'] ?? array() ), 'is_array' ) );
	}

	private static function in_layout( $field, $sub, $layout ) {
		return ( $sub['layout_name'] ?? '' ) === $layout;
	}

	/**
	 * Writes a translated sub field into the same row of the translation. The path must be one of the original's
	 * text sub fields, from a field of the original's groups: nothing else is written.
	 */
	public static function save_sub( $post_id, $path, $text, $source_id ) {
		if ( ! function_exists( 'update_field' ) || ! is_string( $text ) ) {
			return false;
		}
		$allowed = wp_list_pluck( self::sub_fields( $source_id ), 'type', 'key' );
		if ( ! isset( $allowed[ $path ] ) ) {
			return false;
		}
		$segments = explode( '|', (string) $path );
		$parent = array_shift( $segments );
		$value = get_field( $parent, absint( $post_id ), false );
		$field = acf_get_field( $parent );
		$value = self::set_at( $field, is_array( $value ) ? $value : array(), $segments, 'wysiwyg' === $allowed[ $path ] ? wp_kses_post( $text ) : ( 'textarea' === $allowed[ $path ] ? sanitize_textarea_field( $text ) : sanitize_text_field( $text ) ) );
		return (bool) update_field( $parent, $value, absint( $post_id ) );
	}

	/** `$value` with the text at `$segments` (row index and sub key, nested) replaced. */
	private static function set_at( $field, $value, $segments, $text ) {
		if ( 'group' === ( $field['type'] ?? '' ) ) {
			$key = array_shift( $segments );
			$value[ $key ] = $segments ? self::set_at( self::find_sub( $field, $key ), is_array( $value[ $key ] ?? null ) ? $value[ $key ] : array(), $segments, $text ) : $text;
			return $value;
		}
		$index = array_shift( $segments );
		$key = array_shift( $segments );
		if ( ! isset( $value[ $index ] ) || ! is_array( $value[ $index ] ) ) {
			return $value;
		}
		$value[ $index ][ $key ] = $segments ? self::set_at( self::find_sub( $field, $key ), is_array( $value[ $index ][ $key ] ?? null ) ? $value[ $index ][ $key ] : array(), $segments, $text ) : $text;
		return $value;
	}

	private static function find_sub( $field, $key ) {
		foreach ( self::sub_definitions( $field ) as $sub ) {
			if ( ( $sub['key'] ?? '' ) === $key ) {
				return $sub;
			}
		}
		return array();
	}
}
