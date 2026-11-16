<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WordPress side of the AI connector (CONNECTOR.md). An agent on the user's computer (Claude Code, Codex,
 * Opencode, any MCP client) talks to connector/lingua-bridge-connector.mjs on 127.0.0.1; the admin tab the user
 * linked relays each request here, same-origin, with its nonce and cookie. The local process never gets cookies,
 * passwords or keys.
 *
 * Only the operations in OPERATIONS exist, with only their arguments. Every one is checked again here with the
 * capabilities of the user of the tab, on each content it touches. Writing makes drafts only: a published page
 * is never overwritten (a correction is a separate draft) and nothing is ever published.
 */
final class Connector {
	const NONCE_ACTION = 'lingua_bridge_connector';
	const PORT = 7342;

	/** Operation => allowed arguments (the local connector checks the same list). */
	const OPERATIONS = array(
		'status'         => array(),
		'getBrief'       => array(),
		'listWork'       => array( 'language', 'type', 'state', 'limit', 'offset' ),
		'getSource'      => array( 'post_id', 'target_language' ),
		'getPost'        => array( 'post_id' ),
		'saveDraft'      => array( 'source_post_id', 'language', 'title', 'content', 'excerpt', 'slug', 'fields' ),
		'saveCorrection' => array( 'published_post_id', 'language', 'title', 'content', 'excerpt', 'slug', 'fields' ),
		'listStrings'    => array( 'language', 'status', 'limit' ),
		'saveStrings'    => array( 'language', 'items' ),
		'getSegments'    => array( 'post_id', 'target_language' ),
		'saveSegments'   => array( 'source_post_id', 'language', 'title', 'excerpt', 'slug', 'fields', 'segments', 'source_hash' ),
	);

	/** States of a translation in listWork. */
	const STATES = array( 'missing', 'stale', 'draft', 'published' );

	const SECRET_META = 'lingua_bridge_connector_secret';

	public static function init() {
		add_action( 'wp_ajax_lingua_bridge_connector', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_lingua_bridge_connector_rotate', array( __CLASS__, 'rotate' ) );
	}

	/**
	 * The pairing secret of a user (CONNECTOR.md): it goes into the `serve` command they copy, and the tab and the
	 * local connector prove to each other that they know it. One per user, made on first use.
	 */
	public static function pair_secret( $user_id ) {
		$secret = (string) get_user_meta( $user_id, self::SECRET_META, true );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $secret ) ) {
			$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
			update_user_meta( $user_id, self::SECRET_META, $secret );
		}
		return $secret;
	}

	/** A new secret: a connector started with the old one no longer accepts this user's tabs. */
	public static function rotate() {
		check_admin_referer( 'lingua_bridge_connector_rotate' );
		if ( ! self::can_connect() ) {
			wp_die( esc_html__( 'Non hai i permessi per usare il connettore.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		delete_user_meta( get_current_user_id(), self::SECRET_META );
		self::pair_secret( get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=lingua-bridge-ai&lb_rotated=1' ) );
		exit;
	}

	/** The origin of the admin pages, the only one the connector accepts tabs from. */
	public static function admin_origin() {
		$parts = wp_parse_url( admin_url() );
		$origin = strtolower( $parts['scheme'] ?? 'https' ) . '://' . strtolower( $parts['host'] ?? '' );
		return isset( $parts['port'] ) ? $origin . ':' . absint( $parts['port'] ) : $origin;
	}

	/** Who may link a tab: whoever can edit content; each operation then checks the content it touches. */
	public static function can_connect() {
		return current_user_can( 'edit_posts' );
	}

	public static function handle() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta: ricarica la pagina di Lingua Bridge e collega di nuovo.', 'lingua-bridge' ) ), 403 );
		}
		if ( ! self::can_connect() ) {
			wp_send_json_error( array( 'message' => __( 'Non hai i permessi per usare il connettore.', 'lingua-bridge' ) ), 403 );
		}
		$operation = isset( $_POST['op'] ) && is_string( $_POST['op'] ) ? sanitize_text_field( wp_unslash( $_POST['op'] ) ) : '';
		$raw = isset( $_POST['args'] ) && is_string( $_POST['args'] ) ? json_decode( wp_unslash( $_POST['args'] ), true ) : array();
		$result = self::run( $operation, is_array( $raw ) ? $raw : null );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}

	/** One operation with its arguments, as the current user. */
	public static function run( $operation, $args ) {
		if ( ! is_string( $operation ) || ! isset( self::OPERATIONS[ $operation ] ) ) {
			return new \WP_Error( 'lingua_connector_operation', __( 'Operazione sconosciuta.', 'lingua-bridge' ) );
		}
		if ( ! is_array( $args ) || ( array() !== $args && array_keys( $args ) === range( 0, count( $args ) - 1 ) ) ) {
			return new \WP_Error( 'lingua_connector_arguments', __( 'Gli argomenti devono essere un oggetto.', 'lingua-bridge' ) );
		}
		foreach ( array_keys( $args ) as $name ) {
			if ( ! in_array( $name, self::OPERATIONS[ $operation ], true ) ) {
				/* translators: 1: operation, 2: argument name. */
				return new \WP_Error( 'lingua_connector_arguments', sprintf( __( '%1$s: argomento sconosciuto «%2$s».', 'lingua-bridge' ), $operation, $name ) );
			}
		}
		switch ( $operation ) {
			case 'status':
				return self::status();
			case 'getBrief':
				return self::brief();
			case 'listWork':
				return self::list_work( $args );
			case 'getSource':
				return self::get_source( $args );
			case 'getPost':
				return self::get_post( $args );
			case 'saveDraft':
				return self::save_draft( $args );
			case 'saveCorrection':
				return self::save_correction( $args );
			case 'listStrings':
				return self::list_strings( $args );
			case 'saveStrings':
				return self::save_strings( $args );
			case 'getSegments':
				return self::get_segments( $args );
			case 'saveSegments':
				return self::save_segments( $args );
		}
		return new \WP_Error( 'lingua_connector_operation', __( 'Operazione sconosciuta.', 'lingua-bridge' ) );
	}

	private static function status() {
		$user = wp_get_current_user();
		return array(
			'plugin'   => 'lingua-bridge',
			'version'  => LINGUA_BRIDGE_VERSION,
			'site'     => get_bloginfo( 'name' ),
			'user'     => $user->display_name,
			'default'  => Languages::default_code(),
			'languages' => self::languages(),
		);
	}

	private static function languages() {
		$out = array();
		foreach ( Languages::all() as $code => $label ) {
			$out[] = array( 'code' => $code, 'label' => $label, 'default' => Languages::default_code() === $code );
		}
		return $out;
	}

	/** What the agent needs before translating anything here: languages, context, glossary, rules, the work. */
	private static function brief() {
		$settings = Languages::settings();
		$glossary = array();
		foreach ( isset( $settings['translation_glossary'] ) && is_array( $settings['translation_glossary'] ) ? $settings['translation_glossary'] : array() as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['source_term'] ) && ! empty( $entry['target_term'] ) ) {
				$glossary[] = array(
					'from' => (string) ( $entry['source_language'] ?? '' ),
					'to'   => (string) ( $entry['target_language'] ?? '' ),
					'term' => (string) $entry['source_term'],
					'use'  => (string) $entry['target_term'],
					'note' => (string) ( $entry['note'] ?? '' ),
				);
			}
		}
		$types = array();
		foreach ( Admin::editable_post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object && current_user_can( $object->cap->edit_posts ) ) {
				$types[] = array( 'type' => $type, 'label' => $object->labels->name );
			}
		}
		$work = self::work_summary();
		return array(
			'site'         => array( 'name' => get_bloginfo( 'name' ), 'description' => get_bloginfo( 'description' ), 'url' => home_url( '/' ) ),
			'default'      => Languages::default_code(),
			'languages'    => self::languages(),
			'prefixes'     => Routing::enabled(),
			'context'      => isset( $settings['translation_context'] ) ? (string) $settings['translation_context'] : '',
			'instructions' => isset( $settings['translation_instructions'] ) ? (string) $settings['translation_instructions'] : '',
			'glossary'     => $glossary,
			'types'        => $types,
			'work'         => $work,
		);
	}

	/**
	 * Per target language, how many of the user's recent originals miss a translation, have an outdated one, or a
	 * draft waiting (the dashboard summary and the brief).
	 *
	 * @return array{scanned: int, totals: array<string, array<string, int>>}
	 */
	public static function work_summary() {
		// A few minutes of cache per user; any saved content or changed link makes a new key.
		$cache_key = 'lb_summary_' . get_current_user_id() . '_' . absint( get_option( Storage::CONTENT_VERSION_OPTION, 0 ) );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['published'] ) ) {
			return $cached;
		}
		$summary = self::count_work();
		set_transient( $cache_key, $summary, 5 * MINUTE_IN_SECONDS );
		return $summary;
	}

	/** Stale candidates checked at most (the original changed after the translation): beyond, the count says «+». */
	const STALE_CHECKS = 500;

	/**
	 * The counts of the summary from the relations table, not from a sample (D12 of the 1.5.10 verification): the
	 * originals the user can edit, and per language how many miss a translation, have a draft, are published or
	 * outdated. Outdated needs the original's fingerprint: only the translations whose original changed after them
	 * are checked, at most STALE_CHECKS.
	 *
	 * @return array{scanned: int, totals: array<string, array<string, int>>, published: array<string, int>, complete: bool}
	 */
	private static function count_work() {
		global $wpdb;
		$summary = array( 'scanned' => 0, 'totals' => array(), 'published' => array(), 'complete' => true );
		$types = Admin::editable_post_types();
		if ( ! $types || ! Storage::is_available() ) {
			return $summary;
		}
		$table = Storage::table();
		$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$statuses = "'publish', 'draft', 'pending', 'private', 'future'";
		$author = current_user_can( 'edit_others_posts' ) ? '' : $wpdb->prepare( ' AND sp.post_author = %d', get_current_user_id() );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, fixed statuses and placeholders only.
		$originals = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE(r.language, %s) AS language, COUNT(*) AS n FROM {$wpdb->posts} sp LEFT JOIN $table r ON r.object_id = sp.ID WHERE sp.post_type IN ($in) AND sp.post_status IN ($statuses) AND ( r.object_id IS NULL OR r.object_id = r.source_id )$author GROUP BY COALESCE(r.language, %s)", array_merge( array( Languages::default_code() ), $types, array( Languages::default_code() ) ) ), ARRAY_A );
		$translated = $wpdb->get_results( $wpdb->prepare( "SELECT t.language, IF(tp.post_status = 'publish', 1, 0) AS online, COUNT(*) AS n FROM $table t INNER JOIN {$wpdb->posts} tp ON tp.ID = t.object_id INNER JOIN {$wpdb->posts} sp ON sp.ID = t.source_id WHERE t.object_id <> t.source_id AND tp.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND sp.post_type IN ($in) AND sp.post_status IN ($statuses)$author GROUP BY t.language, online", $types ), ARRAY_A );
		$candidates = $wpdb->get_results( $wpdb->prepare( "SELECT t.*, tp.post_status AS lb_status FROM $table t INNER JOIN {$wpdb->posts} tp ON tp.ID = t.object_id INNER JOIN {$wpdb->posts} sp ON sp.ID = t.source_id WHERE t.object_id <> t.source_id AND tp.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND sp.post_type IN ($in) AND sp.post_status IN ($statuses) AND sp.post_modified_gmt > t.updated_at$author LIMIT %d", array_merge( $types, array( self::STALE_CHECKS + 1 ) ) ), ARRAY_A );
		// phpcs:enable
		$by_language = array();
		foreach ( is_array( $originals ) ? $originals : array() as $row ) {
			$by_language[ $row['language'] ] = (int) $row['n'];
		}
		$summary['scanned'] = array_sum( $by_language );
		$counts = array();
		foreach ( is_array( $translated ) ? $translated : array() as $row ) {
			$counts[ $row['language'] ][ $row['online'] ? 'published' : 'draft' ] = (int) $row['n'];
		}
		$stale = array();
		$candidates = is_array( $candidates ) ? $candidates : array();
		if ( count( $candidates ) > self::STALE_CHECKS ) {
			$summary['complete'] = false;
			$candidates = array_slice( $candidates, 0, self::STALE_CHECKS );
		}
		foreach ( $candidates as $row ) {
			if ( Translations::is_stale( $row ) ) {
				$stale[ $row['language'] ][ 'publish' === $row['lb_status'] ? 'published' : 'draft' ] = ( $stale[ $row['language'] ][ 'publish' === $row['lb_status'] ? 'published' : 'draft' ] ?? 0 ) + 1;
			}
		}
		foreach ( array_keys( Languages::all() ) as $code ) {
			if ( Languages::default_code() === $code ) {
				continue;
			}
			// Originals of this very language have nothing to translate into it.
			$originals_here = $summary['scanned'] - (int) ( $by_language[ $code ] ?? 0 );
			$published = (int) ( $counts[ $code ]['published'] ?? 0 );
			$draft = (int) ( $counts[ $code ]['draft'] ?? 0 );
			$stale_published = (int) ( $stale[ $code ]['published'] ?? 0 );
			$stale_draft = (int) ( $stale[ $code ]['draft'] ?? 0 );
			$summary['totals'][ $code ] = array(
				'missing' => max( 0, $originals_here - $published - $draft ),
				'stale'   => $stale_published + $stale_draft,
				'draft'   => max( 0, $draft - $stale_draft ),
			);
			$summary['published'][ $code ] = max( 0, $published - $stale_published );
		}
		return $summary;
	}

	/**
	 * The originals the user can edit, with the state of each language: missing, stale (the original changed after
	 * the translation), draft, published. Filtered by language, type and state; newest changes first.
	 */
	private static function list_work( $args ) {
		$language = isset( $args['language'] ) && is_string( $args['language'] ) ? sanitize_key( $args['language'] ) : '';
		if ( '' !== $language && ( ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) ) {
			return new \WP_Error( 'lingua_connector_language', __( 'Lingua di destinazione non configurata.', 'lingua-bridge' ) );
		}
		$types = Admin::editable_post_types();
		$type = isset( $args['type'] ) && is_string( $args['type'] ) ? sanitize_key( $args['type'] ) : '';
		if ( '' !== $type && ! in_array( $type, $types, true ) ) {
			return new \WP_Error( 'lingua_connector_type', __( 'Tipo di contenuto non traducibile.', 'lingua-bridge' ) );
		}
		$states = isset( $args['state'] ) && is_string( $args['state'] ) ? array_values( array_intersect( array_map( 'trim', explode( ',', $args['state'] ) ), self::STATES ) ) : array( 'missing', 'stale', 'draft' );
		if ( empty( $states ) ) {
			return new \WP_Error( 'lingua_connector_state', __( 'Stato sconosciuto: usa missing, stale, draft o published.', 'lingua-bridge' ) );
		}
		$limit = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;
		// F7: the items after the first `offset` that match (an XLIFF of more than 100 contents, in files).
		$offset = isset( $args['offset'] ) && is_numeric( $args['offset'] ) ? max( 0, min( 299, (int) $args['offset'] ) ) : 0;
		$query_args = array(
			'post_type'           => '' !== $type ? $type : $types,
			'post_status'         => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'      => 300,
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'lb_source_only'      => true,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		);
		// A translator (N7) works on the published originals of others, into their languages only.
		$translator = Translators::is_translator( get_current_user_id() );
		if ( $translator ) {
			$query_args['post_status'] = 'publish';
		} elseif ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		}
		$query = new \WP_Query( $query_args );
		$targets = array();
		foreach ( array_keys( Translators::targets() ) as $code ) {
			if ( '' === $language || $language === $code ) {
				$targets[] = $code;
			}
		}
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! ( $translator ? Translators::may_translate_any( $post->ID ) : current_user_can( 'edit_post', $post->ID ) ) ) {
				continue;
			}
			$row = Storage::find( $post->ID );
			$by_language = array();
			foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
				$by_language[ $member['language'] ] = $member;
			}
			$languages = array();
			$wanted = false;
			foreach ( $targets as $code ) {
				if ( $row && $code === $row['language'] ) {
					continue;
				}
				$state = self::state_of( $by_language[ $code ] ?? null );
				$languages[ $code ] = $state;
				$wanted = $wanted || in_array( $state['state'], $states, true );
			}
			if ( ! $wanted ) {
				continue;
			}
			if ( $offset > 0 ) {
				--$offset;
				continue;
			}
			$items[] = array(
				'source_id' => $post->ID,
				'title'     => get_the_title( $post ),
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'language'  => $row ? $row['language'] : Languages::default_code(),
				'modified'  => get_post_modified_time( 'c', true, $post ),
				'languages' => $languages,
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return array( 'items' => $items, 'scanned' => count( $query->posts ), 'states' => $states );
	}

	private static function state_of( $member ) {
		$post = $member ? get_post( absint( $member['object_id'] ) ) : null;
		if ( ! $post ) {
			return array( 'state' => 'missing', 'id' => 0 );
		}
		$stale = Translations::is_stale( $member );
		$state = $stale ? 'stale' : ( 'publish' === $post->post_status ? 'published' : 'draft' );
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			// A version this user cannot edit: whether it is online, nothing about a draft or a private page.
			return array( 'state' => 'publish' === $post->post_status && '' === (string) $post->post_password ? 'published' : 'draft', 'id' => 0, 'status' => '' );
		}
		return array( 'state' => $state, 'id' => $post->ID, 'status' => $post->post_status );
	}

	private static function get_source( $args ) {
		$input = array(
			'post_id'         => isset( $args['post_id'] ) && is_numeric( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
			'target_language' => isset( $args['target_language'] ) && is_string( $args['target_language'] ) ? sanitize_key( $args['target_language'] ) : '',
		);
		if ( ! $input['post_id'] || ! Abilities::can_edit_source( $input ) ) {
			return new \WP_Error( 'lingua_connector_forbidden', __( 'Non hai i permessi per leggere questo contenuto.', 'lingua-bridge' ) );
		}
		$source = Abilities::get_source( $input );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		// What exists already in the target language: a draft to improve, or a published page to correct.
		$row = Storage::find( $input['post_id'] );
		$existing = $row && '' !== $input['target_language'] ? Storage::find_group_language( $row['group_uuid'], $input['target_language'] ) : null;
		$source['existing'] = $existing ? self::state_of( $existing ) : array( 'state' => 'missing', 'id' => 0 );
		$source['report'] = Fields::support_report( $input['post_id'] );
		// Sentences of this content already translated elsewhere: use them, so the site says things one way.
		$memory = array();
		if ( '' !== $input['target_language'] ) {
			$post = get_post( $input['post_id'] );
			$texts = array_merge( array( $post->post_title ), array_column( Xliff::segment( $post->post_content )['segments'], 'text' ) );
			foreach ( Memory::lookup( $texts, $input['target_language'] ) as $from => $to ) {
				$memory[] = array( 'source' => $from, 'target' => $to );
			}
		}
		$source['memory'] = array_slice( $memory, 0, 300 );
		return $source;
	}

	/**
	 * getSegments: the original as numbered texts (Abilities::get_segments()), with what exists in the target
	 * language, the field report and the sentences the memory knows, as getSource.
	 */
	private static function get_segments( $args ) {
		$source = self::get_source( $args );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$segments = Abilities::get_segments( array( 'post_id' => $source['post_id'], 'target_language' => $source['target_language'] ) );
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}
		foreach ( array( 'existing', 'report', 'memory' ) as $key ) {
			$segments[ $key ] = $source[ $key ];
		}
		return $segments;
	}

	/** saveSegments: the draft from the translated texts (Abilities::save_segments()), with the checks of saveDraft. */
	private static function save_segments( $args ) {
		$input = array( 'source_post_id' => isset( $args['source_post_id'] ) && is_numeric( $args['source_post_id'] ) ? absint( $args['source_post_id'] ) : 0 );
		foreach ( array( 'language', 'title', 'excerpt', 'slug', 'source_hash' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				if ( ! is_string( $args[ $key ] ) ) {
					/* translators: %s: argument name. */
					return new \WP_Error( 'lingua_connector_arguments', sprintf( __( '«%s» deve essere testo.', 'lingua-bridge' ), $key ) );
				}
				$input[ $key ] = $args[ $key ];
			}
		}
		foreach ( array( 'fields', 'segments' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				if ( ! is_array( $args[ $key ] ) ) {
					return new \WP_Error( 'lingua_connector_arguments', __( 'I campi tradotti devono essere una lista.', 'lingua-bridge' ) );
				}
				$input[ $key ] = $args[ $key ];
			}
		}
		if ( ! isset( $input['language'], $input['title'], $input['segments'] ) || '' === trim( $input['title'] ) ) {
			return new \WP_Error( 'lingua_connector_arguments', __( 'Servono lingua, titolo e testi della traduzione.', 'lingua-bridge' ) );
		}
		$input['language'] = sanitize_key( $input['language'] );
		if ( ! $input['source_post_id'] || ! Abilities::can_edit_source( $input ) ) {
			return new \WP_Error( 'lingua_connector_forbidden', __( 'Non hai i permessi per creare questa bozza.', 'lingua-bridge' ) );
		}
		return Abilities::save_segments( $input );
	}

	/** A content as it is now (a translation to revise), when the user can edit it. */
	private static function get_post( $args ) {
		$post_id = isset( $args['post_id'] ) && is_numeric( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! Translations::supports_post( $post ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'lingua_connector_forbidden', __( 'Contenuto non trovato o non modificabile.', 'lingua-bridge' ) );
		}
		$row = Storage::find( $post_id );
		return array(
			'post_id'  => $post_id,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'language' => $row ? $row['language'] : Languages::default_code(),
			'title'    => $post->post_title,
			'content'  => $post->post_content,
			'excerpt'  => $post->post_excerpt,
			'slug'     => $post->post_name,
			'fields'   => Fields::translatable_values( $post_id ),
		);
	}

	/** The texts outside contents (Strings): only for whoever manages the site, as in its admin screen. */
	private static function strings_language( $args ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'lingua_connector_forbidden', __( 'Le stringhe del sito le traduce solo chi amministra il sito.', 'lingua-bridge' ) );
		}
		$language = isset( $args['language'] ) && is_string( $args['language'] ) ? sanitize_key( $args['language'] ) : '';
		if ( ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			return new \WP_Error( 'lingua_connector_language', __( 'Lingua di destinazione non configurata.', 'lingua-bridge' ) );
		}
		return $language;
	}

	private static function list_strings( $args ) {
		$language = self::strings_language( $args );
		if ( is_wp_error( $language ) ) {
			return $language;
		}
		Strings::seed();
		$states = isset( $args['status'] ) && is_string( $args['status'] ) ? array_map( 'trim', explode( ',', $args['status'] ) ) : array( 'missing' );
		$limit = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? (int) $args['limit'] : 100;
		$result = Strings::query( $language, array( 'status' => $states, 'limit' => $limit ) );
		$result['counts'] = Strings::counts( $language );
		return $result;
	}

	/** Translations of strings from an agent: drafts, shown on the site only once approved in «Stringhe». */
	private static function save_strings( $args ) {
		$language = self::strings_language( $args );
		if ( is_wp_error( $language ) ) {
			return $language;
		}
		$items = isset( $args['items'] ) && is_array( $args['items'] ) ? array_slice( $args['items'], 0, 200 ) : null;
		if ( null === $items ) {
			return new \WP_Error( 'lingua_connector_arguments', __( 'Servono gli elementi: [{id, translation}].', 'lingua-bridge' ) );
		}
		$saved = 0;
		$errors = array();
		foreach ( $items as $item ) {
			$id = is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ? absint( $item['id'] ) : 0;
			$row = $id ? Strings::get( $id ) : null;
			if ( ! $row || $row['language'] !== $language || ! isset( $item['translation'] ) || ! is_string( $item['translation'] ) ) {
				$errors[] = $id;
				continue;
			}
			if ( 'published' === $row['status'] ) {
				$errors[] = $id; // A published string is changed by a person, in «Stringhe».
				continue;
			}
			Strings::save( $id, $item['translation'], 'draft' );
			++$saved;
		}
		return array( 'saved' => $saved, 'skipped' => $errors, 'approve' => admin_url( 'admin.php?page=lingua-bridge-strings&status=draft&language=' . $language ) );
	}

	private static function values( $args ) {
		$input = array();
		foreach ( array( 'language', 'title', 'content', 'excerpt', 'slug' ) as $key ) {
			if ( isset( $args[ $key ] ) ) {
				if ( ! is_string( $args[ $key ] ) ) {
					/* translators: %s: argument name. */
					return new \WP_Error( 'lingua_connector_arguments', sprintf( __( '«%s» deve essere testo.', 'lingua-bridge' ), $key ) );
				}
				$input[ $key ] = $args[ $key ];
			}
		}
		if ( isset( $args['fields'] ) ) {
			if ( ! is_array( $args['fields'] ) ) {
				return new \WP_Error( 'lingua_connector_arguments', __( 'I campi tradotti devono essere una lista.', 'lingua-bridge' ) );
			}
			$input['fields'] = $args['fields'];
		}
		if ( ! isset( $input['language'], $input['title'], $input['content'] ) || '' === trim( $input['title'] ) ) {
			return new \WP_Error( 'lingua_connector_arguments', __( 'Servono lingua, titolo e contenuto della traduzione.', 'lingua-bridge' ) );
		}
		$input['language'] = sanitize_key( $input['language'] );
		return $input;
	}

	private static function save_draft( $args ) {
		$input = self::values( $args );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$input['source_post_id'] = isset( $args['source_post_id'] ) && is_numeric( $args['source_post_id'] ) ? absint( $args['source_post_id'] ) : 0;
		if ( ! $input['source_post_id'] || ! Abilities::can_edit_source( $input ) ) {
			return new \WP_Error( 'lingua_connector_forbidden', __( 'Non hai i permessi per creare questa bozza.', 'lingua-bridge' ) );
		}
		return Abilities::save_draft( $input );
	}

	private static function save_correction( $args ) {
		$input = self::values( $args );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$input['published_post_id'] = isset( $args['published_post_id'] ) && is_numeric( $args['published_post_id'] ) ? absint( $args['published_post_id'] ) : 0;
		if ( ! $input['published_post_id'] || ! Abilities::can_edit_source( $input ) ) {
			return new \WP_Error( 'lingua_connector_forbidden', __( 'Non hai i permessi per correggere questo contenuto.', 'lingua-bridge' ) );
		}
		return Abilities::save_correction_draft( $input );
	}
}
