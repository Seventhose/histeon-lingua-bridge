<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WordPress language of a page (docs/STRINGS.md): a page in English is drawn with the en_US locale, so the
 * theme and the plugins translate their own texts with their language packs (buttons, dates, WooCommerce, forms).
 * The admin, REST and the original language keep the site's locale.
 */
final class Locale {
	/** Codes whose usual WordPress locale is not code_CODE. */
	const KNOWN = array(
		'ar' => 'ar', 'ca' => 'ca', 'cs' => 'cs_CZ', 'cy' => 'cy', 'da' => 'da_DK', 'el' => 'el', 'en' => 'en_US',
		'en-gb' => 'en_GB', 'en-us' => 'en_US', 'eo' => 'eo', 'es-419' => 'es_MX', 'et' => 'et', 'eu' => 'eu', 'fa' => 'fa_IR',
		'fi' => 'fi', 'ga' => 'ga', 'gl' => 'gl_ES', 'he' => 'he_IL', 'hi' => 'hi_IN', 'hy' => 'hy', 'ja' => 'ja',
		'ka' => 'ka_GE', 'kk' => 'kk', 'ko' => 'ko_KR', 'lb' => 'lb_LU', 'ms' => 'ms_MY', 'nb' => 'nb_NO', 'pt-br' => 'pt_BR',
		'sl' => 'sl_SI', 'sq' => 'sq', 'sr' => 'sr_RS', 'sv' => 'sv_SE', 'sw' => 'sw', 'th' => 'th', 'tl' => 'tl',
		'uk' => 'uk', 'ur' => 'ur', 'vi' => 'vi', 'zh' => 'zh_CN', 'zh-hans' => 'zh_CN', 'zh-hant' => 'zh_TW',
		'af' => 'af', 'be' => 'bel', 'hr' => 'hr', 'lv' => 'lv', 'bn' => 'bn_BD', 'bs' => 'bs_BA', 'ta' => 'ta_IN', 'la' => 'la',
	);

	private static $early = null;

	public static function init() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		add_filter( 'locale', array( __CLASS__, 'early_locale' ) );
		add_action( 'wp', array( __CLASS__, 'switch_for_page' ), 1 );
	}

	/** The WordPress locale of a language: the one set in the settings, or the usual one for its code. */
	public static function for_language( $language ) {
		$settings = Languages::settings();
		if ( ! empty( $settings['locales'][ $language ] ) && self::is_locale( $settings['locales'][ $language ] ) ) {
			return (string) $settings['locales'][ $language ];
		}
		if ( Languages::default_code() === $language ) {
			return (string) get_option( 'WPLANG', '' ) ?: 'en_US';
		}
		if ( isset( self::KNOWN[ $language ] ) ) {
			return self::KNOWN[ $language ];
		}
		$parts = explode( '-', strtolower( $language ) );
		return isset( $parts[1] ) && 2 === strlen( $parts[1] ) ? $parts[0] . '_' . strtoupper( $parts[1] ) : $parts[0] . '_' . strtoupper( $parts[0] );
	}

	public static function is_locale( $value ) {
		return is_string( $value ) && (bool) preg_match( '/\A[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?\z/', $value );
	}

	/**
	 * Before the query, a language prefix in the address already says the language: texts loaded early (the
	 * theme's) come in that language too.
	 */
	public static function early_locale( $locale ) {
		if ( null === self::$early ) {
			self::$early = '';
			if ( Routing::enabled() ) {
				$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to language codes only.
				$base = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
				$first = strtolower( (string) strtok( trim( substr( $path, strlen( $base ) ), '/' ), '/' ) );
				foreach ( array_keys( Languages::all() ) as $language ) {
					if ( Languages::default_code() !== $language && strtolower( $language ) === $first ) {
						self::$early = self::for_language( $language );
					}
				}
			}
		}
		return '' !== self::$early ? self::$early : $locale;
	}

	/** Once the page is known (a translation without a prefix, too), its language's locale. */
	public static function switch_for_page() {
		$language = Frontend::current_language();
		if ( Languages::default_code() === $language ) {
			return;
		}
		$locale = self::for_language( $language );
		if ( $locale !== determine_locale() && function_exists( 'switch_to_locale' ) ) {
			switch_to_locale( $locale );
		}
	}

	/** Locales of the site's languages and whether WordPress has their language files. */
	public static function status() {
		$installed = function_exists( 'get_available_languages' ) ? get_available_languages() : array();
		$out = array();
		foreach ( array_keys( Languages::all() ) as $language ) {
			$locale = self::for_language( $language );
			$out[ $language ] = array( 'locale' => $locale, 'installed' => 'en_US' === $locale || in_array( $locale, $installed, true ) );
		}
		return $out;
	}
}
