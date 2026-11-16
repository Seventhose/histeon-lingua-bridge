<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The visual editor on the site (docs/FRONTEND-EDITOR.md): a translation opens in its own page, with the real
 * theme, in «translation mode» (`/?lb_translate=<id>`, drafts too, only for whoever can edit it). Click a text,
 * write its translation, see it in place.
 *
 * Where a text goes:
 * - in the translation's content, when it is there: the whole text, every place it appears (the same text, the
 *   same translation), in the HTML of the blocks or in their JSON attributes (Histeon, Etch);
 * - otherwise it is a text of the theme, of a menu or of the site: a string (Strings), for administrators.
 *
 * A translation is plain text: it is escaped where it lands, so the editor cannot add markup to a page.
 */
final class Editor {
	const QUERY_VAR = 'lb_translate';
	const NONCE = 'lingua_bridge_editor';
	/** Texts left as the original on purpose (a name, a brand): sha1 of the normalized text. */
	const KEPT_META = '_lingua_bridge_kept';
	/** Typographic characters WordPress prints (wptexturize) and what the stored text may have instead. */
	const TEXTURIZED = array(
		"\u{2019}" => array( "'", '&#8217;', '&rsquo;' ),
		"\u{2018}" => array( "'", '&#8216;', '&lsquo;' ),
		"\u{201C}" => array( '"', '&#8220;', '&ldquo;', '&quot;' ),
		"\u{201D}" => array( '"', '&#8221;', '&rdquo;', '&quot;' ),
		"\u{2013}" => array( '-', '--', '&#8211;', '&ndash;' ),
		"\u{2014}" => array( '---', '--', '&#8212;', '&mdash;' ),
		"\u{2026}" => array( '...', '&#8230;', '&hellip;' ),
		"\u{00A0}" => array( ' ', '&nbsp;', '&#160;' ),
		'&'        => array( '&amp;', '&#038;', '&#38;' ),
		'"'        => array( '&quot;', '&#34;' ),
		"'"        => array( '&#039;', '&#39;' ),
	);

	private static $post = 0;

	public static function init() {
		add_filter( 'request', array( __CLASS__, 'request' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 85 );
		add_action( 'wp_ajax_lingua_bridge_editor_save', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_seo', array( __CLASS__, 'save_seo' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_publish', array( __CLASS__, 'publish' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_keep', array( __CLASS__, 'keep' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_memory', array( __CLASS__, 'apply_memory' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_update', array( __CLASS__, 'update' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_mark_updated', array( __CLASS__, 'mark_updated' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_mode', array( __CLASS__, 'mode' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_machine', array( __CLASS__, 'machine' ) );
		add_action( 'wp_ajax_lingua_bridge_editor_suggest', array( __CLASS__, 'suggest' ) );
		add_action( 'admin_post_lingua_bridge_quick_create', array( __CLASS__, 'quick_create' ) );
		add_action( 'send_headers', array( __CLASS__, 'headers' ) );
		add_action( 'wp_footer', array( __CLASS__, 'components' ), 5 );
	}

	/** The translation asked for, if the current user may edit it. */
	public static function requested() {
		if ( is_admin() || ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- opens a view; writes have their own nonce.
			return 0;
		}
		$id = absint( $_GET[ self::QUERY_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post = $id ? get_post( $id ) : null;
		$row = $post ? Storage::find( $id ) : null;
		if ( ! $post || ! $row || Languages::default_code() === $row['language'] || ! Translations::supports_post( $post ) || ! is_user_logged_in() || ! current_user_can( 'edit_post', $id ) ) {
			return 0;
		}
		return $id;
	}

	public static function active() {
		return self::$post > 0;
	}

	/** Translation mode: the main query is the translation, as WordPress previews a draft. */
	public static function request( $query_vars ) {
		$id = self::requested();
		if ( ! $id ) {
			return $query_vars;
		}
		self::$post = $id;
		$post = get_post( $id );
		Routing::force_language( Storage::find( $id )['language'] );
		return 'page' === $post->post_type
			? array( 'page_id' => $id, 'preview' => 'true' )
			: array( 'p' => $id, 'post_type' => $post->post_type, 'preview' => 'true' );
	}

	/** Never cached, never indexed, never framed by another site. */
	public static function headers() {
		if ( self::active() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
			// The editor saves with a click: no other site may frame it and trick that click.
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( "Content-Security-Policy: frame-ancestors 'self'" );
		}
	}

	/** After the page is drawn: the translated components on it, so a text of theirs is saved into them. */
	public static function components() {
		if ( self::active() ) {
			wp_add_inline_script( 'lingua-bridge-editor', 'window.LinguaBridgeEditorComponents = ' . wp_json_encode( Frontend::rendered_components() ) . ';', 'before' );
		}
	}

	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		$id = self::$post;
		$post = get_post( $id );
		$language = Storage::find( $id )['language'];
		wp_enqueue_style( 'lingua-bridge-editor', plugins_url( 'assets/editor.css', LINGUA_BRIDGE_FILE ), array(), LINGUA_BRIDGE_VERSION );
		wp_enqueue_script( 'lingua-bridge-editor', plugins_url( 'assets/editor.js', LINGUA_BRIDGE_FILE ), array(), LINGUA_BRIDGE_VERSION, true );
		// The editor is the person's tool: its texts follow their profile language, not the page's.
		I18n::in_user_language(
			static function () use ( $id, $post, $language ) {
				wp_localize_script(
					'lingua-bridge-editor',
					'LinguaBridgeEditor',
					array(
						'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
						'nonce'      => wp_create_nonce( self::NONCE . '_' . $id ),
						'post'       => $id,
						'language'   => Languages::label( $language ),
						'code'       => $language,
						'sourceCode' => Languages::default_code(),
						'status'     => $post->post_status,
						'canPublish' => 'publish' !== $post->post_status && self::can_publish( $id ),
						'canStrings' => current_user_can( 'manage_options' ),
						'editUrl'    => (string) get_edit_post_link( $id, 'raw' ),
						'texts'      => self::page_texts( $id ),
						'seo'        => self::seo_texts( $id ),
						'independent' => Translations::is_independent( $id ),
						'machine'    => MachineTranslation::configured() && MachineTranslation::can_use(),
						// The original's sentences only to whoever may edit the original.
						'updates'    => Translations::is_stale( Storage::find( $id ) ) && current_user_can( 'edit_post', absint( Storage::find( $id )['source_id'] ) ) ? Updates::changes( $id ) : null,
						'glossary'   => self::glossary( Storage::find( $id ) ),
						'created'    => isset( $_GET['lb_new'] ) ? self::creation_notes( $id ) : null,
						// What a machine changed besides texts (Translations::structure_differences()): to check.
						'structure'  => array_values( array_filter( (array) get_post_meta( $id, Translations::STRUCTURE_META, true ), 'is_string' ) ),
						'canEdit'    => true, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a notice after creating.
						'exitUrl'    => 'publish' === $post->post_status ? (string) get_permalink( $id ) : (string) get_edit_post_link( $id, 'raw' ),
						'labels'     => array(
							'title'        => __( 'Traduci la pagina', 'lingua-bridge' ),
							'draft'        => __( 'bozza', 'lingua-bridge' ),
							'published'    => __( 'pubblicata', 'lingua-bridge' ),
							'pick'         => __( 'Clicca un testo della pagina per tradurlo. I link non si aprono finché il pannello è attivo.', 'lingua-bridge' ),
							'current'      => __( 'Testo nella pagina', 'lingua-bridge' ),
							'translation'  => __( 'Traduzione', 'lingua-bridge' ),
							'save'         => __( 'Salva', 'lingua-bridge' ),
							'seoTitle'     => __( 'Meta della pagina (titolo e descrizione per motori di ricerca e social)', 'lingua-bridge' ),
							'seoSaved'     => __( 'Salvato nei meta della pagina.', 'lingua-bridge' ),
							'saved'        => __( 'Salvato.', 'lingua-bridge' ),
							'inContent'    => __( 'Salvato nel contenuto (%d volte).', 'lingua-bridge' ),
							'asString'     => __( 'Salvato come testo del sito (Stringhe): vale su tutte le pagine di questa lingua.', 'lingua-bridge' ),
							/* translators: %s: what changed besides texts. */
							'structureNote' => __( 'Da controllare prima di pubblicare, oltre ai testi: %s', 'lingua-bridge' ),
							/* translators: %s: the component's title. */
							'inComponent'  => __( 'Salvato nel componente «%s»: vale dove il componente compare.', 'lingua-bridge' ),
							'publish'      => __( 'Pubblica la traduzione', 'lingua-bridge' ),
							'publishedNow' => __( 'Traduzione pubblicata.', 'lingua-bridge' ),
							'edit'         => __( 'Apri nell’editor', 'lingua-bridge' ),
							'exit'         => __( 'Esci', 'lingua-bridge' ),
							'pause'        => __( 'Naviga', 'lingua-bridge' ),
							'resume'       => __( 'Traduci', 'lingua-bridge' ),
							'attribute'    => __( 'Testo alternativo e attributi', 'lingua-bridge' ),
							'error'        => __( 'Non salvato: %s', 'lingua-bridge' ),
							'original'     => __( 'Originale', 'lingua-bridge' ),
							'noOriginal'   => __( 'Originale non riconosciuto per questo testo (la struttura della traduzione è cambiata).', 'lingua-bridge' ),
							'progress'     => __( '%1$d di %2$d testi tradotti', 'lingua-bridge' ),
							'allDone'      => __( 'Tutti i testi di questa pagina sono tradotti.', 'lingua-bridge' ),
							'next'         => __( 'Prossimo da tradurre', 'lingua-bridge' ),
							'keep'         => __( 'Lascia così', 'lingua-bridge' ),
							'confirm'      => __( 'Conferma', 'lingua-bridge' ),
							'reviewNote'   => __( 'Scritta dalla memoria: confermala o correggila.', 'lingua-bridge' ),
							'kept'         => __( 'Resta uguale all’originale.', 'lingua-bridge' ),
							'glossaryHint' => __( 'Glossario', 'lingua-bridge' ),
							'createdNote'  => __( 'Bozza creata: è una copia dell’originale. I testi in giallo sono da tradurre.', 'lingua-bridge' ),
							'missingNote'  => __( 'Non copiati nella bozza: %s.', 'lingua-bridge' ),
							'saveNext'     => __( 'Ctrl+Invio salva e passa al prossimo.', 'lingua-bridge' ),
							'memory'       => __( 'Dalla memoria', 'lingua-bridge' ),
							'useMemory'    => __( 'Usa', 'lingua-bridge' ),
							/* translators: %d: percent. */
							'similar'      => __( 'Dalla memoria, simile al %d%%: controlla le differenze', 'lingua-bridge' ),
							'applyMemory'  => __( 'Applica la memoria (%d)', 'lingua-bridge' ),
							'fromMemory'   => __( '%d testi sono già tradotti dalla memoria: controllali.', 'lingua-bridge' ),
							'changed'      => __( 'L’originale è cambiato dopo la traduzione: %d frasi nuove o cambiate.', 'lingua-bridge' ),
							'changedUnknown' => __( 'L’originale è cambiato dopo la traduzione. Questa traduzione è precedente alla 1.4, quindi non so quali frasi: rivedi la pagina.', 'lingua-bridge' ),
							'titleChanged' => __( 'Titolo', 'lingua-bridge' ),
							'before'       => __( 'Prima', 'lingua-bridge' ),
							'now'          => __( 'Ora', 'lingua-bridge' ),
							'go'           => __( 'Vai', 'lingua-bridge' ),
							'newSentence'  => __( 'nuova nell’originale: aggiungila nell’editor', 'lingua-bridge' ),
							'updateDraft'  => __( 'Aggiorna la bozza', 'lingua-bridge' ),
							'markUpdated'  => __( 'Segna come aggiornata', 'lingua-bridge' ),
							'updated'      => __( 'Traduzione aggiornata.', 'lingua-bridge' ),
							'follows'      => __( 'Segue l’originale: frasi allineate, memoria, aggiornamenti.', 'lingua-bridge' ),
							'independentNote' => __( 'Pagina indipendente: contenuto suo, collegata all’originale solo come sua versione in questa lingua.', 'lingua-bridge' ),
							'makeIndependent' => __( 'Rendi indipendente', 'lingua-bridge' ),
							'makeFollow'   => __( 'Segui l’originale', 'lingua-bridge' ),
							'machinePage'  => __( 'Traduci automaticamente', 'lingua-bridge' ),
							'machineDone'  => __( '%d testi tradotti automaticamente: rivedili.', 'lingua-bridge' ),
							'suggest'      => __( 'Proposta automatica', 'lingua-bridge' ),
						),
					)
				);
			}
		);
	}

	/** «Traduci questa pagina» in the admin bar: each language, to open in translation mode or to create. */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || ! Translations::supports_post( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$row = Storage::find( $post->ID );
		$language = $row ? $row['language'] : Languages::default_code();
		$bar->add_node( array( 'id' => 'lingua-bridge-translate', 'title' => esc_html__( 'Traduci questa pagina', 'lingua-bridge' ), 'href' => Languages::default_code() !== $language ? esc_url( add_query_arg( self::QUERY_VAR, $post->ID, home_url( '/' ) ) ) : false ) );
		$members = array();
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$members[ $member['language'] ] = absint( $member['object_id'] );
		}
		$source = $row ? absint( $row['source_id'] ) : $post->ID;
		foreach ( Languages::all() as $code => $label ) {
			if ( Languages::default_code() === $code ) {
				continue;
			}
			$target = $members[ $code ] ?? 0;
			$href = $target && current_user_can( 'edit_post', $target )
				? add_query_arg( self::QUERY_VAR, $target, home_url( '/' ) )
				: ( $target ? '' : self::quick_create_url( $source, $code ) );
			if ( '' === $href ) {
				continue;
			}
			$bar->add_node(
				array(
					'parent' => 'lingua-bridge-translate',
					'id'     => 'lingua-bridge-translate-' . sanitize_html_class( $code ),
					'title'  => esc_html( $target ? $label : sprintf( /* translators: %s: language name. */ __( '%s: crea la traduzione', 'lingua-bridge' ), $label ) ),
					'href'   => esc_url( $href ),
				)
			);
		}
	}

	/** A translation from the page: into the content when the text is there, otherwise as a string. */
	/**
	 * C10: the SEO texts of the translation (Fields::seo_fields()) with the original's beside them, for the panel
	 * «Meta della pagina»: only those the original has, and only to whoever may open the original.
	 *
	 * @return list<array{key: string, label: string, type: string, current: string, original: string}>
	 */
	public static function seo_texts( $id ) {
		$row = Storage::find( $id );
		$source_id = $row ? absint( $row['source_id'] ) : 0;
		if ( ! $source_id || ! current_user_can( 'edit_post', $source_id ) ) {
			return array();
		}
		$out = array();
		foreach ( Fields::seo_fields() as $key => $definition ) {
			$original = Fields::seo_value( $source_id, $key );
			if ( '' !== trim( $original ) ) {
				$out[] = array( 'key' => (string) $key, 'label' => $definition[0], 'type' => $definition[1], 'current' => Fields::seo_value( $id, $key ), 'original' => $original );
			}
		}
		return $out;
	}

	/** «Meta della pagina»: one SEO text saved on the translation, its plugin's variables as in the original. */
	public static function save_seo() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::NONCE . '_' . $id, 'nonce' );
		$row = $id ? Storage::find( $id ) : null;
		if ( ! $row || Languages::default_code() === $row['language'] || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Non puoi modificare questa traduzione.', 'lingua-bridge' ) ), 403 );
		}
		$key = isset( $_POST['key'] ) ? (string) wp_unslash( $_POST['key'] ) : '';
		$value = isset( $_POST['value'] ) ? (string) wp_unslash( $_POST['value'] ) : '';
		if ( ! Fields::save_seo_value( $id, $key, $value, absint( $row['source_id'] ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Le variabili del plugin SEO (come %%title%% o %sep%) devono restare quelle dell’originale.', 'lingua-bridge' ) ), 400 );
		}
		wp_send_json_success( array( 'key' => $key ) );
	}

	public static function save() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::NONCE . '_' . $id, 'nonce' );
		$row = $id ? Storage::find( $id ) : null;
		if ( ! $row || Languages::default_code() === $row['language'] || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Non puoi modificare questa traduzione.', 'lingua-bridge' ) ), 403 );
		}
		$old = isset( $_POST['old'] ) ? (string) wp_unslash( $_POST['old'] ) : '';
		$new = Strings::clean( isset( $_POST['new'] ) ? wp_unslash( $_POST['new'] ) : '' );
		$attribute = isset( $_POST['attribute'] ) ? sanitize_key( wp_unslash( $_POST['attribute'] ) ) : '';
		if ( '' === Strings::normalize( $old ) || '' === $new || mb_strlen( $old ) > Strings::MAX_LENGTH ) {
			wp_send_json_error( array( 'message' => __( 'Scrivi una traduzione.', 'lingua-bridge' ) ), 400 );
		}
		if ( '' !== $attribute && ! in_array( $attribute, array( 'alt', 'title', 'placeholder', 'aria-label' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Attributo non traducibile.', 'lingua-bridge' ) ), 400 );
		}
		$post = get_post( $id );
		$result = self::replace( $post->post_content, $old, $new, $attribute );
		// A text lands where text was: if the active markup changed (a ">" inside an attribute), nothing is saved.
		if ( $result['count'] > 0 && is_wp_error( Translations::check_active_markup( array( 'content' => $post->post_content ), array( 'content' => $result['content'] ) ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Questo testo è dentro codice della pagina: traducilo nell’editor.', 'lingua-bridge' ) ), 400 );
		}
		if ( $result['count'] > 0 ) {
			$saved = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $result['content'] ) ), true );
			if ( is_wp_error( $saved ) ) {
				wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
			}
			Storage::update_review_state( $id, 'needs_review' );
			Memory::reviewed( $id, $new );
			// A person wrote on the page: what the memory learns from it is a person's.
			delete_post_meta( $id, Memory::WRITER_META );
			wp_send_json_success( array( 'where' => 'content', 'count' => $result['count'], 'text' => $new ) );
		}
		if ( Strings::normalize( $old ) === Strings::normalize( $post->post_title ) ) {
			wp_update_post( wp_slash( array( 'ID' => $id, 'post_title' => $new ) ) );
			wp_send_json_success( array( 'where' => 'content', 'count' => 1, 'text' => $new ) );
		}
		// A text of a translated component drawn on the page (an Etch header, a footer): saved into that component.
		$components = isset( $_POST['components'] ) ? array_slice( array_filter( array_map( 'absint', explode( ',', (string) wp_unslash( $_POST['components'] ) ) ) ), 0, 50 ) : array();
		foreach ( $components as $component_id ) {
			$component = get_post( $component_id );
			$component_row = $component ? Storage::find( $component_id ) : null;
			if ( ! $component_row || 'wp_block' !== $component->post_type || $component_row['language'] !== $row['language'] || ! current_user_can( 'edit_post', $component_id ) ) {
				continue;
			}
			$in_component = self::replace( $component->post_content, $old, $new, $attribute );
			if ( ! $in_component['count'] || is_wp_error( Translations::check_active_markup( array( 'content' => $component->post_content ), array( 'content' => $in_component['content'] ) ) ) ) {
				continue;
			}
			$saved = wp_update_post( wp_slash( array( 'ID' => $component_id, 'post_content' => $in_component['content'] ) ), true );
			if ( is_wp_error( $saved ) ) {
				wp_send_json_error( array( 'message' => $saved->get_error_message() ), 400 );
			}
			Storage::update_review_state( $component_id, 'needs_review' );
			wp_send_json_success( array( 'where' => 'component', 'count' => $in_component['count'], 'text' => $new, 'component' => get_the_title( $component ) ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Questo testo non è nel contenuto della pagina: è del tema o del sito, e lo traduce chi amministra il sito (Stringhe).', 'lingua-bridge' ) ), 403 );
		}
		$string = self::string_for( $old, $row['language'] );
		if ( ! $string ) {
			wp_send_json_error( array( 'message' => __( 'Testo non riconosciuto.', 'lingua-bridge' ) ), 400 );
		}
		Strings::save( absint( $string['id'] ), $new, 'published' );
		wp_send_json_success( array( 'where' => 'string', 'count' => 1, 'text' => $new ) );
	}

	/** The string row a displayed text belongs to: a translation already shown, its source, or a new source. */
	private static function string_for( $displayed, $language ) {
		global $wpdb;
		$normalized = Strings::normalize( $displayed );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Strings::table() . " WHERE language = %s AND context = 'html' AND translation = %s LIMIT 1", $language, $normalized ), ARRAY_A );
		if ( $row ) {
			return $row;
		}
		Strings::register( 'html', $normalized );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Strings::table() . ' WHERE language = %s AND source_hash = %s', $language, Strings::key( 'html', $normalized ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * The texts of the translation with their original and their state, for the panel: aligned segment by segment
	 * when the translation keeps the original's structure; otherwise only texts still equal to the original are
	 * known (as «to translate»). Plus the site's strings in this language.
	 *
	 * @return list<array{current: string, original: string, state: string, where: string}>
	 */
	public static function page_texts( $id ) {
		$post = get_post( $id );
		$row = Storage::find( $id );
		$source = $row ? get_post( absint( $row['source_id'] ) ) : null;
		if ( ! $post || ! $row || ! $source || ! current_user_can( 'edit_post', $source->ID ) ) {
			return array();
		}
		if ( Translations::is_independent( $id ) ) {
			// Its own content: nothing corresponds to the original sentence by sentence.
			return array();
		}
		$kept = array_flip( (array) get_post_meta( $id, self::KEPT_META, true ) );
		$filled = array_flip( (array) get_post_meta( $id, Memory::FILLED_META, true ) );
		$state = static function ( $current, $original ) use ( $kept, $filled ) {
			if ( isset( $kept[ sha1( Strings::normalize( $current ) ) ] ) ) {
				return 'kept';
			}
			// Written by the memory: to review (to confirm or change), counted as not done yet.
			if ( isset( $filled[ sha1( Strings::normalize( $current ) ) ] ) ) {
				return 'todo';
			}
			return '' !== $original && Strings::normalize( $current ) === Strings::normalize( $original ) && preg_match( '/\p{L}/u', $current ) ? 'todo' : 'done';
		};
		$texts = array( array( 'current' => $post->post_title, 'original' => $source->post_title, 'state' => $state( $post->post_title, $source->post_title ), 'where' => 'title' ) );
		$from = Xliff::segment( $source->post_content );
		$to = Xliff::segment( $post->post_content );
		$aligned = $from['ok'] && $to['ok'] && array_column( $from['segments'], 'kind' ) === array_column( $to['segments'], 'kind' );
		$originals = array();
		foreach ( $from['segments'] as $segment ) {
			$originals[ Strings::normalize( $segment['text'] ) ] = $segment['text'];
		}
		foreach ( $to['segments'] as $i => $segment ) {
			if ( $aligned ) {
				$original = $from['segments'][ $i ]['text'];
			} else {
				$original = $originals[ Strings::normalize( $segment['text'] ) ] ?? '';
			}
			$texts[] = array( 'current' => $segment['text'], 'original' => $original, 'state' => $state( $segment['text'], $original ), 'where' => 'content', 'review' => isset( $filled[ sha1( Strings::normalize( $segment['text'] ) ) ] ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			$strings = Strings::query( $row['language'], array( 'limit' => 200 ) );
			foreach ( $strings['items'] as $string ) {
				if ( 'menu' === $string['context'] ) {
					continue;
				}
				$texts[] = array(
					'current'  => 'published' === $string['status'] ? $string['translation'] : $string['source'],
					'original' => $string['source'],
					'state'    => 'published' === $string['status'] ? 'done' : 'todo',
					'where'    => 'string',
				);
			}
		}
		// The memory's suggestion for what is still to translate.
		$todo = array_filter( $texts, static fn( $t ) => 'todo' === $t['state'] && '' !== $t['original'] );
		$known = Memory::lookup( array_column( $todo, 'original' ), $row['language'] );
		// C25: for the others, a sentence alike in the memory, with how alike: to review, never applied by itself.
		$alike = Memory::similar( array_values( array_filter( array_column( $todo, 'original' ), static fn( $t ) => ! isset( $known[ Strings::normalize( $t ) ] ) ) ), $row['language'] );
		foreach ( $texts as $i => $text ) {
			$key = Strings::normalize( $text['original'] );
			if ( 'todo' === $text['state'] && isset( $known[ $key ] ) ) {
				$texts[ $i ]['memory'] = $known[ $key ];
			} elseif ( 'todo' === $text['state'] && isset( $alike[ $key ] ) ) {
				$texts[ $i ]['similar'] = $alike[ $key ];
			}
		}
		return array_slice( $texts, 0, 2000 );
	}

	/** The glossary entries from the original language to this translation's. */
	private static function glossary( $row ) {
		$settings = Languages::settings();
		$out = array();
		foreach ( isset( $settings['translation_glossary'] ) && is_array( $settings['translation_glossary'] ) ? $settings['translation_glossary'] : array() as $entry ) {
			if ( is_array( $entry ) && $row && ( $entry['target_language'] ?? '' ) === $row['language'] && ( $entry['source_language'] ?? '' ) === Languages::default_code() ) {
				$out[] = array( 'term' => (string) $entry['source_term'], 'use' => (string) $entry['target_term'], 'note' => (string) ( $entry['note'] ?? '' ) );
			}
		}
		return $out;
	}

	/** Once, after creating: what the draft did not take with it. */
	private static function creation_notes( $id ) {
		$row = Storage::find( $id );
		$missing = array();
		foreach ( $row ? Fields::support_report( absint( $row['source_id'] ) ) : array() as $item ) {
			if ( 'missing' === ( $item['state'] ?? '' ) ) {
				$missing[] = (string) $item['label'];
			}
		}
		return array( 'missing' => $missing, 'memory' => absint( get_post_meta( $id, '_lingua_bridge_from_memory', true ) ) );
	}

	/** The checks every action of the panel shares: nonce of this translation, a translation, editable. */
	private static function translation_from_request() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::NONCE . '_' . $id, 'nonce' );
		$row = $id ? Storage::find( $id ) : null;
		if ( ! $row || Languages::default_code() === $row['language'] || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Non puoi modificare questa traduzione.', 'lingua-bridge' ) ), 403 );
		}
		return $id;
	}

	public static function apply_memory() {
		$id = self::translation_from_request();
		wp_send_json_success( array( 'count' => Memory::apply( $id ) ) );
	}

	public static function update() {
		$id = self::translation_from_request();
		$result = Updates::rebuild_draft( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}

	public static function mark_updated() {
		$id = self::translation_from_request();
		$result = Updates::mark_updated( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array() );
	}

	/** The page's sentences still in the original's words, by the service (drafts only). */
	public static function machine() {
		$id = self::translation_from_request();
		$result = MachineTranslation::translate_post( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'count' => $result ) );
	}

	/** One sentence, proposed by the service: it only fills the field, nothing is saved. */
	public static function suggest() {
		$id = self::translation_from_request();
		$text = isset( $_POST['text'] ) ? Strings::normalize( wp_unslash( $_POST['text'] ) ) : '';
		if ( '' === $text || mb_strlen( $text ) > 5000 ) {
			wp_send_json_error( array( 'message' => __( 'Testo non valido.', 'lingua-bridge' ) ), 400 );
		}
		$result = MachineTranslation::translate_texts( array( $text ), Languages::default_code(), Storage::find( $id )['language'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( array( 'text' => $result[0] ) );
	}

	/** Follows the original, or an independent page: switched from the panel. */
	public static function mode() {
		$id = self::translation_from_request();
		$independent = isset( $_POST['independent'] ) && '1' === $_POST['independent'];
		Translations::set_mode( $id, $independent );
		if ( ! $independent ) {
			// Following again from now: the original as it is, as the starting point.
			Translations::snapshot( $id );
		}
		wp_send_json_success( array( 'independent' => $independent ) );
	}

	/** «Lascia così»: a text that stays as the original on purpose counts as done. */
	public static function keep() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::NONCE . '_' . $id, 'nonce' );
		$row = $id ? Storage::find( $id ) : null;
		$text = isset( $_POST['text'] ) ? Strings::normalize( wp_unslash( $_POST['text'] ) ) : '';
		if ( ! $row || Languages::default_code() === $row['language'] || ! current_user_can( 'edit_post', $id ) || '' === $text ) {
			wp_send_json_error( array( 'message' => __( 'Non puoi modificare questa traduzione.', 'lingua-bridge' ) ), 403 );
		}
		$kept = array_values( array_unique( array_merge( (array) get_post_meta( $id, self::KEPT_META, true ), array( sha1( $text ) ) ) ) );
		update_post_meta( $id, self::KEPT_META, array_slice( $kept, -2000 ) );
		Memory::reviewed( $id, $text );
		wp_send_json_success( array( 'text' => $text ) );
	}

	/**
	 * «Crea e traduci» in one click (admin bar, block editor): the draft is made and opens on the site. If it
	 * cannot be made, the «Crea traduzione» screen says why.
	 */
	public static function quick_create() {
		$source = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
		$language = isset( $_GET['target_language'] ) ? sanitize_key( wp_unslash( $_GET['target_language'] ) ) : '';
		check_admin_referer( 'lingua_bridge_quick_create_' . $source . '_' . $language );
		$created = Translations::create_draft( $source, $language );
		if ( is_wp_error( $created ) ) {
			$existing = $created->get_error_data();
			if ( 'lingua_exists' === $created->get_error_code() && is_array( $existing ) && ! empty( $existing['post_id'] ) && current_user_can( 'edit_post', absint( $existing['post_id'] ) ) ) {
				wp_safe_redirect( add_query_arg( self::QUERY_VAR, absint( $existing['post_id'] ), home_url( '/' ) ) );
				exit;
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'lingua-bridge-create', 'source_id' => $source, 'target_language' => $language, 'lb_return' => 'frontend' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( self::QUERY_VAR => absint( $created ), 'lb_new' => 1 ), home_url( '/' ) ) );
		exit;
	}

	/** The one-click creation link (nonce for this original and language). */
	public static function quick_create_url( $source, $language ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'lingua_bridge_quick_create', 'source_id' => absint( $source ), 'target_language' => $language ), admin_url( 'admin-post.php' ) ), 'lingua_bridge_quick_create_' . absint( $source ) . '_' . $language );
	}

	/**
	 * Whether this user may publish the translation. A pattern (wp_block, an Etch component) maps «publish» to the
	 * capability publish_blocks, which no role has: whoever may edit it and publish posts may publish it, as
	 * WordPress itself lets them create one.
	 */
	public static function can_publish( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}
		return current_user_can( 'publish_post', $post->ID ) || ( 'wp_block' === $post->post_type && current_user_can( 'publish_posts' ) );
	}

	public static function publish() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( self::NONCE . '_' . $id, 'nonce' );
		$row = $id ? Storage::find( $id ) : null;
		if ( ! $row || Languages::default_code() === $row['language'] || ! self::can_publish( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Non puoi pubblicare questa traduzione.', 'lingua-bridge' ) ), 403 );
		}
		if ( ! in_array( get_post_status( $id ), array( 'draft', 'pending' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Si pubblica da qui solo una bozza o un contenuto in revisione.', 'lingua-bridge' ) ), 400 );
		}
		$published = wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $published ) ) {
			wp_send_json_error( array( 'message' => $published->get_error_message() ), 400 );
		}
		Storage::update_review_state( $id, 'reviewed' );
		wp_send_json_success( array( 'url' => (string) get_permalink( $id ) ) );
	}

	/**
	 * Replaces a whole text in stored content, wherever it is whole: a text node of the HTML, an attribute of the
	 * HTML, a string value of a block's JSON attributes. Returns the content and how many places changed.
	 *
	 * @return array{content: string, count: int}
	 */
	public static function replace( $content, $old, $new, $attribute = '' ) {
		$words = preg_split( '/\s+/u', trim( (string) $old ) );
		if ( empty( $words ) || '' === $words[0] ) {
			return array( 'content' => $content, 'count' => 0 );
		}
		$total = 0;
		$original = $content;
		$html_words = array_map( array( __CLASS__, 'html_pattern' ), $words );
		$json_words = array_map( array( __CLASS__, 'json_pattern' ), $words );
		// A word PCRE could not split (a limit reached): nothing changes, as for a failed match.
		if ( in_array( null, $html_words, true ) || in_array( null, $json_words, true ) ) {
			return array( 'content' => $content, 'count' => 0 );
		}
		if ( '' === $attribute ) {
			$pattern = '~(^|>)(\s*)' . implode( '\s+', $html_words ) . '(\s*)(?=<|$)~u';
			$result = preg_replace_callback( $pattern, static fn( $m ) => $m[1] . $m[2] . esc_html( $new ) . $m[3], $content, -1, $count );
		} else {
			$pattern = '~(\s' . preg_quote( $attribute, '~' ) . '=")\s*' . implode( '\s+', $html_words ) . '\s*(")~u';
			$result = preg_replace_callback( $pattern, static fn( $m ) => $m[1] . esc_attr( $new ) . $m[2], $content, -1, $count );
		}
		// A failed match (too long, too complex) never becomes an empty content: nothing changes.
		if ( null === $result ) {
			return array( 'content' => $original, 'count' => 0 );
		}
		$content = $result;
		$total += $count;
		// Block attributes, as WordPress (and Histeon, Etch) serialize them: a whole string value (an Etch element's
		// aria-label too).
		$pattern = '~("(?:' . Xliff::JSON_KEYS . '|' . Xliff::ETCH_ATTRIBUTE_KEYS . ')":\s*")(?:\s|\\\\[nt])*' . implode( '(?:\s|\\\\[nt])+', $json_words ) . '(?:\s|\\\\[nt])*(")~u';
		$result = preg_replace_callback( $pattern, static fn( $m ) => $m[1] . self::json_text( $new ) . $m[2], $content, -1, $count );
		if ( null === $result ) {
			return array( 'content' => $original, 'count' => 0 );
		}
		$content = $result;
		$total += $count;
		return array( 'content' => $content, 'count' => $total );
	}

	/** A word as stored HTML may hold it: as is, with entities, or before WordPress's typographic quotes. */
	private static function html_pattern( $word ) {
		$out = '';
		$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return null;
		}
		foreach ( $chars as $char ) {
			$alternatives = array( $char );
			if ( isset( self::TEXTURIZED[ $char ] ) ) {
				$alternatives = array_merge( $alternatives, self::TEXTURIZED[ $char ] );
			}
			if ( '<' === $char || '>' === $char ) {
				$alternatives[] = '<' === $char ? '&lt;' : '&gt;';
			}
			$out .= count( $alternatives ) > 1 ? '(?:' . implode( '|', array_map( static fn( $a ) => preg_quote( $a, '~' ), $alternatives ) ) . ')' : preg_quote( $char, '~' );
		}
		return $out;
	}

	/** A word as block JSON may hold it: WordPress's escapes (<, &, "…) or \uXXXX for any letter. */
	private static function json_pattern( $word ) {
		$out = '';
		$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return null;
		}
		foreach ( $chars as $char ) {
			$forms = array( self::json_text( $char ) );
			foreach ( isset( self::TEXTURIZED[ $char ] ) ? self::TEXTURIZED[ $char ] : array() as $plain ) {
				if ( false === strpos( $plain, '&' ) || '&' === $char ) {
					$forms[] = self::json_text( $plain );
				}
			}
			$code = mb_ord( $char, 'UTF-8' );
			if ( $code > 127 || in_array( $char, array( '<', '>', '&', "'", '"' ), true ) ) {
				$forms[] = sprintf( '\\u%04x', $code );
				$forms[] = sprintf( '\\u%04X', $code );
			}
			$forms = array_unique( $forms );
			$out .= count( $forms ) > 1 ? '(?:' . implode( '|', array_map( static fn( $a ) => preg_quote( $a, '~' ), $forms ) ) . ')' : preg_quote( $forms[0], '~' );
		}
		return $out;
	}

	/** Text inside a JSON string of block attributes, escaped as serialize_block_attributes() does. */
	private static function json_text( $text ) {
		$json = (string) wp_json_encode( (string) $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json = substr( $json, 1, -1 );
		return str_replace( array( '--', '<', '>', '&', '\\"' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ), $json );
	}
}
