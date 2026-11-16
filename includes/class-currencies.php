<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C21 of the competition list (1.12.0): a currency for each language, with the multi-currency of WooPayments.
 *
 * Lingua Bridge converts nothing: the currencies, their rates and the prices are WooPayments' (WooCommerce → Settings →
 * Multi-currency). It only says which of the enabled currencies a language starts with (`language_currencies`,
 * language => code, filter `lingua_bridge_language_currency`): on the first page of a language the visitor sees, the
 * currency becomes that one through WooPayments' own `update_selected_currency()`, as its switcher does. Then the
 * visitor's choice wins: a currency chosen with WooPayments' switcher (`?currency=`) stays until they move to another
 * language. Which language the currency was set for is kept in a cookie (`lingua_bridge_currency`, the language
 * code only).
 */
final class Currencies {
	const COOKIE = 'lingua_bridge_currency';

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'apply' ) );
	}

	/** WooPayments' multi-currency, when it is there and on; null otherwise. */
	private static function multi_currency() {
		if ( ! function_exists( 'WC_Payments_Multi_Currency' ) || ! class_exists( '\\WCPay\\MultiCurrency\\MultiCurrency' ) || ! \WCPay\MultiCurrency\MultiCurrency::is_enabled() ) {
			return null;
		}
		$instance = WC_Payments_Multi_Currency();
		return is_object( $instance ) && method_exists( $instance, 'update_selected_currency' ) ? $instance : null;
	}

	/** @return string[] The currencies enabled in WooPayments (upper case codes), [] without it. */
	public static function enabled() {
		$multi = self::multi_currency();
		return $multi ? array_map( 'strval', array_keys( (array) $multi->get_enabled_currencies() ) ) : array();
	}

	/** The currency `$language` starts with: its setting, then the filter; '' for none or one not enabled. */
	public static function for_language( $language ) {
		$saved = (array) ( Languages::settings()['language_currencies'] ?? array() );
		$code = strtoupper( (string) apply_filters( 'lingua_bridge_language_currency', (string) ( $saved[ $language ] ?? '' ), $language ) );
		return '' !== $code && in_array( $code, self::enabled(), true ) ? $code : '';
	}

	/** On the first page of a language: its currency, unless the visitor is choosing one right now. */
	public static function apply() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() ) {
			return;
		}
		$language = Frontend::current_language();
		$last = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $last === $language ) {
			return;
		}
		$code = self::for_language( $language );
		if ( '' === $code ) {
			return;
		}
		// The visitor's own choice of WooPayments' switcher, on this very request: it wins from now on.
		if ( ! isset( $_GET['currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::multi_currency()->update_selected_currency( $code );
		}
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $language, array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		}
		$_COOKIE[ self::COOKIE ] = $language;
	}
}
