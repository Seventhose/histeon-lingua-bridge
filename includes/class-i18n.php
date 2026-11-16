<?php
/**
 * The plugin's own interface languages: written in Italian, English for every other language.
 *
 * @package LinguaBridge
 */

namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads languages/lingua-bridge-<locale>.mo. A language without its own file gets the English one
 * (lingua-bridge-en_US), so a German or French administrator reads English rather than Italian.
 */
final class I18n {

	const DOMAIN   = 'lingua-bridge';
	const FALLBACK = 'en_US';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'load' ), 0 );
		// WordPress 6.5+ and older versions name the filter differently.
		add_filter( 'load_translation_file', array( __CLASS__, 'fallback' ), 10, 3 );
		add_filter( 'load_textdomain_mofile', array( __CLASS__, 'fallback' ), 10, 2 );
		add_filter( 'load_script_translation_file', array( __CLASS__, 'script_fallback' ), 10, 3 );
	}

	public static function load() {
		load_plugin_textdomain( self::DOMAIN, false, dirname( plugin_basename( LINGUA_BRIDGE_FILE ) ) . '/languages' );
	}

	/** A classic script of the plugin that uses wp.i18n with this domain. */
	public static function script( $handle ) {
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, self::DOMAIN, LINGUA_BRIDGE_DIR . 'languages' );
		}
	}

	/**
	 * Runs $callback with Lingua Bridge's own texts in the current user's language (profile), whatever the language
	 * of the page being rendered: the on-page editor is a tool of the person, the page belongs to its language.
	 * Only this plugin's texts change, through gettext filters, so it works without WordPress's language packs.
	 *
	 * @param callable $callback What builds the texts.
	 * @return mixed What $callback returns.
	 */
	public static function in_user_language( $callback ) {
		$locale = get_user_locale();
		if ( $locale === determine_locale() ) {
			return $callback();
		}
		$mo = null;
		if ( 0 !== strpos( $locale, 'it' ) ) {
			$file = self::fallback( LINGUA_BRIDGE_DIR . 'languages/' . self::DOMAIN . '-' . $locale . '.mo', self::DOMAIN, $locale );
			if ( is_readable( $file ) ) {
				require_once ABSPATH . WPINC . '/pomo/mo.php';
				$mo = new \MO();
				if ( ! $mo->import_from_file( $file ) ) {
					$mo = null;
				}
			}
		}
		$single  = static function ( $translation, $text ) use ( $mo ) {
			return null === $mo ? $text : $mo->translate( $text );
		};
		$context = static function ( $translation, $text, $context ) use ( $mo ) {
			return null === $mo ? $text : $mo->translate( $text, $context );
		};
		$plural  = static function ( $translation, $one, $many, $number ) use ( $mo ) {
			return null === $mo ? ( 1 === (int) $number ? $one : $many ) : $mo->translate_plural( $one, $many, $number );
		};
		add_filter( 'gettext_' . self::DOMAIN, $single, 99, 2 );
		add_filter( 'gettext_with_context_' . self::DOMAIN, $context, 99, 3 );
		add_filter( 'ngettext_' . self::DOMAIN, $plural, 99, 4 );
		try {
			return $callback();
		} finally {
			remove_filter( 'gettext_' . self::DOMAIN, $single, 99 );
			remove_filter( 'gettext_with_context_' . self::DOMAIN, $context, 99 );
			remove_filter( 'ngettext_' . self::DOMAIN, $plural, 99 );
		}
	}

	private static function needs_fallback( $locale ) {
		return '' !== $locale && 0 !== strpos( $locale, 'it' ) && self::FALLBACK !== $locale;
	}

	/**
	 * @param string $file   Translation file.
	 * @param string $domain Text domain.
	 * @param string $locale Locale (WordPress 6.5+); taken from the file name otherwise.
	 * @return string
	 */
	public static function fallback( $file, $domain, $locale = '' ) {
		if ( self::DOMAIN !== $domain || ! is_string( $file ) || is_readable( $file ) ) {
			return $file;
		}
		if ( '' === $locale && preg_match( '/-([a-z]{2,3}(?:_[A-Za-z0-9]+)*)\.(?:mo|l10n\.php)$/', $file, $m ) ) {
			$locale = $m[1];
		}
		if ( ! self::needs_fallback( (string) $locale ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/-' . preg_quote( $locale, '/' ) . '(\.l10n\.php|\.mo)$/', '-' . self::FALLBACK . '$1', $file );
		return is_readable( $english ) ? $english : $file;
	}

	/**
	 * @param string|false $file   JSON file.
	 * @param string       $handle Script handle.
	 * @param string       $domain Text domain.
	 * @return string|false
	 */
	public static function script_fallback( $file, $handle, $domain ) {
		if ( false === $file || self::DOMAIN !== $domain || is_readable( $file ) || ! preg_match( '/-([a-z]{2,3}(?:_[A-Za-z0-9]+)*)-[0-9a-f]{32}\.json$/', $file, $m ) || ! self::needs_fallback( $m[1] ) ) {
			return $file;
		}
		$english = (string) preg_replace( '/-' . preg_quote( $m[1], '/' ) . '-([0-9a-f]{32}\.json)$/', '-' . self::FALLBACK . '-$1', $file );
		return is_readable( $english ) ? $english : $file;
	}
}
