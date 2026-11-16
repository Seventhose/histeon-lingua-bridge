<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C26 of the competition list (1.11.0): a translation is assigned to someone, who is told.
 *
 * - «Assegnata a» in the screen of a translation (a box, the meta `_lingua_bridge_assignee`): someone who may
 *   translate into its language; the dashboard shows «Le mie» (the originals with a translation assigned to me).
 * - An email to whoever gets a translation, and to its person when the translation goes to «da rivedere» (a machine
 *   or the memory wrote it). With `notify` at `daily` the messages of a day become one, for whoever has many
 *   languages; with `off` none (setting «Avvisi ai traduttori»).
 */
final class Assignments {
	const META = '_lingua_bridge_assignee';
	/** User meta: the messages waiting for the daily summary. */
	const PENDING_META = '_lingua_bridge_pending_notices';
	const HOOK = 'lingua_bridge_daily_notices';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'save' ), 20, 2 );
		add_action( 'lingua_bridge_review_state', array( __CLASS__, 'review_state' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'send_daily' ) );
		if ( 'daily' === self::notify() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/** `each` (of serie), `daily` or `off`. */
	public static function notify() {
		$value = (string) ( Languages::settings()['notify'] ?? 'each' );
		return in_array( $value, array( 'each', 'daily', 'off' ), true ) ? $value : 'each';
	}

	/** The person a translation is assigned to, 0 for nobody. */
	public static function assignee( $post_id ) {
		return absint( get_post_meta( absint( $post_id ), self::META, true ) );
	}

	/**
	 * Who may get the translation `$post_id` into `$language` of `$source_id`: the users who can edit it (a
	 * translator only into their languages).
	 */
	public static function candidates( $source_id, $language, $post_id = 0 ) {
		$out = array();
		foreach ( get_users( array( 'capability' => 'edit_posts', 'number' => 200, 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
			$languages = Translators::is_translator( $user->ID ) ? Translators::languages( $user->ID ) : null;
			$may_edit = ! $post_id || user_can( $user->ID, 'edit_post', absint( $post_id ) );
			if ( $may_edit && ( null === $languages || in_array( $language, (array) $languages, true ) ) ) {
				$out[ (int) $user->ID ] = (string) $user->display_name;
			}
		}
		return $out;
	}

	/** The box «Assegnata a» in the screen of a translation, for whoever may edit others' contents. */
	public static function meta_box( $post_type, $post ) {
		$row = $post instanceof \WP_Post ? Storage::find( $post->ID ) : null;
		if ( ! $row || Languages::default_code() === $row['language'] || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		add_meta_box( 'lingua-bridge-assignee', __( 'Assegnata a', 'lingua-bridge' ), array( __CLASS__, 'render_box' ), $post_type, 'side' );
	}

	public static function render_box( $post ) {
		$row = Storage::find( $post->ID );
		wp_nonce_field( 'lingua_bridge_assignee', 'lingua_bridge_assignee_nonce' );
		echo '<label class="screen-reader-text" for="lb-assignee">' . esc_html__( 'Assegnata a', 'lingua-bridge' ) . '</label><select id="lb-assignee" name="lingua_bridge_assignee" style="width:100%"><option value="0">' . esc_html__( 'Nessuno', 'lingua-bridge' ) . '</option>';
		foreach ( self::candidates( absint( $row['source_id'] ?? 0 ), $row['language'] ?? '', $post->ID ) as $id => $name ) {
			echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( self::assignee( $post->ID ), $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Riceve un’email quando gliela assegni e quando passa a «da rivedere».', 'lingua-bridge' ) . '</p>';
	}

	/** Saved with the translation: a valid person, or nobody; a new person is told. */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['lingua_bridge_assignee'], $_POST['lingua_bridge_assignee_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lingua_bridge_assignee_nonce'] ) ), 'lingua_bridge_assignee' ) || ! current_user_can( 'edit_others_posts' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		self::assign( $post_id, absint( wp_unslash( $_POST['lingua_bridge_assignee'] ) ) );
	}

	/** Assigns a translation (0: to nobody). Returns whether it changed. */
	public static function assign( $post_id, $user_id ) {
		$row = Storage::find( $post_id );
		if ( ! $row || Languages::default_code() === $row['language'] ) {
			return false;
		}
		$user_id = isset( self::candidates( absint( $row['source_id'] ), $row['language'], $post_id )[ $user_id ] ) ? absint( $user_id ) : 0;
		if ( self::assignee( $post_id ) === $user_id ) {
			return false;
		}
		if ( $user_id ) {
			update_post_meta( $post_id, self::META, $user_id );
			self::tell( $user_id, $post_id, 'assigned' );
		} else {
			delete_post_meta( $post_id, self::META );
		}
		return true;
	}

	/** A translation gone to «da rivedere»: its person is told. */
	public static function review_state( $post_id, $state ) {
		$user_id = self::assignee( $post_id );
		if ( 'needs_review' === $state && $user_id && get_current_user_id() !== $user_id ) {
			self::tell( $user_id, $post_id, 'review' );
		}
	}

	/** The originals with a translation assigned to `$user_id`, at most 500. */
	public static function mine( $user_id ) {
		$ids = get_posts( array( 'post_type' => 'any', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'numberposts' => 500, 'fields' => 'ids', 'meta_key' => self::META, 'meta_value' => (string) absint( $user_id ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$sources = array();
		foreach ( $ids as $id ) {
			$row = Storage::find( $id );
			if ( $row && $row['source_id'] ) {
				$sources[] = absint( $row['source_id'] );
			}
		}
		return array_values( array_unique( $sources ) );
	}

	/** One message: now, or kept for the daily summary, or none. */
	private static function tell( $user_id, $post_id, $why ) {
		$user = get_userdata( $user_id );
		if ( ! $user || 'off' === self::notify() || ! is_email( $user->user_email ) ) {
			return;
		}
		$row = Storage::find( $post_id );
		$line = sprintf(
			/* translators: 1: content title, 2: language, 3: address of the editor. */
			'assigned' === $why ? __( 'Ti è stata assegnata la traduzione «%1$s» (%2$s): %3$s', 'lingua-bridge' ) : __( 'La traduzione «%1$s» (%2$s) è da rivedere: %3$s', 'lingua-bridge' ),
			get_the_title( $post_id ),
			Languages::label( $row['language'] ?? '' ),
			get_edit_post_link( $post_id, 'raw' )
		);
		if ( 'daily' === self::notify() ) {
			$pending = get_user_meta( $user_id, self::PENDING_META, true );
			$pending = is_array( $pending ) ? $pending : array();
			$pending[] = $line;
			update_user_meta( $user_id, self::PENDING_META, array_slice( array_values( array_filter( $pending, static fn( $one ) => is_string( $one ) && '' !== $one ) ), -200 ) );
			return;
		}
		$locale = switch_to_user_locale( $user_id );
		wp_mail( $user->user_email, sprintf( /* translators: %s: site name. */ __( '[%s] Traduzioni', 'lingua-bridge' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), $line );
		if ( $locale ) {
			restore_previous_locale();
		}
	}

	/** The daily summary: one email per person with messages waiting. */
	public static function send_daily() {
		foreach ( get_users( array( 'meta_key' => self::PENDING_META, 'number' => 500 ) ) as $user ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			$pending = get_user_meta( $user->ID, self::PENDING_META, true );
			$pending = is_array( $pending ) ? array_filter( $pending, static fn( $one ) => is_string( $one ) && '' !== $one ) : array();
			delete_user_meta( $user->ID, self::PENDING_META );
			if ( $pending && is_email( $user->user_email ) ) {
				wp_mail( $user->user_email, sprintf( /* translators: %s: site name. */ __( '[%s] Traduzioni di oggi', 'lingua-bridge' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), implode( "\n\n", $pending ) );
			}
		}
	}
}
