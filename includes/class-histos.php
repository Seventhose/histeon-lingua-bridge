<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lingua Bridge as the multilingual engine of Histeon (docs/HISTOS.md). Histeon stays unaware of any language
 * plugin and offers neutral hooks; this class answers them. Nothing here runs without Histeon: its hooks never fire.
 *
 * - xb_component_post: a component (wp_block) is drawn in the language of the page, if translated and published;
 * - xb_render_document(_done): the builder canvas draws lists and components in its document's language;
 * - xb_data_providers: `data.lingua` for expressions — the language of the page and its published translations;
 * - xb_builder_languages: the builder's language bar — translations to open, languages to create.
 */
final class Histos {
	/** The language of the document the Histeon canvas draws now, '' outside a canvas render. */
	private static $canvas_language = '';
	private static $canvas_post = 0;

	public static function init() {
		add_filter( 'xb_component_post', array( __CLASS__, 'component_post' ), 10, 2 );
		add_action( 'xb_render_document', array( __CLASS__, 'render_start' ) );
		add_action( 'xb_render_document_done', array( __CLASS__, 'render_end' ) );
		add_filter( 'xb_data_providers', array( __CLASS__, 'data_providers' ) );
		add_filter( 'xb_builder_languages', array( __CLASS__, 'builder_languages' ), 10, 2 );
		add_filter( 'xb_site_status', array( __CLASS__, 'site_status' ) );
		add_action( 'xb_remote_language_link', array( __CLASS__, 'remote_link' ), 10, 4 );
	}

	/**
	 * Histeon → Panoramica, «Stato del sito»: the translations still to do, for the contents this user can edit
	 * (Connector::work_summary, cached a few minutes). Histeon keeps only plain text and a link into this admin.
	 */
	public static function site_status( $rows ) {
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! current_user_can( 'edit_posts' ) || count( Languages::all() ) < 2 ) {
			return $rows;
		}
		$summary = Connector::work_summary();
		$count = array( 'missing' => 0, 'stale' => 0, 'draft' => 0 );
		foreach ( $summary['totals'] as $states ) {
			foreach ( array_keys( $count ) as $state ) {
				$count[ $state ] += (int) ( $states[ $state ] ?? 0 );
			}
		}
		/* translators: 1: to translate, 2: to update, 3: drafts */
		$value = sprintf( __( '%1$d da tradurre · %2$d da aggiornare · %3$d in bozza', 'lingua-bridge' ), $count['missing'], $count['stale'], $count['draft'] );
		if ( empty( $summary['complete'] ) ) {
			$value .= ' ' . __( '(e altre)', 'lingua-bridge' );
		}
		$rows[] = array(
			'label' => __( 'Traduzioni', 'lingua-bridge' ),
			'value' => $value,
			'state' => $count['missing'] + $count['stale'] > 0 ? 'warn' : 'ok',
			'url'   => admin_url( 'admin.php?page=lingua-bridge' ),
		);
		return $rows;
	}

	private static function language() {
		return '' !== self::$canvas_language ? self::$canvas_language : Frontend::current_language();
	}

	/** The translation of a component in the language of the page, when there is a published one. */
	public static function component_post( $post_id, $ref ) {
		$language = self::language();
		$row = is_int( $post_id ) ? Storage::find( $post_id ) : null;
		if ( ! $row || $language === $row['language'] ) {
			return $post_id;
		}
		$variant = Storage::find_group_language( $row['group_uuid'], $language );
		$post = $variant ? get_post( absint( $variant['object_id'] ) ) : null;
		return $post && 'wp_block' === $post->post_type && 'publish' === $post->post_status ? absint( $post->ID ) : $post_id;
	}

	/**
	 * A content sent by Histeon Publish is the version of another one (docs/spec/multilingual.md in Histeon). The link is
	 * made once and only between languages of this site; the status of the two posts stays as it is: drafts stay drafts.
	 */
	public static function remote_link( $translation_id, $source_id, $language, $mode ) {
		$code = strtolower( (string) $language );
		$languages = Languages::all();
		if ( ! isset( $languages[ $code ] ) ) {
			$code = strtok( $code, '-' );
		}
		if ( ! isset( $languages[ $code ] ) || $code === Languages::default_code() || ! get_post( $source_id ) || ! get_post( $translation_id ) ) {
			return;
		}
		// Whoever sends the link must be able to edit both contents: no operation trusts a check made elsewhere.
		if ( ! current_user_can( 'edit_post', absint( $source_id ) ) || ! current_user_can( 'edit_post', absint( $translation_id ) ) ) {
			return;
		}
		$hash = Translations::core_hash( $source_id );
		$source = Storage::ensure_source( $source_id, Languages::default_code(), $hash );
		if ( ! $source || absint( $source['source_id'] ) !== absint( $source_id ) ) {
			return;
		}
		$existing = Storage::find( $translation_id );
		if ( ! $existing ) {
			if ( Storage::find_group_language( $source['group_uuid'], $code ) ) {
				return;
			}
			Storage::insert(
				array(
					'object_id'    => $translation_id,
					'group_uuid'   => $source['group_uuid'],
					'language'     => $code,
					'source_id'    => $source_id,
					'source_hash'  => $hash,
					'review_state' => 'needs_review',
				)
			);
		} elseif ( $existing['group_uuid'] !== $source['group_uuid'] ) {
			return;
		}
		update_post_meta( $translation_id, Translations::MODE_META, 'independent' === $mode ? 'independent' : 'follow' );
		// Linked after it was published: it takes the protection of its original now.
		$translation = get_post( $translation_id );
		if ( $translation && in_array( $translation->post_status, array( 'publish', 'future' ), true ) ) {
			Translations::enforce_protection( $translation, true );
		}
	}

	public static function render_start( $post ) {
		$row = $post instanceof \WP_Post ? Storage::find( $post->ID ) : null;
		self::$canvas_language = $row ? $row['language'] : Languages::default_code();
		self::$canvas_post = $post instanceof \WP_Post ? absint( $post->ID ) : 0;
		Routing::force_language( self::$canvas_language );
	}

	public static function render_end() {
		self::$canvas_language = '';
		self::$canvas_post = 0;
		Routing::force_language( '' );
	}

	public static function data_providers( $providers ) {
		$providers = is_array( $providers ) ? $providers : array();
		$providers['lingua'] = array(
			'callback'    => array( __CLASS__, 'lingua' ),
			'description' => __( 'Lingua della pagina e sue traduzioni pubblicate (Lingua Bridge): code, name, alternates[] con code, name, url, current.', 'lingua-bridge' ),
		);
		return $providers;
	}

	/** `data.lingua`: plain data for a language switcher or a lang-aware layout. */
	public static function lingua() {
		$post_id = self::$canvas_post ? self::$canvas_post : Frontend::queried_post_id();
		$language = self::language();
		$alternates = array();
		$row = $post_id ? Storage::find( $post_id ) : null;
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$post = get_post( absint( $member['object_id'] ) );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$alternates[] = array(
				'code'    => (string) $member['language'],
				'name'    => Languages::label( $member['language'] ),
				'url'     => (string) get_permalink( $post ),
				'current' => absint( $post->ID ) === $post_id,
			);
		}
		// A layout that reads the versions draws its own switcher, most likely: no floating one on that page (C1).
		if ( count( $alternates ) > 1 && ! self::$canvas_post ) {
			Switcher::drawn( 'histeon' );
		}
		return array(
			'code'       => $language,
			'name'       => Languages::label( $language ),
			'default'    => Languages::default_code(),
			'alternates' => $alternates,
		);
	}

	/** The builder's language bar: this document, its translations (open them in Histeon) and what is missing. */
	public static function builder_languages( $value, $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post || ! Translations::supports_post( $post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $value;
		}
		$row = Storage::find( $post->ID );
		$current = $row ? $row['language'] : Languages::default_code();
		$members = array();
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$members[ $member['language'] ] = absint( $member['object_id'] );
		}
		$source = $row ? absint( $row['source_id'] ) : $post->ID;
		$items = array();
		foreach ( Languages::all() as $code => $label ) {
			$id = isset( $members[ $code ] ) ? $members[ $code ] : ( $code === $current ? $post->ID : 0 );
			if ( $id === $post->ID ) {
				$items[] = array( 'code' => $code, 'label' => $label, 'state' => 'current', 'url' => '' );
				continue;
			}
			if ( $id ) {
				$items[] = array( 'code' => $code, 'label' => $label, 'state' => 'publish' === get_post_status( $id ) ? 'publish' : 'draft', 'url' => current_user_can( 'edit_post', $id ) ? admin_url( 'admin.php?action=xb_builder&post=' . $id ) : '' );
				continue;
			}
			$items[] = array(
				'code'  => $code,
				'label' => $label,
				'state' => 'missing',
				'url'   => add_query_arg( array( 'page' => 'lingua-bridge-create', 'source_id' => $source, 'target_language' => $code, 'lb_return' => 'histos' ), admin_url( 'admin.php' ) ),
			);
		}
		return array( 'current' => $current, 'items' => $items, 'source' => $row && $source !== $post->ID && current_user_can( 'edit_post', $source ) ? self::texts( $source ) : array() );
	}

	/**
	 * The visible texts of the original, so the builder can point out texts left untranslated in a translation.
	 *
	 * @return list<string>
	 */
	private static function texts( $source_id ) {
		$out = array();
		$walk = static function ( $blocks ) use ( &$walk, &$out ) {
			foreach ( $blocks as $block ) {
				if ( count( $out ) >= 300 ) {
					return;
				}
				$text = isset( $block['attrs']['content'] ) && is_string( $block['attrs']['content'] ) ? $block['attrs']['content'] : ( isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '' );
				$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
				if ( strlen( $text ) >= 12 && false === strpos( $text, '{' ) ) {
					$out[] = mb_substr( $text, 0, 300 );
				}
				// What a component shows is in its props.
				foreach ( isset( $block['attrs']['props'] ) && is_array( $block['attrs']['props'] ) ? $block['attrs']['props'] : array() as $value ) {
					$text = is_string( $value ) ? trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) ) : '';
					if ( strlen( $text ) >= 12 && false === strpos( $text, '{' ) && 0 === preg_match( '~^(?:https?:|/|#|mailto:)~', $text ) ) {
						$out[] = mb_substr( $text, 0, 300 );
					}
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( (string) get_post_field( 'post_content', $source_id, 'raw' ) ) );
		return array_values( array_unique( $out ) );
	}
}
