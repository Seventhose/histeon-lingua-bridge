<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FluentCRM in the languages (product 10): a contact keeps the language of the form it subscribed with, and the
 * emails FluentCRM writes for it (double opt-in, automations, campaigns) are translated before its codes are resolved;
 * their texts are strings of the site.
 */
final class FluentCrm {
	/** The contact meta with the language of the form a contact subscribed with, the original's too. */
	const CONTACT_META = '_lingua_bridge_language';

	/** The language of the submission whose FluentCRM feed is running ('' for the original), else null. */
	private static $feed = null;

	/** Contact id ⇒ its language, for this request. */
	private static $languages = array();

	/** The texts already registered in this request (md5 ⇒ true). */
	private static $registered = array();

	/** The hooks on FluentCRM and on the feed of Fluent Forms; without FluentCRM they never run. */
	public static function init() {
		add_filter( 'fluent_crm/parse_campaign_email_text', array( __CLASS__, 'translate' ), 5, 2 );
		add_action( 'fluentform/integration_notify_fluentcrm_feeds', array( __CLASS__, 'before_feed' ), 5, 3 );
		add_action( 'fluentform/integration_notify_fluentcrm_feeds', array( __CLASS__, 'after_feed' ), 20, 0 );
		add_action( 'fluent_crm/contact_added_by_fluentform', array( __CLASS__, 'remember' ), 10, 2 );
		add_action( 'fluent_crm/contact_updated_by_fluentform', array( __CLASS__, 'remember' ), 10, 2 );
	}

	/** The language of the submission an entry belongs to, else of the request ('' for the original). */
	private static function submission_language( $entry ) {
		$language = is_object( $entry ) ? FluentForms::submission_meta_language( $entry->id ?? 0 ) : '';
		if ( '' === $language ) {
			$language = Requests::language();
		}
		return $language;
	}

	/** The double opt-in is sent before contact_added_by_fluentform, in the language of the submission. */
	public static function before_feed( $feed, $form_data = null, $entry = null ) {
		self::$feed = self::submission_language( $entry );
	}

	/** Forgets the language of the feed once it has run. */
	public static function after_feed() {
		self::$feed = null;
	}

	/** Saves the language of the submission on the contact, the original's too, so an Italian contact stays Italian; the last submission wins. */
	public static function remember( $subscriber, $entry = null ) {
		if ( ! is_object( $subscriber ) || empty( $subscriber->id ) || ! function_exists( 'fluentcrm_update_subscriber_meta' ) ) {
			return;
		}
		$language = self::submission_language( $entry );
		if ( '' === $language ) {
			$language = Languages::default_code();
		}
		fluentcrm_update_subscriber_meta( absint( $subscriber->id ), self::CONTACT_META, $language );
		self::$languages[ absint( $subscriber->id ) ] = $language;
	}

	/** The language a contact is translated into, '' for the original. */
	private static function contact_language( $subscriber ) {
		if ( null !== self::$feed ) {
			return self::$feed;
		}
		if ( is_object( $subscriber ) && ! empty( $subscriber->id ) ) {
			$id = absint( $subscriber->id );
			if ( ! isset( self::$languages[ $id ] ) ) {
				self::$languages[ $id ] = function_exists( 'fluentcrm_get_subscriber_meta' ) ? (string) fluentcrm_get_subscriber_meta( $id, self::CONTACT_META, '' ) : '';
			}
			$saved = self::$languages[ $id ];
			if ( '' !== $saved ) {
				return isset( Languages::all()[ $saved ] ) && Languages::default_code() !== $saved ? $saved : '';
			}
		}
		return Requests::language();
	}

	/** Whether a text is made only of FluentCRM codes. */
	private static function is_code( $text ) {
		return (bool) preg_match( '/\A(?:\s*(?:\{\{[^{}]*\}\}|##[^#]*##|#[a-z_]+#))+\s*\z/u', $text );
	}

	/** Registers the visible texts of a FluentCRM email as strings of the site, once per request. */
	private static function register( $text ) {
		$key = md5( $text );
		if ( isset( self::$registered[ $key ] ) ) {
			return;
		}
		self::$registered[ $key ] = true;
		if ( false !== strpos( $text, '<' ) ) {
			foreach ( FluentForms::html_texts( $text ) as $t ) {
				if ( ! self::is_code( $t ) ) {
					Strings::register( 'html', $t );
				}
			}
			return;
		}
		$t = Strings::normalize( $text );
		if ( '' !== $t && preg_match( '/\p{L}/u', $t ) && ! self::is_code( $t ) ) {
			Strings::register( 'html', $t );
		}
	}

	/** Priority 5, before FluentCRM resolves its codes: the template is translated, not the resolved text. */
	public static function translate( $text, $subscriber = null ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return $text;
		}
		self::register( $text );
		$language = self::contact_language( $subscriber );
		if ( '' === $language ) {
			return $text;
		}
		return false !== strpos( $text, '<' ) ? FluentForms::html( $text, $language ) : FluentForms::text( $text, $language );
	}
}
