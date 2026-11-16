<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fluent Forms in the languages (C38, product 10): the texts of a form are strings of the site; in another language
 * its labels, the error messages of the browser and of the server, the confirmation and its redirect, and the email to
 * the visitor are translated; each submission keeps its language.
 */
final class FluentForms {
	/** The submission meta of Fluent Forms with the language a form was sent in. */
	const SUBMISSION_META = 'lingua_bridge_language';

	/** The keys of a field whose value is a visible text. */
	const TEXT_KEYS = array( 'label', 'placeholder', 'help_message', 'message', 'btn_text', 'admin_field_label', 'prefix_label', 'suffix_label' );

	/** Resolved original ⇒ resolved translation, for the email being sent. */
	private static $emails = array();

	/** Whether the locale was switched to the visitor's language for the email being sent. */
	private static $switched = false;

	/** The hooks on Fluent Forms: drawing a form, checking and confirming a submission, sending the emails. */
	public static function init() {
		add_filter( 'fluentform/rendering_form', array( __CLASS__, 'register_form_strings' ) );
		add_filter( 'fluentform/rendering_form', array( __CLASS__, 'translate_form' ), 20 );
		add_filter( 'fluentform/validations', array( __CLASS__, 'validations' ), 20 );
		add_filter( 'fluentform/global_default_message', array( __CLASS__, 'global_message' ), 20 );
		add_filter( 'fluentform/form_submission_confirmation', array( __CLASS__, 'confirmation' ), 20, 3 );
		add_action( 'fluentform/submission_inserted', array( __CLASS__, 'submission_language' ), 10, 3 );
		add_action( 'fluentform/integration_notify_notifications', array( __CLASS__, 'before_notification' ), 5, 4 );
		add_action( 'fluentform/integration_notify_notifications', array( __CLASS__, 'after_notification' ), 20, 0 );
		add_filter( 'fluentform/email_subject', array( __CLASS__, 'email_text' ), 20 );
		add_filter( 'fluentform/submission_message_parse', array( __CLASS__, 'email_text' ), 20 );
	}

	/** The language of the page being drawn, else the language of the AJAX or REST request ('' for the original). */
	public static function language() {
		$language = Strings::language();
		if ( '' !== $language ) {
			return $language;
		}
		return Requests::language();
	}

	/** A visible text in the language: its translation, or the text itself when there is none. */
	public static function text( $text, $language ) {
		if ( ! is_string( $text ) || '' === $language || '' === trim( $text ) || false !== strpos( $text, '<' ) ) {
			return $text;
		}
		$translated = Strings::translate( 'html', $text, $language );
		return null === $translated ? $text : $translated;
	}

	/** An HTML fragment in the language: each of its texts replaced by its translation, where there is one. */
	public static function html( $html, $language ) {
		if ( ! is_string( $html ) || '' === $language || '' === trim( $html ) ) {
			return $html;
		}
		$out = Strings::apply_html( '<div>' . $html . '</div>', $language );
		return substr( $out, 5, -6 );
	}

	/** The visible texts of an HTML fragment, as strings of the site. */
	public static function html_texts( $html ) {
		$texts = array();
		preg_match_all( '~>([^<>]+)<~u', '<div>' . (string) $html . '</div>', $m );
		foreach ( $m[1] as $raw ) {
			$text = Strings::normalize( $raw );
			if ( '' !== $text && preg_match( '/\p{L}/u', $text ) && ! preg_match( '/\A\{[^{}]*\}\z/u', $text ) ) {
				$texts[ $text ] = $text;
			}
		}
		return $texts;
	}

	/**
	 * The email to the visitor is translated, the one to the owner of the site stays in the original's language;
	 * the filter `lingua_bridge_fluentform_translate_notification` changes that rule.
	 */
	public static function to_visitor( $notification ) {
		$type = (string) ( $notification['sendTo']['type'] ?? '' );
		$email = (string) ( $notification['sendTo']['email'] ?? '' );
		$to = 'field' === $type || ( 'email' === $type && false !== strpos( $email, '{inputs.' ) );
		return (bool) apply_filters( 'lingua_bridge_fluentform_translate_notification', $to, $notification );
	}

	/**
	 * The texts of a Fluent Forms form are strings of the site (context `html`): its labels, placeholders, help and
	 * error messages, options and button, the confirmation and its redirect message, the emails to the visitor and
	 * the global error messages. Only while drawing it in the original's language.
	 */
	public static function register_form_strings( $form ) {
		if ( ! is_object( $form ) || '' !== Strings::language() ) {
			return $form;
		}
		$fields = is_string( $form->form_fields ?? null ) ? json_decode( $form->form_fields, true ) : ( (array) ( $form->fields ?? array() ) );
		$texts = array();
		self::collect_texts( is_array( $fields ) ? $fields : array(), $texts );
		// The confirmation messages too, before the first submission in another language.
		$settings = class_exists( '\FluentForm\App\Models\FormMeta' ) ? \FluentForm\App\Models\FormMeta::retrieve( 'formSettings', absint( $form->id ?? 0 ) ) : null;
		if ( is_array( $settings ) ) {
			foreach ( array( 'messageToShow', 'redirectMessage' ) as $key ) {
				$texts += self::html_texts( (string) ( $settings['confirmation'][ $key ] ?? '' ) );
			}
		}
		// The emails to the visitor: their subject and their message.
		if ( class_exists( '\FluentForm\App\Models\FormMeta' ) ) {
			foreach ( \FluentForm\App\Models\FormMeta::where( 'form_id', absint( $form->id ?? 0 ) )->where( 'meta_key', 'notifications' )->get() as $row ) {
				$n = json_decode( (string) $row->value, true );
				if ( ! is_array( $n ) || ! self::to_visitor( $n ) ) {
					continue;
				}
				$subject = trim( (string) ( $n['subject'] ?? '' ) );
				if ( '' !== $subject && false === strpos( $subject, '<' ) ) {
					$texts[ Strings::normalize( $subject ) ] = $subject;
				}
				$texts += self::html_texts( (string) ( $n['message'] ?? '' ) );
			}
		}
		// The global error messages of the site.
		$global = get_option( '_fluentform_global_form_settings' );
		$messages = is_array( $global ) && is_array( $global['default_messages'] ?? null ) ? $global['default_messages'] : array();
		foreach ( $messages as $message ) {
			if ( ! is_string( $message ) || '' === trim( $message ) || false !== strpos( $message, '<' ) || ! preg_match( '/\p{L}/u', $message ) ) {
				continue;
			}
			$texts[ Strings::normalize( $message ) ] = trim( $message );
		}
		$version = md5( implode( "\0", $texts ) );
		$key = 'lingua_bridge_form_' . absint( $form->id ?? 0 );
		if ( get_option( $key ) !== $version ) {
			foreach ( $texts as $text ) {
				Strings::register( 'html', $text );
			}
			update_option( $key, $version, false );
		}
		return $form;
	}

	/** The visible texts of the fields of a form, nested (containers, columns). */
	private static function collect_texts( $fields, &$texts, $parent = '' ) {
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				self::collect_texts( $value, $texts, (string) $key );
			} elseif ( is_string( $value ) && ( in_array( (string) $key, self::TEXT_KEYS, true ) || ( 'text' === $key && 'button_ui' === $parent ) ) ) {
				$text = trim( wp_strip_all_tags( $value ) );
				if ( '' !== $text && preg_match( '/\p{L}/u', $text ) && ! preg_match( '/\{[a-z_.]+\}/', $text ) ) {
					$texts[ Strings::normalize( $text ) ] = $text;
				}
			}
		}
	}

	/** The fields of a form with their visible texts in the language, nested like the fields. */
	public static function translate_fields( $fields, $language, $parent = '' ) {
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$fields[ $key ] = self::translate_fields( $value, $language, (string) $key );
			} elseif ( is_string( $value ) && ( in_array( (string) $key, self::TEXT_KEYS, true ) || ( 'text' === $key && 'button_ui' === $parent ) ) ) {
				$fields[ $key ] = self::text( $value, $language );
			}
		}
		return $fields;
	}

	/** The fields of a form drawn in a language. Also the messages Fluent Forms writes in its script. */
	public static function translate_form( $form ) {
		if ( ! is_object( $form ) ) {
			return $form;
		}
		$language = Strings::language();
		if ( '' === $language || ! is_array( $form->fields ?? null ) ) {
			return $form;
		}
		$form->fields = self::translate_fields( $form->fields, $language );
		return $form;
	}

	/** The browser's validation messages, in the language of the request. */
	public static function validations( $validations ) {
		if ( ! is_array( $validations ) || ! is_array( $validations[1] ?? null ) ) {
			return $validations;
		}
		$language = self::language();
		if ( '' === $language ) {
			return $validations;
		}
		foreach ( $validations[1] as $key => $message ) {
			if ( is_string( $message ) ) {
				$validations[1][ $key ] = self::text( $message, $language );
			}
		}
		return $validations;
	}

	/** The global error message of the forms, in the language of the request. */
	public static function global_message( $message ) {
		$language = self::language();
		return '' === $language ? $message : self::text( $message, $language );
	}

	/** The page of this site in the language for a post, if it is online and not protected by a password; else 0. */
	public static function page_in( $post_id, $language ) {
		$row = Storage::find( $post_id );
		if ( ! $row ) {
			return 0;
		}
		$member = Storage::find_group_language( $row['group_uuid'], $language );
		$post = $member ? get_post( absint( $member['object_id'] ) ) : null;
		return ( $post && Frontend::online( $post ) && '' === (string) $post->post_password ) ? (int) $post->ID : 0;
	}

	/** The confirmation of a form sent from a page in a language: its messages and its redirect in that language. */
	public static function confirmation( $confirmation, $form_data = null, $form = null ) {
		$language = Requests::language();
		if ( '' === $language || ! is_array( $confirmation ) ) {
			return $confirmation;
		}
		foreach ( array( 'messageToShow', 'redirectMessage' ) as $key ) {
			if ( isset( $confirmation[ $key ] ) && is_string( $confirmation[ $key ] ) && '' !== $confirmation[ $key ] ) {
				$confirmation[ $key ] = self::html( $confirmation[ $key ], $language );
			}
		}
		$to = (string) ( $confirmation['redirectTo'] ?? '' );
		if ( 'customPage' === $to && ! empty( $confirmation['customPage'] ) ) {
			$page = self::page_in( absint( $confirmation['customPage'] ), $language );
			if ( $page ) {
				$confirmation['customPage'] = $page;
			}
		} elseif ( 'customUrl' === $to && ! empty( $confirmation['customUrl'] ) ) {
			$url = Links::url( (string) $confirmation['customUrl'], $language );
			// Links::url() leaves the request without a forced language, so the redirect is forced to this one.
			Routing::force_language( $language );
			if ( '' !== $url ) {
				$confirmation['customUrl'] = $url;
			}
		}
		return $confirmation;
	}

	/** Each submission keeps the language it was sent in: whoever answers knows which. */
	public static function submission_language( $insert_id, $form_data = null, $form = null ) {
		$language = self::language();
		$language = '' !== $language ? $language : Languages::default_code();
		if ( class_exists( '\FluentForm\App\Helpers\Helper' ) && method_exists( '\FluentForm\App\Helpers\Helper', 'setSubmissionMeta' ) ) {
			\FluentForm\App\Helpers\Helper::setSubmissionMeta( absint( $insert_id ), self::SUBMISSION_META, $language, is_object( $form ) ? absint( $form->id ?? 0 ) : false );
		}
	}

	/** The language a submission was sent in, from its meta: '' for the original, or when there is none. */
	public static function submission_meta_language( $entry_id ) {
		if ( ! $entry_id || ! class_exists( '\FluentForm\App\Helpers\Helper' ) || ! method_exists( '\FluentForm\App\Helpers\Helper', 'getSubmissionMeta' ) ) {
			return '';
		}
		$language = (string) \FluentForm\App\Helpers\Helper::getSubmissionMeta( absint( $entry_id ), self::SUBMISSION_META, '' );
		return ( isset( Languages::all()[ $language ] ) && Languages::default_code() !== $language ) ? $language : '';
	}

	/** Before an email is sent: the email to the visitor is resolved in the language of the submission (or the request). */
	public static function before_notification( $feed, $form_data = null, $entry = null, $form = null ) {
		self::after_notification();
		if ( ! is_array( $feed ) || ! is_array( $feed['settings'] ?? null ) || ! is_array( $feed['processedValues'] ?? null ) || ! is_object( $entry ) || ! self::to_visitor( $feed['settings'] ) || ! class_exists( '\FluentForm\App\Services\FormBuilder\ShortCodeParser' ) ) {
			return;
		}
		$language = self::submission_meta_language( $entry->id ?? 0 );
		if ( '' === $language ) {
			$language = Requests::language();
		}
		if ( '' === $language ) {
			return;
		}
		$settings = $feed['settings'];
		unset( $settings['conditionals'] );
		$settings['subject'] = self::text( (string) ( $settings['subject'] ?? '' ), $language );
		$settings['message'] = self::html( (string) ( $settings['message'] ?? '' ), $language );
		$parsed = \FluentForm\App\Services\FormBuilder\ShortCodeParser::parse( $settings, absint( $entry->id ), is_array( $form_data ) ? $form_data : array(), $form, false, 'notifications' );
		foreach ( array( 'subject', 'message' ) as $key ) {
			$from = $feed['processedValues'][ $key ] ?? null;
			$to = is_array( $parsed ) ? ( $parsed[ $key ] ?? null ) : null;
			if ( is_string( $from ) && is_string( $to ) && $from !== $to ) {
				self::$emails[ $from ] = $to;
			}
		}
		$locale = Locale::for_language( $language );
		if ( '' !== $locale && switch_to_locale( $locale ) ) {
			// The texts of Fluent Forms in the email itself (its own wording) follow the visitor's language too.
			self::$switched = true;
		}
	}

	/** After an email is sent: forget its texts and switch the locale back. */
	public static function after_notification() {
		self::$emails = array();
		if ( self::$switched ) {
			restore_previous_locale();
			self::$switched = false;
		}
	}

	/** The subject or the message of an email as translated for the visitor, if it was resolved; else the text itself. */
	public static function email_text( $text ) {
		return is_string( $text ) && isset( self::$emails[ $text ] ) ? self::$emails[ $text ] : $text;
	}
}
