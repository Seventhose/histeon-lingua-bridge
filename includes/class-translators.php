<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The «Traduttore» role (N7 of the 1.5.10 verification): a person who translates into the languages given to them
 * and nothing else. They read the published originals, create and edit the drafts of their languages, and send them
 * for review (the role cannot publish); they never edit an original, a translation of another language or a
 * published one. Editors and administrators keep WordPress's own rules: this only narrows users who have the
 * capability and cannot edit others' contents.
 */
final class Translators {
	const ROLE = 'lingua_bridge_translator';
	const CAP = 'lingua_bridge_translate';
	/** User meta: the language codes the user translates into. */
	const META = 'lingua_bridge_languages';
	/** Meta capability: may this user translate this original into this language (args: source id, language). */
	const TRANSLATE_POST = 'lingua_bridge_translate_post';
	const ROLE_VERSION = '1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	/** The role, created once (and again if its version changes): read, edit_posts, edit_pages, translate. */
	public static function ensure_role() {
		if ( self::ROLE_VERSION === get_option( 'lingua_bridge_translator_role' ) && get_role( self::ROLE ) ) {
			return;
		}
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'Traduttore', 'lingua-bridge' ), array( 'read' => true, 'edit_posts' => true, 'edit_pages' => true, self::CAP => true ) );
		update_option( 'lingua_bridge_translator_role', self::ROLE_VERSION, true );
	}

	/** A translator: has the capability and cannot edit others' contents (an editor with it keeps all its rights). */
	public static function is_translator( $user_id ) {
		return $user_id && user_can( $user_id, self::CAP ) && ! user_can( $user_id, 'edit_others_posts' );
	}

	/** @return list<string> The languages of translation given to the user, in the order of the settings. */
	public static function languages( $user_id ) {
		$saved = array_map( 'strval', (array) get_user_meta( absint( $user_id ), self::META, true ) );
		return array_values( array_filter( array_keys( Languages::all() ), static fn( $code ) => Languages::default_code() !== $code && in_array( $code, $saved, true ) ) );
	}

	/** @return array<string, string> The languages the current user works on: a translator's own, else all of translation. */
	public static function targets() {
		$targets = array_diff_key( Languages::all(), array( Languages::default_code() => true ) );
		$user = get_current_user_id();
		return self::is_translator( $user ) ? array_intersect_key( $targets, array_flip( self::languages( $user ) ) ) : $targets;
	}

	/** Whether the current user may translate this original into this language: they can edit it, or translate into it. */
	public static function may_translate( $source_id, $language ) {
		return current_user_can( self::TRANSLATE_POST, absint( $source_id ), (string) $language );
	}

	/** Whether the current user may translate this original into at least one language. */
	public static function may_translate_any( $source_id ) {
		foreach ( array_keys( self::targets() ) as $code ) {
			if ( self::may_translate( $source_id, $code ) ) {
				return true;
			}
		}
		return false;
	}

	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::TRANSLATE_POST === $cap ) {
			$source = get_post( absint( $args[0] ?? 0 ) );
			if ( ! $source ) {
				return array( 'do_not_allow' );
			}
			if ( user_can( $user_id, 'edit_post', $source->ID ) ) {
				return array( 'exist' );
			}
			$language = sanitize_key( (string) ( $args[1] ?? '' ) );
			// A translator reads the original as a visitor would: only a published one, without a password.
			$readable = 'publish' === $source->post_status && '' === (string) $source->post_password;
			return self::is_translator( $user_id ) && $readable && in_array( $language, self::languages( $user_id ), true ) ? array( self::CAP ) : array( 'do_not_allow' );
		}
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) || ! self::is_translator( $user_id ) ) {
			return $caps;
		}
		$post = get_post( absint( $args[0] ?? 0 ) );
		$row = $post ? Storage::find( $post->ID ) : null;
		// An original (or a content not linked): WordPress decides, so a translator edits only their own drafts.
		if ( ! $row || absint( $row['object_id'] ) === absint( $row['source_id'] ) || Languages::default_code() === $row['language'] ) {
			return $caps;
		}
		if ( 'delete_post' === $cap || ! in_array( $row['language'], self::languages( $user_id ), true ) ) {
			return array( 'do_not_allow' );
		}
		// A translation of their language, before it is published; a published one is the editors'.
		return in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ? array( self::CAP ) : array( 'do_not_allow' );
	}

	/** On the profile of a user with the capability: their languages, set by an administrator. */
	public static function render_profile( $user ) {
		if ( ! $user instanceof \WP_User || ! user_can( $user, self::CAP ) ) {
			return;
		}
		$mine = self::languages( $user->ID );
		$editable = current_user_can( 'manage_options' );
		echo '<h2>' . esc_html__( 'Lingua Bridge', 'lingua-bridge' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'Lingue del traduttore', 'lingua-bridge' ) . '</th><td><fieldset>';
		if ( $editable ) {
			wp_nonce_field( 'lingua_bridge_translator_' . $user->ID, 'lingua_bridge_translator_nonce' );
		}
		foreach ( array_diff_key( Languages::all(), array( Languages::default_code() => true ) ) as $code => $label ) {
			echo '<label><input type="checkbox" name="lingua_bridge_languages[]" value="' . esc_attr( $code ) . '" ' . checked( in_array( $code, $mine, true ), true, false ) . disabled( ! $editable, true, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '<p class="description">' . esc_html__( 'Crea e modifica solo le bozze di queste lingue e le manda in revisione; non modifica gli originali né le traduzioni pubblicate.', 'lingua-bridge' ) . '</p></fieldset></td></tr></table>';
	}

	public static function save_profile( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['lingua_bridge_translator_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lingua_bridge_translator_nonce'] ) ), 'lingua_bridge_translator_' . $user_id ) ) {
			return;
		}
		$chosen = isset( $_POST['lingua_bridge_languages'] ) && is_array( $_POST['lingua_bridge_languages'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['lingua_bridge_languages'] ) ) : array();
		$valid = array_values( array_intersect( array_keys( array_diff_key( Languages::all(), array( Languages::default_code() => true ) ) ), $chosen ) );
		$valid ? update_user_meta( $user_id, self::META, $valid ) : delete_user_meta( $user_id, self::META );
	}
}
