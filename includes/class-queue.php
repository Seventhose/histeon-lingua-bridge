<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The work that takes long, done a few contents at a time (F2 of the 1.5.10 verification): the translation service
 * on many contents, the drafts of many contents. A group action puts the contents in the queue and the request ends
 * at once; WP-Cron (and the dashboard, while it is open) works through them, ITEMS_PER_STEP per step, each as the
 * user who queued it, so every content's permissions are checked as for that user. The dashboard shows how far it
 * is, what failed and why, with «Riprova».
 *
 * The queue is an option (not autoloaded): at most MAX_ITEMS jobs. Machines still write drafts only.
 */
final class Queue {
	const OPTION = 'lingua_bridge_queue';
	const HOOK = 'lingua_bridge_queue_run';
	const ITEMS_PER_STEP = 3;
	const MAX_ITEMS = 500;
	/** A job that failed this many times stays failed until someone asks again. */
	const MAX_TRIES = 2;
	const ACTIONS = array( 'create', 'machine' );

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'wp_ajax_lingua_bridge_queue', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_lingua_bridge_queue', array( __CLASS__, 'handle' ) );
	}

	/** @return list<array{id: string, user: int, action: string, post: int, language: string, status: string, tries: int, message: string, title: string}> */
	public static function items() {
		$items = get_option( self::OPTION, array() );
		return is_array( $items ) ? array_values( array_filter( $items, 'is_array' ) ) : array();
	}

	private static function store( $items ) {
		update_option( self::OPTION, array_slice( array_values( $items ), -self::MAX_ITEMS ), false );
	}

	/**
	 * Puts contents in the queue for the current user (or `$user`: the automatic translation, C23). Duplicates (same action, content, language, still waiting)
	 * are left out. Returns how many were added.
	 */
	public static function add( $action, $post_ids, $language, $user = 0 ) {
		$user = $user ? absint( $user ) : get_current_user_id();
		if ( ! in_array( $action, self::ACTIONS, true ) || ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			return 0;
		}
		$items = self::items();
		$waiting = array();
		foreach ( $items as $item ) {
			if ( 'queued' === $item['status'] ) {
				$waiting[ $item['action'] . ':' . $item['post'] . ':' . $item['language'] ] = true;
			}
		}
		$added = 0;
		foreach ( array_map( 'absint', (array) $post_ids ) as $post_id ) {
			if ( ! $post_id || isset( $waiting[ $action . ':' . $post_id . ':' . $language ] ) || count( $items ) >= self::MAX_ITEMS ) {
				continue;
			}
			$items[] = array(
				'id'       => wp_generate_password( 10, false ),
				'user'     => $user,
				'action'   => $action,
				'post'     => $post_id,
				'language' => $language,
				'status'   => 'queued',
				'tries'    => 0,
				'message'  => '',
				'title'    => (string) get_the_title( $post_id ),
			);
			$waiting[ $action . ':' . $post_id . ':' . $language ] = true;
			++$added;
		}
		if ( $added ) {
			self::store( $items );
			self::schedule();
		}
		return $added;
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
		}
	}

	/** One step of WP-Cron: a few jobs, then the next step while some wait. */
	public static function run() {
		self::step( 0 );
		foreach ( self::items() as $item ) {
			if ( 'queued' === $item['status'] ) {
				self::schedule();
				break;
			}
		}
	}

	/**
	 * Works through up to ITEMS_PER_STEP waiting jobs. With `$user` only that user's jobs (the dashboard's step);
	 * with 0 any user's (WP-Cron), each run as its user and the current user put back after.
	 *
	 * @return int How many jobs were done or failed in this step.
	 */
	public static function step( $user ) {
		$lock = 'lingua_bridge_queue_lock';
		if ( ! add_option( $lock, (string) time(), '', false ) ) {
			// A step still running (or one that died: after five minutes the lock goes).
			if ( time() - (int) get_option( $lock, 0 ) < 300 ) {
				return 0;
			}
			update_option( $lock, (string) time(), false );
		}
		$before = get_current_user_id();
		$worked = 0;
		$items = self::items();
		foreach ( $items as $index => $item ) {
			if ( $worked >= self::ITEMS_PER_STEP ) {
				break;
			}
			if ( 'queued' !== $item['status'] || ( $user && absint( $item['user'] ) !== absint( $user ) ) || ! get_userdata( absint( $item['user'] ) ) ) {
				continue;
			}
			wp_set_current_user( absint( $item['user'] ) );
			$result = self::work( $item );
			++$item['tries'];
			if ( is_wp_error( $result ) ) {
				$item['message'] = $result->get_error_message();
				$item['status'] = $item['tries'] >= self::MAX_TRIES || in_array( $result->get_error_code(), array( 'lingua_forbidden', 'lingua_mt_forbidden', 'lingua_mt_published', 'lingua_mt_independent', 'lingua_mt_off' ), true ) ? 'failed' : 'queued';
			} else {
				$item['status'] = 'done';
				$item['message'] = (string) $result;
			}
			$items[ $index ] = $item;
			++$worked;
			// Saved after each job: a step cut by a time limit loses one job at most.
			self::store( $items );
		}
		wp_set_current_user( $before );
		delete_option( $lock );
		return $worked;
	}

	/** One job, with the checks of the function that does it. @return string|\WP_Error */
	private static function work( $item ) {
		$source = absint( $item['post'] );
		// A draft: whoever may translate into the language (a translator too, N7); the service: whoever edits it.
		if ( ! ( 'create' === $item['action'] ? Translators::may_translate( $source, $item['language'] ) : current_user_can( 'edit_post', $source ) ) ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi per tradurre questo contenuto.', 'lingua-bridge' ) );
		}
		$row = Storage::find( $source );
		$member = $row ? Storage::find_group_language( $row['group_uuid'], $item['language'] ) : null;
		$target = $member ? absint( $member['object_id'] ) : 0;
		if ( ! $target ) {
			$target = Translations::create_draft( $source, $item['language'] );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			if ( 'create' === $item['action'] ) {
				return __( 'Bozza creata.', 'lingua-bridge' );
			}
		} elseif ( 'create' === $item['action'] ) {
			return __( 'C’era già.', 'lingua-bridge' );
		}
		if ( ! MachineTranslation::configured() ) {
			return new \WP_Error( 'lingua_mt_off', __( 'La traduzione automatica non è configurata (Impostazioni → Traduzione IA).', 'lingua-bridge' ) );
		}
		// C23: a draft from an older version of the original follows its new structure first («Aggiorna la bozza»).
		if ( $member && 'draft' === get_post_status( $target ) && $row ) {
			$from = Xliff::segment( (string) get_post_field( 'post_content', $source ) );
			$to = Xliff::segment( (string) get_post_field( 'post_content', $target ) );
			if ( $from['ok'] && $to['ok'] && array_column( $from['segments'], 'kind' ) !== array_column( $to['segments'], 'kind' ) ) {
				$rebuilt = Updates::rebuild_draft( $target );
				if ( is_wp_error( $rebuilt ) ) {
					return $rebuilt;
				}
			}
		}
		$count = MachineTranslation::translate_post( $target );
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		/* translators: %d: texts translated. */
		return sprintf( __( 'Testi tradotti: %d.', 'lingua-bridge' ), (int) $count );
	}

	/**
	 * The jobs of the current user, counted, with the last ones that failed.
	 *
	 * @return array{queued: int, done: int, failed: int, failures: list<array{id: string, title: string, language: string, message: string}>}
	 */
	public static function summary() {
		$out = array( 'queued' => 0, 'done' => 0, 'failed' => 0, 'failures' => array() );
		foreach ( self::items() as $item ) {
			if ( absint( $item['user'] ) !== get_current_user_id() ) {
				continue;
			}
			$out[ $item['status'] ] = ( $out[ $item['status'] ] ?? 0 ) + 1;
			if ( 'failed' === $item['status'] ) {
				$out['failures'][] = array( 'id' => $item['id'], 'title' => $item['title'], 'language' => $item['language'], 'message' => $item['message'] );
			}
		}
		$out['failures'] = array_slice( $out['failures'], -10 );
		return $out;
	}

	/** The dashboard while it is open: one step of this user's jobs, then the counts (JSON). */
	public static function ajax() {
		check_ajax_referer( 'lingua_bridge_queue', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Non hai i permessi necessari.', 'lingua-bridge' ) ), 403 );
		}
		self::step( get_current_user_id() );
		wp_send_json_success( self::summary() );
	}

	/** «Riprova» (a failed job back in the queue) and «Togli i finiti» (done and failed jobs of this user go). */
	public static function handle() {
		check_admin_referer( 'lingua_bridge_queue' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$job = isset( $_POST['job'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['job'] ) ) : '';
		$items = self::items();
		foreach ( $items as $index => $item ) {
			if ( absint( $item['user'] ) !== get_current_user_id() ) {
				continue;
			}
			if ( 'retry' === $do && 'failed' === $item['status'] && ( '' === $job || $job === $item['id'] ) ) {
				$items[ $index ]['status'] = 'queued';
				$items[ $index ]['tries'] = 0;
				$items[ $index ]['message'] = '';
			} elseif ( 'clear' === $do && 'queued' !== $item['status'] ) {
				unset( $items[ $index ] );
			}
		}
		self::store( $items );
		self::schedule();
		wp_safe_redirect( admin_url( 'admin.php?page=lingua-bridge' ) );
		exit;
	}
}
