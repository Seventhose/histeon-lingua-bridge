<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C22 and C38 of the competition list (1.10.0): the language of what a visitor sends, and the messages it causes.
 *
 * - A form sent from a page in a language reaches WordPress at `admin-ajax.php` or the REST API, with no prefix:
 *   its language is the one of the page it came from (the Referer of this site under a language's prefix). For that
 *   request WordPress and the plugins speak that language (`switch_to_locale()`), so their confirmations and the
 *   emails written in it are in the visitor's language.
 * - Someone who registers from a page in a language gets that language in their profile (WordPress's `locale`),
 *   which WordPress already uses for the emails it sends to a user.
 * - Fluent Forms: see FluentForms.
 */
final class Requests {
	private static $switched = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'switch_for_form' ), 1 );
		add_action( 'user_register', array( __CLASS__, 'user_language' ) );
		// C39: Complianz (and other plugins in `lingua_bridge_wpml_string_domains`) translate their texts through the
		// strings API of WPML: without WPML, Lingua Bridge answers it with its own strings.
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			add_action( 'wpml_register_single_string', array( __CLASS__, 'wpml_register' ), 10, 3 );
			add_filter( 'wpml_translate_single_string', array( __CLASS__, 'wpml_translate' ), 10, 3 );
		}
	}

	/** @return string[] The text domains whose WPML strings Lingua Bridge takes (Complianz by default). */
	private static function wpml_domains() {
		return (array) apply_filters( 'lingua_bridge_wpml_string_domains', array( 'complianz' ) );
	}

	/** `wpml_register_single_string`: a text of the plugin becomes a string of the site, to translate. */
	public static function wpml_register( $domain, $name = '', $value = '' ) {
		// A text with markup (a message with a link) is left to the page: its words are translated where it is shown.
		if ( in_array( (string) $domain, self::wpml_domains(), true ) && is_string( $value ) && '' !== trim( $value ) && false === strpos( $value, '<' ) ) {
			Strings::register( 'html', wp_strip_all_tags( $value ) );
		}
	}

	/** `wpml_translate_single_string`: its published translation in the page's language, if there is one. */
	public static function wpml_translate( $value, $domain = '', $name = '' ) {
		if ( ! is_string( $value ) || false !== strpos( $value, '<' ) || ! in_array( (string) $domain, self::wpml_domains(), true ) ) {
			return $value;
		}
		$language = '' !== Strings::language() ? Strings::language() : self::language();
		$translated = '' !== $language ? Strings::translate( 'html', wp_strip_all_tags( $value ), $language ) : null;
		return null === $translated ? $value : esc_html( $translated );
	}

	/**
	 * The language of the current request: its prefix, else for an AJAX or REST request (or a registration on
	 * `wp-login.php`) the prefix of the page of this site it came from (never a page of the administration), else ''.
	 * Only a language of translation.
	 */
	public static function language() {
		$language = Routing::request_language();
		if ( '' !== $language ) {
			return $language;
		}
		$login = 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' );
		if ( ! $login && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && false === strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/wp-json/' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return '';
		}
		$referer = (string) wp_get_raw_referer();
		$home = wp_parse_url( home_url( '/' ) );
		$from = wp_parse_url( $referer );
		if ( '' === $referer || ! is_array( $from ) || ! is_array( $home ) || strtolower( (string) ( $from['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return '';
		}
		$base = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		$path = trim( substr( (string) ( $from['path'] ?? '/' ), strlen( $base ) ), '/' );
		if ( 0 === strpos( $path . '/', 'wp-admin/' ) ) {
			return '';
		}
		$first = (string) strtok( $path, '/' );
		$code = Routing::language_prefixes()[ $first ] ?? '';
		return isset( Languages::all()[ $code ] ) && Languages::default_code() !== $code ? $code : '';
	}

	/** A form sent from a page in a language: WordPress and the plugins answer in that language. */
	public static function switch_for_form() {
		if ( self::$switched || ( ! wp_doing_ajax() && false === strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/wp-json/' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$language = self::language();
		$locale = '' !== $language ? Locale::for_language( $language ) : '';
		if ( '' !== $locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale ) ) {
			self::$switched = true;
			Routing::force_language( $language );
		}
	}

	/** Someone registering from a page in a language: that language in their profile, unless they chose one. */
	public static function user_language( $user_id ) {
		$language = self::language();
		if ( '' === $language && function_exists( 'WC' ) && WC()->session ) {
			$language = (string) WC()->session->get( WooCommerce::SESSION_KEY, '' );
		}
		if ( '' !== $language && Languages::default_code() !== $language && isset( Languages::all()[ $language ] ) && '' === (string) get_user_meta( $user_id, 'locale', true ) ) {
			update_user_meta( $user_id, 'locale', Locale::for_language( $language ) );
		}
	}
}
