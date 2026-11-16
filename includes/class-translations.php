<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Translations {
	/** A translation is either a copy that follows its original, or an independent page (its own content). */
	const MODE_META = '_lingua_bridge_mode';
	/** The original as it was when the translation was last brought up to date (title and segments). */
	const SNAPSHOT_META = '_lingua_bridge_source_snapshot';

	public static function is_independent( $post_id ) {
		return 'independent' === get_post_meta( absint( $post_id ), self::MODE_META, true );
	}

	public static function set_mode( $post_id, $independent ) {
		if ( $independent ) {
			update_post_meta( absint( $post_id ), self::MODE_META, 'independent' );
		} else {
			delete_post_meta( absint( $post_id ), self::MODE_META );
		}
		Storage::content_changed();
	}

	/** Whether a translation lags behind its original: only for those that follow it. */
	public static function is_stale( $row ) {
		if ( ! is_array( $row ) || empty( $row['source_hash'] ) || absint( $row['object_id'] ) === absint( $row['source_id'] ) || self::is_independent( $row['object_id'] ) ) {
			return false;
		}
		return $row['source_hash'] !== self::core_hash( absint( $row['source_id'] ) );
	}

	/** The translation follows the current original now: its version and a picture of its texts. */
	public static function snapshot( $translation_id ) {
		$row = Storage::find( $translation_id );
		$source = $row ? get_post( absint( $row['source_id'] ) ) : null;
		if ( ! $row || ! $source || absint( $row['source_id'] ) === absint( $translation_id ) ) {
			return;
		}
		$parts = Xliff::segment( $source->post_content );
		update_post_meta(
			absint( $translation_id ),
			self::SNAPSHOT_META,
			wp_slash(
				wp_json_encode(
					array(
						'title'    => $source->post_title,
						'segments' => $parts['ok'] ? $parts['segments'] : array(),
					)
				)
			)
		);
		Storage::update_source_hash( $translation_id, self::core_hash( $source->ID ) );
	}

	/**
	 * How much of a translation that follows its original is translated (percent), or null when it cannot be
	 * told (independent page, structure different from the original's).
	 */
	public static function progress( $translation_id ) {
		$row = Storage::find( $translation_id );
		$post = get_post( $translation_id );
		$source = $row ? get_post( absint( $row['source_id'] ) ) : null;
		if ( ! $row || ! $post || ! $source || $source->ID === $post->ID || self::is_independent( $post->ID ) ) {
			return null;
		}
		$from = Xliff::segment( $source->post_content );
		$to = Xliff::segment( $post->post_content );
		if ( ! $from['ok'] || ! $to['ok'] || array_column( $from['segments'], 'kind' ) !== array_column( $to['segments'], 'kind' ) ) {
			return null;
		}
		$kept = array_flip( (array) get_post_meta( $post->ID, Editor::KEPT_META, true ) );
		$pairs = array_merge( array( array( $source->post_title, $post->post_title ) ), array_map( null, array_column( $from['segments'], 'text' ), array_column( $to['segments'], 'text' ) ) );
		$done = 0;
		foreach ( $pairs as $pair ) {
			$same = Strings::normalize( $pair[0] ) === Strings::normalize( (string) $pair[1] );
			if ( ! $same || isset( $kept[ sha1( Strings::normalize( (string) $pair[1] ) ) ] ) || ! preg_match( '/\p{L}/u', (string) $pair[1] ) ) {
				++$done;
			}
		}
		return (int) floor( 100 * $done / max( 1, count( $pairs ) ) );
	}

	/** The picture of the original taken at the last update, or null (translations made before 1.4). */
	public static function snapshot_of( $translation_id ) {
		$data = json_decode( (string) get_post_meta( absint( $translation_id ), self::SNAPSHOT_META, true ), true );
		return is_array( $data ) && isset( $data['segments'] ) && is_array( $data['segments'] ) ? $data : null;
	}

	/**
	 * An existing content becomes the translation of another, as an independent page (Polylang/WPML style): same
	 * type, both editable, not linked yet, the language free in the group.
	 */
	public static function link_existing( $source_id, $target_id, $language ) {
		$source_id = absint( $source_id );
		$target_id = absint( $target_id );
		$language = sanitize_text_field( $language );
		$source = get_post( $source_id );
		$target = get_post( $target_id );
		if ( ! $source || ! $target || $source_id === $target_id || $source->post_type !== $target->post_type || ! self::supports_post( $source ) || ! current_user_can( 'edit_post', $source_id ) || ! current_user_can( 'edit_post', $target_id ) ) {
			return new \WP_Error( 'lingua_link_forbidden', __( 'Scegli un contenuto dello stesso tipo, che puoi modificare.', 'lingua-bridge' ) );
		}
		if ( ! isset( Languages::all()[ $language ] ) ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}
		if ( Storage::find( $target_id ) ) {
			return new \WP_Error( 'lingua_link_taken', __( 'Quel contenuto è già collegato a un’altra lingua.', 'lingua-bridge' ) );
		}
		$source_map = Storage::find( $source_id );
		if ( ! $source_map ) {
			$source_map = Storage::ensure_source( $source_id, Languages::default_code(), self::core_hash( $source_id ) );
		}
		if ( ! $source_map || $language === $source_map['language'] || Storage::find_group_language( $source_map['group_uuid'], $language ) ) {
			return new \WP_Error( 'lingua_exists', __( 'Esiste già una versione in questa lingua.', 'lingua-bridge' ) );
		}
		$inserted = Storage::insert( array( 'object_id' => $target_id, 'group_uuid' => $source_map['group_uuid'], 'language' => $language, 'source_id' => $source_id, 'source_hash' => self::core_hash( $source_id ), 'review_state' => 'reviewed' ) );
		if ( ! $inserted ) {
			return new \WP_Error( 'lingua_relation_error', __( 'Il collegamento non è stato salvato.', 'lingua-bridge' ) );
		}
		update_post_meta( $target_id, '_lingua_bridge_source_id', $source_id );
		self::set_mode( $target_id, true );
		return $target_id;
	}

	/**
	 * A translation keeps the protection of its original: private stays private, a password stays. Whatever way it
	 * is published (editor, block editor, REST, wp_publish_post), a public translation of a protected page would
	 * show what the original hides.
	 */
	public static function init_protection() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'protect_data' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'protect_transition' ), 5, 3 );
		add_action( 'post_updated', array( __CLASS__, 'protect_followers' ), 10, 3 );
	}

	/**
	 * The original's protection for a translation: [status if published, password], or null. A correction copy has no
	 * relation of its own: it takes the protection of the page it corrects and of that page's original.
	 */
	public static function protection( $translation_id ) {
		$translation_id = absint( $translation_id );
		$row = $translation_id ? Storage::find( $translation_id ) : null;
		if ( ! $row && $translation_id ) {
			$target_id = absint( get_post_meta( $translation_id, '_lingua_bridge_correction_target_id', true ) );
			$target = $target_id && $target_id !== $translation_id ? get_post( $target_id ) : null;
			if ( ! $target ) {
				return null;
			}
			$inherited = Storage::find( $target_id ) ? self::protection( $target_id ) : null;
			$private = 'private' === $target->post_status || ( $inherited && 'private' === $inherited[0] );
			$password = '' !== (string) $target->post_password ? (string) $target->post_password : ( $inherited ? $inherited[1] : '' );
			return $private || '' !== $password ? array( $private ? 'private' : 'publish', $password ) : null;
		}
		$source = $row && absint( $row['source_id'] ) !== $translation_id ? get_post( absint( $row['source_id'] ) ) : null;
		if ( ! $source || ( 'private' !== $source->post_status && '' === (string) $source->post_password ) ) {
			return null;
		}
		return array( 'private' === $source->post_status ? 'private' : 'publish', (string) $source->post_password );
	}

	public static function protect_data( $data, $postarr ) {
		$id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		$protection = in_array( $data['post_status'] ?? '', array( 'publish', 'future' ), true ) ? self::protection( $id ) : null;
		if ( $protection ) {
			if ( 'private' === $protection[0] ) {
				$data['post_status'] = 'private';
			}
			if ( '' !== $protection[1] && '' === (string) ( $data['post_password'] ?? '' ) ) {
				$data['post_password'] = $protection[1];
			}
		}
		return $data;
	}

	public static function protect_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post ) {
			return;
		}
		self::enforce_protection( $post );
	}

	/**
	 * An original made private or given a password after its translations were published: the published translations
	 * take the same protection at once, or they would stay online (and in the sitemap) with what the original now hides.
	 */
	public static function protect_followers( $post_id, $post_after, $post_before ) {
		if ( ! $post_after instanceof \WP_Post || ( 'private' !== $post_after->post_status && '' === (string) $post_after->post_password ) ) {
			return;
		}
		$row = Storage::find( absint( $post_id ) );
		if ( ! $row || absint( $row['source_id'] ) !== absint( $post_id ) ) {
			return;
		}
		foreach ( Storage::group_rows( $row['group_uuid'] ) as $member ) {
			$member_post = absint( $member['object_id'] ) !== absint( $post_id ) ? get_post( absint( $member['object_id'] ) ) : null;
			if ( $member_post && in_array( $member_post->post_status, array( 'publish', 'future' ), true ) ) {
				self::enforce_protection( $member_post, true );
			}
		}
	}

	/**
	 * Gives a published translation the protection of its original, without the save hooks (no loop, no kses).
	 * $announce: the status changed outside a save, so the transition is announced.
	 */
	public static function enforce_protection( $post, $announce = false ) {
		$protection = $post instanceof \WP_Post ? self::protection( $post->ID ) : null;
		if ( ! $protection ) {
			return;
		}
		global $wpdb;
		$changes = array();
		if ( 'private' === $protection[0] && 'private' !== $post->post_status ) {
			$changes['post_status'] = 'private';
		}
		if ( '' !== $protection[1] && '' === (string) $post->post_password ) {
			$changes['post_password'] = $protection[1];
		}
		if ( $changes ) {
			$wpdb->update( $wpdb->posts, $changes, array( 'ID' => $post->ID ) );
			clean_post_cache( $post->ID );
			Storage::content_changed();
			if ( $announce && isset( $changes['post_status'] ) ) {
				// As wp_publish_post() does: caches, sitemaps and Static Bridge hear the new status.
				wp_transition_post_status( 'private', $post->post_status, get_post( $post->ID ) );
			}
		}
	}

	/**
	 * The types that can be translated: editable, with an editor, and seen by visitors. A type that is not public
	 * (form submissions, orders, logs) holds private data, never offered for translation, XLIFF, an agent or a
	 * translation service. Patterns and navigation menus are not public types but are shown inside pages. The
	 * filter lingua_bridge_post_types can add a type on purpose.
	 *
	 * @return list<string>
	 */
	public static function translatable_types() {
		$editable = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $type ) {
			if ( in_array( $name, array( 'attachment', 'revision', 'nav_menu_item' ), true ) || ! post_type_supports( $name, 'editor' ) ) {
				continue;
			}
			if ( empty( $type->public ) && empty( $type->publicly_queryable ) && ! in_array( $name, array( 'wp_block', 'wp_navigation' ), true ) ) {
				continue;
			}
			$editable[] = $name;
		}
		return apply_filters( 'lingua_bridge_post_types', $editable );
	}

	public static function supports_post( $post ) {
		$post = get_post( $post );
		// One list for every way in (dashboard, editor, XLIFF, connector, translation service): private types such
		// as form submissions are never translatable, even by id.
		return $post && 'trash' !== $post->post_status && in_array( $post->post_type, self::translatable_types(), true );
	}

	/** @var array<int, string> The hash of each original, computed once per request (cleared when a post is saved). */
	private static $hashes = array();

	public static function forget_hash( $post_id ) {
		unset( self::$hashes[ absint( $post_id ) ] );
	}

	public static function core_hash( $post_id ) {
		$post_id = absint( $post_id );
		if ( isset( self::$hashes[ $post_id ] ) ) {
			return self::$hashes[ $post_id ];
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$payload = array(
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'fields'       => Fields::translatable_values( $post_id ),
		);
		self::$hashes[ $post_id ] = hash( 'sha256', wp_json_encode( $payload ) );
		return self::$hashes[ $post_id ];
	}

	/**
	 * A new translation as a draft: 'copy' (the original's content, to translate; it follows the original) or
	 * 'blank' (an independent page with an empty content, to build as you like).
	 */
	public static function create_draft( $source_id, $language, $mode = 'copy' ) {
		$source_id = absint( $source_id );
		$source = get_post( $source_id );
		$language = sanitize_text_field( $language );
		if ( ! $source || ! self::supports_post( $source ) ) {
			return new \WP_Error( 'lingua_invalid_source', __( 'Il contenuto di partenza non è supportato.', 'lingua-bridge' ) );
		}
		if ( ! isset( Languages::all()[ $language ] ) ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}
		// Whoever can edit the original, or a translator of this language (N7).
		if ( ! Translators::may_translate( $source_id, $language ) ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi per tradurre questo contenuto.', 'lingua-bridge' ) );
		}
		// A translation is a new item of the same type: whoever cannot create one cannot make one this way either.
		$type_object = get_post_type_object( $source->post_type );
		if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi per creare contenuti di questo tipo.', 'lingua-bridge' ) );
		}

		$source_map = Storage::find( $source_id );
		if ( ! $source_map ) {
			$source_map = Storage::ensure_source( $source_id, Languages::default_code(), self::core_hash( $source_id ) );
		}
		if ( ! $source_map ) {
			return new \WP_Error( 'lingua_storage_error', __( 'Non è stato possibile collegare il contenuto alla lingua.', 'lingua-bridge' ) );
		}
		if ( $language === $source_map['language'] ) {
			return new \WP_Error( 'lingua_same_language', __( 'Scegli una lingua diversa da quella del contenuto selezionato.', 'lingua-bridge' ) );
		}
		$existing = Storage::find_group_language( $source_map['group_uuid'], $language );
		if ( $existing ) {
			return new \WP_Error( 'lingua_exists', __( 'Esiste già una versione in questa lingua.', 'lingua-bridge' ), array( 'post_id' => absint( $existing['object_id'] ) ) );
		}

		$target_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $source->post_type,
					'post_status'    => 'draft',
					'post_title'     => $source->post_title,
					'post_content'   => 'blank' === $mode ? '' : $source->post_content,
					'post_excerpt'   => 'blank' === $mode ? '' : $source->post_excerpt,
					'post_author'    => get_current_user_id(),
					'post_parent'    => self::translated_parent_id( absint( $source->post_parent ), $language, $source->post_type ),
					'menu_order'     => absint( $source->menu_order ),
					'comment_status' => $source->comment_status,
					'ping_status'    => $source->ping_status,
					'post_password'  => $source->post_password,
				)
			),
			true
		);
		if ( is_wp_error( $target_id ) ) {
			return $target_id;
		}

		$thumbnail_id = get_post_thumbnail_id( $source_id );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $target_id, $thumbnail_id );
		}
		self::copy_terms( $source_id, $target_id, $source->post_type );
		Fields::copy( $source_id, $target_id );

		$inserted = Storage::insert(
			array(
				'object_id'    => $target_id,
				'group_uuid'   => $source_map['group_uuid'],
				'language'     => $language,
				'source_id'    => $source_id,
				'source_hash'  => self::core_hash( $source_id ),
				'review_state' => 'needs_review',
			)
		);
		if ( ! $inserted ) {
			wp_delete_post( $target_id, true );
			return new \WP_Error( 'lingua_relation_error', __( 'La bozza è stata annullata perché la relazione linguistica non è stata salvata.', 'lingua-bridge' ) );
		}
		update_post_meta( $target_id, '_lingua_bridge_source_id', $source_id );
		if ( 'blank' === $mode ) {
			self::set_mode( $target_id, true );
		} else {
			self::snapshot( $target_id );
			// The sentences already translated elsewhere come in at once (translation memory).
			$known = Memory::apply( $target_id );
			if ( $known ) {
				update_post_meta( $target_id, '_lingua_bridge_from_memory', $known );
			}
		}
		do_action( 'lingua_bridge_translation_created', $target_id, $source_id, $language );
		return $target_id;
	}

	private static function translated_parent_id( $parent_id, $language, $post_type ) {
		if ( ! $parent_id ) {
			return 0;
		}
		$parent_map = Storage::find( $parent_id );
		if ( ! $parent_map ) {
			return $parent_id;
		}
		$translated_parent = Storage::find_group_language( $parent_map['group_uuid'], $language );
		if ( ! $translated_parent ) {
			return $parent_id;
		}
		$parent_post = get_post( absint( $translated_parent['object_id'] ) );
		return $parent_post && $post_type === $parent_post->post_type ? absint( $parent_post->ID ) : $parent_id;
	}

	/** Markup that runs code or loads other pages: a translation never has more of it than its original. */
	/** Active markup, matched as whole fragments (the tag, the handler with its value, the URL scheme with its code). */
	const ACTIVE_MARKUP = array(
		'script'     => '/<script\b[^>]*>.*?(?:<\/script\s*>|$)/is',
		'element'    => '/<(?:iframe|object|embed|applet|form|meta|base|link|svg|math|style)\b[^>]*>/i',
		'handler'    => '/[\s"\'\/]on[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i',
		'json'       => '/"on[a-z]+"\s*:\s*"(?:[^"\\\\]|\\\\.)*"/i',
		'javascript' => '/(?:java|vb)script\s*:[^"\'\s<>]*|data\s*:\s*text\/html[^"\'\s<>]*/i',
		'srcdoc'     => '/\bsrcdoc\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i',
		'formaction' => '/\bformaction["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i',
		// Etch and Histeon expressions ({this.title}, {props.name}): a path of names in braces, never translated.
		'expression' => '/\{\s*[a-z_][a-z0-9_]*(?:\.[a-z0-9_]+(?:\([^{}]*\))?)+\s*\}/i',
	);

	/**
	 * The text as a browser or a block would read it: JSON escapes and HTML entities decoded (numeric references also
	 * without their closing semicolon, as browsers read them), case folded. Spaces and control characters become one
	 * space; for a URL scheme ($scheme) control characters are dropped instead, because browsers drop tabs and new
	 * lines anywhere in a URL ("jav\tascript:").
	 */
	private static function active_view( $text, $scheme = false ) {
		$text = (string) preg_replace_callback( '/\\\\u([0-9a-fA-F]{4})/', static fn( $m ) => mb_chr( hexdec( $m[1] ), 'UTF-8' ), (string) $text );
		$text = strtr( $text, array( '\\t' => "\t", '\\n' => "\n", '\\r' => "\r", '\\/' => '/' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$decoded = (string) preg_replace_callback(
				'/&#(?:x([0-9a-f]+)|([0-9]+));?/i',
				static function ( $m ) {
					$code = '' !== $m[1] ? hexdec( $m[1] ) : (int) $m[2];
					$char = $code > 0 && $code <= 0x10FFFF ? mb_chr( (int) $code, 'UTF-8' ) : false;
					return false === $char ? "\u{FFFD}" : $char;
				},
				$text
			);
			$decoded = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $text ) {
				break;
			}
			$text = $decoded;
		}
		if ( $scheme ) {
			return strtolower( (string) preg_replace( '/[\x00-\x1f]+/', '', $text ) );
		}
		return strtolower( (string) preg_replace( '/[\x00-\x20]+/', ' ', $text ) );
	}

	/** Elements that run code or load other pages when Histeon stores them as blocks (xb/element, its tag in attrs). */
	const ACTIVE_TAGS = array( 'script', 'style', 'iframe', 'object', 'embed', 'applet', 'form', 'meta', 'base', 'link', 'svg', 'math', 'frame', 'frameset', 'template' );

	/** Active elements whose content is code (a script, a style) or a URL in any attribute: fingerprinted whole. */
	const WHOLE_TAGS = array( 'script', 'style', 'math', 'template', 'meta', 'base', 'link' );

	/** Attributes that are text for people, not behaviour: translating them changes no active element. */
	const TEXT_ATTRIBUTES = array( 'aria-label', 'aria-description', 'aria-placeholder', 'aria-roledescription', 'alt', 'title', 'placeholder', 'value', 'label' );

	/** Readable names of the fragments last seen (key => "form «Ricerca»"), for the error message. */
	private static $fragment_labels = array();

	/**
	 * Active elements written as blocks, not as HTML: Histeon keeps a <script> as an xb/element block with
	 * "tag":"script" and its code as a verbatim text child, and Etch keeps an element's script base64-encoded in the
	 * block attributes. Neither shows up as "<script" in the text: each such element is one fragment. A script, a
	 * style (WHOLE_TAGS) counts whole; a form, an iframe, an SVG only by what acts (tag, attributes that are not text
	 * for people, script), and their children are walked like any other block: translating the label or the
	 * placeholder of a search form changes no fragment, changing its action does. Editor labels (metadata) never count.
	 *
	 * @return list<string>
	 */
	private static function block_fragments( $text ) {
		if ( false === strpos( (string) $text, '<!-- wp:' ) || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		$out  = array();
		$walk = static function ( $blocks ) use ( &$walk, &$out ) {
			foreach ( $blocks as $block ) {
				$name  = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$tag   = is_string( $attrs['tag'] ?? null ) ? strtolower( trim( $attrs['tag'] ) ) : '';
				if ( ( 'xb/element' === $name || 0 === strpos( $name, 'etch/' ) ) && in_array( $tag, self::ACTIVE_TAGS, true ) ) {
					$layer = is_string( $attrs['metadata']['name'] ?? null ) ? trim( $attrs['metadata']['name'] ) : '';
					$label = '' !== $layer && strtolower( $layer ) !== $tag ? $tag . ' «' . $layer . '»' : $tag;
					unset( $attrs['metadata'] );
					if ( in_array( $tag, self::WHOLE_TAGS, true ) ) {
						$block['attrs'] = $attrs;
						$key = 'block:' . md5( (string) wp_json_encode( self::without_metadata( $block ) ) );
						self::$fragment_labels[ $key ] = $label;
						$out[] = $key;
						continue;
					}
					if ( is_array( $attrs['attributes'] ?? null ) ) {
						$attrs['attributes'] = array_diff_key( $attrs['attributes'], array_flip( self::TEXT_ATTRIBUTES ) );
					}
					$key = 'block:' . md5( (string) wp_json_encode( array( $name, $attrs ) ) );
					self::$fragment_labels[ $key ] = $label;
					$out[] = $key;
				} elseif ( 0 === strpos( $name, 'etch/' ) && isset( $attrs['script'] ) ) {
					$key = 'etch-script:' . md5( (string) wp_json_encode( $attrs['script'] ) );
					$layer = is_string( $attrs['metadata']['name'] ?? null ) ? trim( $attrs['metadata']['name'] ) : '';
					self::$fragment_labels[ $key ] = 'script' . ( '' !== $layer ? ' «' . $layer . '»' : '' );
					$out[] = $key;
				}
				if ( is_array( $block['innerBlocks'] ?? null ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( (string) $text ) );
		return $out;
	}

	/** A parsed block without the editor's labels (metadata), at every level. */
	private static function without_metadata( $block ) {
		if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			unset( $block['attrs']['metadata'] );
		}
		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = array_map( array( __CLASS__, 'without_metadata' ), $block['innerBlocks'] );
		}
		return $block;
	}

	/** @return array<string, int> Every active fragment of a text, with how many times it occurs. */
	private static function active_fragments( $text ) {
		$found = array();
		foreach ( self::block_fragments( $text ) as $key ) {
			$found[ $key ] = ( $found[ $key ] ?? 0 ) + 1;
		}
		$view = self::active_view( $text );
		$view_tight = (string) preg_replace( '/(java|vb)\s*script\s*:/', '$1script:', $view );
		$scheme_view = (string) preg_replace( '/(java|vb)\s*script\s*:/', '$1script:', self::active_view( $text, true ) );
		foreach ( self::ACTIVE_MARKUP as $name => $pattern ) {
			if ( preg_match_all( $pattern, 'javascript' === $name ? $scheme_view : $view_tight, $matches ) ) {
				foreach ( $matches[0] as $fragment ) {
					$key = trim( $fragment );
					$found[ $key ] = ( $found[ $key ] ?? 0 ) + 1;
				}
			}
		}
		// The shortcodes the site runs, by name: their attributes may hold texts to translate, a new one runs code.
		$tags = isset( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] ) ? array_keys( $GLOBALS['shortcode_tags'] ) : array();
		if ( $tags && preg_match_all( '/\[(' . implode( '|', array_map( static fn( $tag ) => preg_quote( strtolower( (string) $tag ), '/' ), $tags ) ) . ')(?=[\s\]\/])/', $view, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$found[ '[' . $tag . ']' ] = ( $found[ '[' . $tag . ']' ] ?? 0 ) + 1;
			}
		}
		return $found;
	}

	/**
	 * Text written by an agent (connector, Abilities) went through untrusted input: the site's own content.
	 * Administrators save unfiltered HTML, so kses would not catch an injected script. A translation keeps the
	 * active markup of its original, field by field and fragment by fragment, after decoding entities and JSON
	 * escapes: an embed already in the original stays, nothing new or different is accepted.
	 *
	 * @param string|array<string, string> $original The original's title, content and excerpt (or one text for all).
	 */
	public static function check_active_markup( $original, $values ) {
		foreach ( array( 'title', 'content', 'excerpt' ) as $key ) {
			if ( ! isset( $values[ $key ] ) || ! is_string( $values[ $key ] ) ) {
				continue;
			}
			$allowed = self::active_fragments( is_array( $original ) ? (string) ( $original[ $key ] ?? '' ) : (string) $original );
			foreach ( self::active_fragments( $values[ $key ] ) as $fragment => $count ) {
				if ( $count > ( $allowed[ $fragment ] ?? 0 ) ) {
					$what = self::$fragment_labels[ $fragment ] ?? ( strlen( $fragment ) > 60 ? substr( $fragment, 0, 57 ) . '…' : $fragment );
					return new \WP_Error(
						'lingua_active_markup',
						sprintf(
							/* translators: 1: the active element or code that differs, 2: title, content or excerpt */
							__( 'La traduzione aggiunge o cambia codice attivo (script, iframe, moduli, gestori di eventi, javascript:, shortcode, espressioni {…}) rispetto all’originale: non viene salvata. Elemento: %1$s (in %2$s).', 'lingua-bridge' ),
							$what,
							array( 'title' => __( 'titolo', 'lingua-bridge' ), 'content' => __( 'contenuto', 'lingua-bridge' ), 'excerpt' => __( 'riassunto', 'lingua-bridge' ) )[ $key ]
						)
					);
				}
			}
		}
		return true;
	}

	/** Post meta: what a machine changed in the structure of a draft, for whoever reviews it (F8). */
	const STRUCTURE_META = '_lingua_bridge_structure_notes';

	/**
	 * What a translation changed besides texts, compared with its original: the blocks (name and the attributes
	 * that are not texts for people) and the links that lead to another site. Not a refusal: a help for whoever
	 * publishes, shown in the editor. At most 10 notes.
	 *
	 * @return list<string>
	 */
	public static function structure_differences( $original, $translation ) {
		$notes = array();
		if ( function_exists( 'parse_blocks' ) && ( false !== strpos( (string) $original, '<!-- wp:' ) || false !== strpos( (string) $translation, '<!-- wp:' ) ) ) {
			$from = self::block_shapes( parse_blocks( (string) $original ) );
			$to = self::block_shapes( parse_blocks( (string) $translation ) );
			if ( count( $from ) !== count( $to ) ) {
				/* translators: 1: blocks in the original, 2: blocks in the translation. */
				$notes[] = sprintf( __( 'Blocchi: %1$d nell’originale, %2$d nella traduzione.', 'lingua-bridge' ), count( $from ), count( $to ) );
			}
			foreach ( $from as $i => $shape ) {
				if ( ! isset( $to[ $i ] ) || count( $notes ) >= 10 ) {
					break;
				}
				if ( $shape['name'] !== $to[ $i ]['name'] ) {
					/* translators: 1: block number, 2: block of the original, 3: block of the translation. */
					$notes[] = sprintf( __( 'Blocco %1$d: %2$s è diventato %3$s.', 'lingua-bridge' ), $i + 1, $shape['name'], $to[ $i ]['name'] );
				} elseif ( $shape['attrs'] !== $to[ $i ]['attrs'] ) {
					/* translators: 1: block number, 2: block name, 3: the attributes that changed. */
					$notes[] = sprintf( __( 'Blocco %1$d (%2$s): attributi cambiati (%3$s).', 'lingua-bridge' ), $i + 1, $shape['label'], implode( ', ', array_keys( array_diff_assoc( $to[ $i ]['flat'], $shape['flat'] ) + array_diff_assoc( $shape['flat'], $to[ $i ]['flat'] ) ) ) );
				}
			}
		}
		$known = array_flip( self::link_hosts( $original ) );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		foreach ( self::link_hosts( $translation ) as $host ) {
			if ( ! isset( $known[ $host ] ) && $host !== $home && count( $notes ) < 10 ) {
				/* translators: %s: a host name. */
				$notes[] = sprintf( __( 'Link nuovo verso un altro sito: %s.', 'lingua-bridge' ), $host );
			}
		}
		return array_slice( $notes, 0, 10 );
	}

	/** @return list<array{name: string, label: string, attrs: string, flat: array<string, string>}> Every block, in order, with what is not text. */
	private static function block_shapes( $blocks ) {
		$out = array();
		$texts = array_flip( array_merge( explode( '|', Xliff::JSON_KEYS ), explode( '|', Xliff::ETCH_ATTRIBUTE_KEYS ), array( 'value', 'metadata' ) ) );
		$walk = static function ( $list ) use ( &$walk, &$out, $texts ) {
			foreach ( $list as $block ) {
				$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
				if ( '' === $name ) {
					continue;
				}
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$flat = array();
				$flatten = static function ( $value, $path ) use ( &$flatten, &$flat, $texts ) {
					foreach ( (array) $value as $key => $item ) {
						if ( isset( $texts[ (string) $key ] ) ) {
							continue;
						}
						if ( is_array( $item ) ) {
							$flatten( $item, $path . $key . '.' );
						} else {
							$flat[ $path . $key ] = is_scalar( $item ) ? (string) $item : '';
						}
					}
				};
				$flatten( $attrs, '' );
				ksort( $flat );
				$layer = is_string( $attrs['metadata']['name'] ?? null ) ? $attrs['metadata']['name'] : '';
				$out[] = array( 'name' => $name, 'label' => '' !== $layer ? $name . ' «' . $layer . '»' : $name, 'attrs' => md5( (string) wp_json_encode( $flat ) ), 'flat' => $flat );
				if ( is_array( $block['innerBlocks'] ?? null ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( $blocks );
		return $out;
	}

	/** @return list<string> The hosts the links of a text lead to (href and src in HTML, "href" and "url" in block JSON). */
	private static function link_hosts( $text ) {
		$hosts = array();
		if ( preg_match_all( '~(?:\b(?:href|src)\s*=\s*["\']|"(?:href|url|src)"\s*:\s*")((?:https?:)?//[^"\'\s<>\\\\]+)~i', (string) $text, $found ) ) {
			foreach ( $found[1] as $url ) {
				$host = strtolower( (string) wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST ) );
				if ( '' !== $host ) {
					$hosts[ $host ] = true;
				}
			}
		}
		return array_keys( $hosts );
	}

	public static function create_or_update_draft( $source_id, $language, $values ) {
		$source_id = absint( $source_id );
		$language = sanitize_text_field( $language );
		$source = get_post( $source_id );
		if ( ! $source || ! self::supports_post( $source ) || ! Translators::may_translate( $source_id, $language ) ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Il contenuto di partenza non è disponibile o non hai i permessi necessari.', 'lingua-bridge' ) );
		}
		if ( ! is_array( $values ) || ! isset( $values['title'], $values['content'] ) || ! is_string( $values['title'] ) || ! is_string( $values['content'] ) || '' === trim( $values['title'] ) ) {
			return new \WP_Error( 'lingua_invalid_translation', __( 'La traduzione deve contenere un titolo valido e un contenuto testuale.', 'lingua-bridge' ) );
		}
		foreach ( array( 'excerpt', 'slug' ) as $optional_key ) {
			if ( isset( $values[ $optional_key ] ) && ! is_string( $values[ $optional_key ] ) ) {
				return new \WP_Error( 'lingua_invalid_translation', __( 'I valori opzionali della traduzione devono essere testo.', 'lingua-bridge' ) );
			}
		}
		if ( isset( $values['fields'] ) && ! is_array( $values['fields'] ) ) {
			return new \WP_Error( 'lingua_invalid_fields', __( 'I campi tradotti devono essere una lista.', 'lingua-bridge' ) );
		}
		if ( isset( $values['fields'] ) ) {
			foreach ( $values['fields'] as $field ) {
				if ( ! is_array( $field ) || ! isset( $field['provider'], $field['key'], $field['value'] ) || ! is_string( $field['provider'] ) || ! is_string( $field['key'] ) || ! is_string( $field['value'] ) ) {
					return new \WP_Error( 'lingua_invalid_fields', __( 'Ogni campo tradotto deve avere provider, chiave e valore testuale.', 'lingua-bridge' ) );
				}
			}
		}
		$active = self::check_active_markup( array( 'title' => $source->post_title, 'content' => $source->post_content, 'excerpt' => $source->post_excerpt ), $values );
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		if ( ! isset( Languages::all()[ $language ] ) ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}
		$source_map = Storage::find( $source_id );
		if ( ! $source_map ) {
			$source_map = Storage::ensure_source( $source_id, Languages::default_code(), self::core_hash( $source_id ) );
		}
		if ( ! $source_map ) {
			return new \WP_Error( 'lingua_storage_error', __( 'Relazione della lingua non disponibile.', 'lingua-bridge' ) );
		}
		if ( $language === $source_map['language'] ) {
			return new \WP_Error( 'lingua_same_language', __( 'La lingua richiesta coincide con quella del contenuto di partenza.', 'lingua-bridge' ) );
		}
		// The original is never written by a translation (connector, Abilities, XLIFF): not in its language.
		if ( Languages::default_code() === $language ) {
			return new \WP_Error( 'lingua_original_target', __( 'L’originale non si sovrascrive con una traduzione.', 'lingua-bridge' ) );
		}

		$existing = Storage::find_group_language( $source_map['group_uuid'], $language );
		if ( $existing ) {
			$target_id = absint( $existing['object_id'] );
			// Nor the root of the group, nor a page built on its own for its language.
			if ( absint( $existing['source_id'] ) === $target_id || self::is_independent( $target_id ) ) {
				return new \WP_Error( 'lingua_protected_target', __( 'Questa versione non si sovrascrive: è l’originale o una pagina indipendente.', 'lingua-bridge' ) );
			}
			if ( ! current_user_can( 'edit_post', $target_id ) ) {
				return new \WP_Error( 'lingua_forbidden_target', __( 'Non hai i permessi per modificare la bozza tradotta.', 'lingua-bridge' ) );
			}
			$target = get_post( $target_id );
			if ( ! $target || 'draft' !== $target->post_status ) {
				return new \WP_Error( 'lingua_non_draft_target', __( 'Il connector aggiorna solo bozze. Modifica i contenuti con un altro stato dall’editor WordPress per conservarne il flusso editoriale.', 'lingua-bridge' ) );
			}
		} else {
			$target_id = self::create_draft( $source_id, $language );
			if ( is_wp_error( $target_id ) ) {
				return $target_id;
			}
			$created_here = true;
		}

		$post_data = array( 'ID' => $target_id, 'post_status' => 'draft' );
		foreach ( array( 'post_title' => 'title', 'post_content' => 'content', 'post_excerpt' => 'excerpt' ) as $post_key => $input_key ) {
			if ( isset( $values[ $input_key ] ) && is_string( $values[ $input_key ] ) ) {
				$post_data[ $post_key ] = $values[ $input_key ];
			}
		}
		if ( isset( $values['slug'] ) && is_string( $values['slug'] ) && '' !== trim( $values['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $values['slug'] );
		}
		$result = wp_update_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $result ) ) {
			// A draft made here a moment ago, still the original's copy: it goes, the error stays.
			if ( ! empty( $created_here ) ) {
				Storage::delete( $target_id );
				wp_delete_post( $target_id, true );
			}
			return $result;
		}
		if ( isset( $values['fields'] ) ) {
			$field_result = Fields::save_translatable_values( $target_id, $values['fields'] );
			if ( is_wp_error( $field_result ) ) {
				return $field_result;
			}
		}
		Storage::update_review_state( $target_id, 'needs_review' );
		// A translation of the current original (agent, XLIFF): it is up to date with it.
		if ( ! self::is_independent( $target_id ) ) {
			self::snapshot( $target_id );
		}
		// Written by a machine (agent, XLIFF): the memory learns it as such, after a person publishes it.
		update_post_meta( $target_id, Memory::WRITER_META, 'agent' );
		// What the machine changed besides texts, for whoever reviews it (a note, never a refusal).
		$notes = self::structure_differences( $source->post_content, $values['content'] );
		if ( $notes ) {
			update_post_meta( $target_id, self::STRUCTURE_META, $notes );
		} else {
			delete_post_meta( $target_id, self::STRUCTURE_META );
		}
		return $target_id;
	}

	public static function create_or_update_correction_draft( $source_id, $language, $values ) {
		$source_id = absint( $source_id );
		$language = sanitize_text_field( $language );
		$source = get_post( $source_id );
		if ( ! $source || ! self::supports_post( $source ) || ! current_user_can( 'edit_post', $source_id ) ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Il contenuto di partenza non è disponibile o non hai i permessi necessari.', 'lingua-bridge' ) );
		}
		if ( ! is_array( $values ) || ! isset( $values['title'], $values['content'] ) || ! is_string( $values['title'] ) || ! is_string( $values['content'] ) || '' === trim( $values['title'] ) ) {
			return new \WP_Error( 'lingua_invalid_translation', __( 'La traduzione deve contenere un titolo valido e un contenuto testuale.', 'lingua-bridge' ) );
		}
		foreach ( array( 'excerpt', 'slug' ) as $optional_key ) {
			if ( isset( $values[ $optional_key ] ) && ! is_string( $values[ $optional_key ] ) ) {
				return new \WP_Error( 'lingua_invalid_translation', __( 'I valori opzionali della traduzione devono essere testo.', 'lingua-bridge' ) );
			}
		}
		if ( isset( $values['fields'] ) && ! is_array( $values['fields'] ) ) {
			return new \WP_Error( 'lingua_invalid_fields', __( 'I campi tradotti devono essere una lista.', 'lingua-bridge' ) );
		}
		if ( isset( $values['fields'] ) ) {
			foreach ( $values['fields'] as $field ) {
				if ( ! is_array( $field ) || ! isset( $field['provider'], $field['key'], $field['value'] ) || ! is_string( $field['provider'] ) || ! is_string( $field['key'] ) || ! is_string( $field['value'] ) ) {
					return new \WP_Error( 'lingua_invalid_fields', __( 'Ogni campo tradotto deve avere provider, chiave e valore testuale.', 'lingua-bridge' ) );
				}
			}
		}
		$active = self::check_active_markup( array( 'title' => $source->post_title, 'content' => $source->post_content, 'excerpt' => $source->post_excerpt ), $values );
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		if ( ! isset( Languages::all()[ $language ] ) ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}

		$source_map = Storage::find( $source_id );
		if ( ! $source_map ) {
			$source_map = Storage::ensure_source( $source_id, Languages::default_code(), self::core_hash( $source_id ) );
		}
		if ( ! $source_map ) {
			return new \WP_Error( 'lingua_storage_error', __( 'Relazione della lingua non disponibile.', 'lingua-bridge' ) );
		}
		if ( $language === $source_map['language'] ) {
			return new \WP_Error( 'lingua_same_language', __( 'La lingua richiesta coincide con quella del contenuto di partenza.', 'lingua-bridge' ) );
		}

		$translation_row = Storage::find_group_language( $source_map['group_uuid'], $language );
		if ( ! $translation_row ) {
			return new \WP_Error( 'lingua_correction_target_missing', __( 'Non esiste una traduzione collegata da correggere. Usa il normale salvataggio della bozza.', 'lingua-bridge' ) );
		}
		$target_id = absint( $translation_row['object_id'] );
		$target = get_post( $target_id );
		if ( ! $target || ! self::supports_post( $target ) || $source->post_type !== $target->post_type || 'publish' !== $target->post_status ) {
			return new \WP_Error( 'lingua_correction_target_not_published', __( 'La bozza di revisione richiede una traduzione collegata e pubblicata dello stesso tipo di contenuto.', 'lingua-bridge' ) );
		}
		if ( ! current_user_can( 'edit_post', $target_id ) ) {
			return new \WP_Error( 'lingua_forbidden_target', __( 'Non hai i permessi per rivedere la traduzione pubblicata.', 'lingua-bridge' ) );
		}

		$matches = get_posts(
			array(
				'post_type'        => $target->post_type,
				'post_status'      => 'any',
				'posts_per_page'   => 3,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array(
					'relation' => 'AND',
					array( 'key' => '_lingua_bridge_correction_target_id', 'value' => (string) $target_id, 'compare' => '=' ),
					array( 'key' => '_lingua_bridge_correction_source_id', 'value' => (string) $source_id, 'compare' => '=' ),
					array( 'key' => '_lingua_bridge_correction_language', 'value' => $language, 'compare' => '=' ),
				),
			)
		);
		$matches = is_array( $matches ) ? array_values( array_unique( array_map( 'absint', $matches ) ) ) : array();
		if ( count( $matches ) > 1 ) {
			return new \WP_Error( 'lingua_correction_drafts_ambiguous', __( 'Esistono più bozze di revisione per questa traduzione. Risolvi il duplicato dall’elenco articoli prima di crearne un’altra.', 'lingua-bridge' ) );
		}

		$draft_id = empty( $matches ) ? 0 : absint( $matches[0] );
		$is_new = ! $draft_id;
		if ( $draft_id ) {
			$draft = get_post( $draft_id );
			if ( ! $draft || 'draft' !== $draft->post_status ) {
				return new \WP_Error( 'lingua_correction_copy_not_draft', __( 'La copia di revisione non è più una bozza e non verrà aggiornata automaticamente.', 'lingua-bridge' ) );
			}
			if ( ! current_user_can( 'edit_post', $draft_id ) ) {
				return new \WP_Error( 'lingua_forbidden_correction', __( 'Non hai i permessi per aggiornare la bozza di revisione.', 'lingua-bridge' ) );
			}
		} else {
			// The copy is a new item of the type: whoever cannot create one cannot make one this way either.
			$type_object = get_post_type_object( $target->post_type );
			if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
				return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi per creare contenuti di questo tipo.', 'lingua-bridge' ) );
			}
			$target_slug = isset( $target->post_name ) && '' !== $target->post_name ? $target->post_name : $target->post_title;
			$draft_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => $target->post_type,
						'post_status'    => 'draft',
						'post_title'     => $target->post_title,
						'post_content'   => $target->post_content,
						'post_excerpt'   => $target->post_excerpt,
						'post_password'  => (string) $target->post_password,
						'post_name'      => sanitize_title( $target_slug . '-review' ),
						'post_author'    => get_current_user_id(),
						'post_parent'    => absint( $target->post_parent ),
						'menu_order'     => absint( $target->menu_order ),
						'comment_status' => $target->comment_status,
						'ping_status'    => $target->ping_status,
					)
				),
				true
			);
			if ( is_wp_error( $draft_id ) || ! absint( $draft_id ) ) {
				return is_wp_error( $draft_id ) ? $draft_id : new \WP_Error( 'lingua_correction_insert_failed', __( 'Non è stato possibile creare la bozza di revisione.', 'lingua-bridge' ) );
			}
			$draft_id = absint( $draft_id );
			$thumbnail_id = get_post_thumbnail_id( $target_id );
			if ( $thumbnail_id ) {
				set_post_thumbnail( $draft_id, $thumbnail_id );
			}
			self::copy_terms( $target_id, $draft_id, $target->post_type );
			Fields::copy( $target_id, $draft_id );
		}

		update_post_meta( $draft_id, '_lingua_bridge_correction_target_id', $target_id );
		update_post_meta( $draft_id, '_lingua_bridge_correction_source_id', $source_id );
		update_post_meta( $draft_id, '_lingua_bridge_correction_language', $language );
		update_post_meta( $draft_id, '_lingua_bridge_source_id', $source_id );
		$post_data = array(
			'ID'           => $draft_id,
			'post_status'  => 'draft',
			'post_title'   => $values['title'],
			'post_content' => $values['content'],
		);
		if ( isset( $values['excerpt'] ) ) {
			$post_data['post_excerpt'] = $values['excerpt'];
		}
		if ( isset( $values['slug'] ) && '' !== trim( $values['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $values['slug'] );
		}
		$result = wp_update_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $result ) ) {
			if ( $is_new ) {
				wp_delete_post( $draft_id, true );
			}
			return $result;
		}
		if ( isset( $values['fields'] ) ) {
			$field_result = Fields::save_translatable_values( $draft_id, $values['fields'] );
			if ( is_wp_error( $field_result ) ) {
				if ( $is_new ) {
					wp_delete_post( $draft_id, true );
				}
				return $field_result;
			}
		}
		return $draft_id;
	}

	public static function create_or_update_standalone_correction_draft( $published_id, $language, $values ) {
		$published_id = absint( $published_id );
		$language = sanitize_text_field( $language );
		$target = get_post( $published_id );
		if ( ! $target || ! self::supports_post( $target ) || 'publish' !== $target->post_status || ! current_user_can( 'edit_post', $published_id ) ) {
			return new \WP_Error( 'lingua_review_target_not_published', __( 'La revisione autonoma richiede un contenuto pubblicato modificabile.', 'lingua-bridge' ) );
		}
		if ( ! isset( Languages::all()[ $language ] ) ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}
		if ( ! is_array( $values ) || ! isset( $values['title'], $values['content'] ) || ! is_string( $values['title'] ) || ! is_string( $values['content'] ) || '' === trim( $values['title'] ) ) {
			return new \WP_Error( 'lingua_invalid_translation', __( 'La revisione deve contenere un titolo valido e un contenuto testuale.', 'lingua-bridge' ) );
		}
		foreach ( array( 'excerpt', 'slug' ) as $optional_key ) {
			if ( isset( $values[ $optional_key ] ) && ! is_string( $values[ $optional_key ] ) ) {
				return new \WP_Error( 'lingua_invalid_translation', __( 'I valori opzionali della revisione devono essere testo.', 'lingua-bridge' ) );
			}
		}
		if ( isset( $values['fields'] ) && ! is_array( $values['fields'] ) ) {
			return new \WP_Error( 'lingua_invalid_fields', __( 'I campi tradotti devono essere una lista.', 'lingua-bridge' ) );
		}
		if ( isset( $values['fields'] ) ) {
			foreach ( $values['fields'] as $field ) {
				if ( ! is_array( $field ) || ! isset( $field['provider'], $field['key'], $field['value'] ) || ! is_string( $field['provider'] ) || ! is_string( $field['key'] ) || ! is_string( $field['value'] ) ) {
					return new \WP_Error( 'lingua_invalid_fields', __( 'Ogni campo tradotto deve avere provider, chiave e valore testuale.', 'lingua-bridge' ) );
				}
			}
		}
		$active = self::check_active_markup( array( 'title' => $target->post_title, 'content' => $target->post_content, 'excerpt' => $target->post_excerpt ), $values );
		if ( is_wp_error( $active ) ) {
			return $active;
		}

		$matches = get_posts(
			array(
				'post_type'        => $target->post_type,
				'post_status'      => 'any',
				'posts_per_page'   => 3,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'meta_query'       => array(
					'relation' => 'AND',
					array( 'key' => '_lingua_bridge_correction_target_id', 'value' => (string) $published_id, 'compare' => '=' ),
					array( 'key' => '_lingua_bridge_correction_source_id', 'value' => '0', 'compare' => '=' ),
					array( 'key' => '_lingua_bridge_correction_language', 'value' => $language, 'compare' => '=' ),
				)
			)
		);
		$matches = is_array( $matches ) ? array_values( array_unique( array_map( 'absint', $matches ) ) ) : array();
		if ( count( $matches ) > 1 ) {
			return new \WP_Error( 'lingua_correction_drafts_ambiguous', __( 'Esistono più copie di revisione per questo contenuto. Risolvi il duplicato prima di crearne un’altra.', 'lingua-bridge' ) );
		}

		$draft_id = empty( $matches ) ? 0 : absint( $matches[0] );
		$is_new = ! $draft_id;
		if ( $draft_id ) {
			$draft = get_post( $draft_id );
			if ( ! $draft || 'draft' !== $draft->post_status ) {
				return new \WP_Error( 'lingua_correction_copy_not_draft', __( 'La copia di revisione non è più una bozza e non verrà aggiornata automaticamente.', 'lingua-bridge' ) );
			}
			if ( ! current_user_can( 'edit_post', $draft_id ) ) {
				return new \WP_Error( 'lingua_forbidden_correction', __( 'Non hai i permessi per aggiornare la bozza di revisione.', 'lingua-bridge' ) );
			}
		} else {
			// The copy is a new item of the type: whoever cannot create one cannot make one this way either.
			$type_object = get_post_type_object( $target->post_type );
			if ( ! $type_object || ! current_user_can( $type_object->cap->create_posts ) ) {
				return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi per creare contenuti di questo tipo.', 'lingua-bridge' ) );
			}
			$target_slug = isset( $target->post_name ) && '' !== $target->post_name ? $target->post_name : $target->post_title;
			$draft_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'      => $target->post_type,
						'post_status'    => 'draft',
						'post_title'     => $target->post_title,
						'post_content'   => $target->post_content,
						'post_excerpt'   => $target->post_excerpt,
						'post_password'  => (string) $target->post_password,
						'post_name'      => sanitize_title( $target_slug . '-review' ),
						'post_author'    => get_current_user_id(),
						'post_parent'    => absint( $target->post_parent ),
						'menu_order'     => absint( $target->menu_order ),
						'comment_status' => $target->comment_status,
						'ping_status'    => $target->ping_status,
					)
				),
				true
			);
			if ( is_wp_error( $draft_id ) || ! absint( $draft_id ) ) {
				return is_wp_error( $draft_id ) ? $draft_id : new \WP_Error( 'lingua_correction_insert_failed', __( 'Non è stato possibile creare la copia di revisione.', 'lingua-bridge' ) );
			}
			$draft_id = absint( $draft_id );
			$thumbnail_id = get_post_thumbnail_id( $published_id );
			if ( $thumbnail_id ) {
				set_post_thumbnail( $draft_id, $thumbnail_id );
			}
			self::copy_terms( $published_id, $draft_id, $target->post_type );
			Fields::copy( $published_id, $draft_id );
		}

		update_post_meta( $draft_id, '_lingua_bridge_correction_target_id', $published_id );
		update_post_meta( $draft_id, '_lingua_bridge_correction_source_id', 0 );
		update_post_meta( $draft_id, '_lingua_bridge_correction_language', $language );
		$post_data = array(
			'ID'           => $draft_id,
			'post_status'  => 'draft',
			'post_title'   => $values['title'],
			'post_content' => $values['content'],
		);
		if ( isset( $values['excerpt'] ) ) {
			$post_data['post_excerpt'] = $values['excerpt'];
		}
		if ( isset( $values['slug'] ) && '' !== trim( $values['slug'] ) ) {
			$post_data['post_name'] = sanitize_title( $values['slug'] );
		}
		$result = wp_update_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $result ) ) {
			if ( $is_new ) {
				wp_delete_post( $draft_id, true );
			}
			return $result;
		}
		if ( isset( $values['fields'] ) ) {
			$field_result = Fields::save_translatable_values( $draft_id, $values['fields'] );
			if ( is_wp_error( $field_result ) ) {
				if ( $is_new ) {
					wp_delete_post( $draft_id, true );
				}
				return $field_result;
			}
		}
		return $draft_id;
	}

	public static function on_post_deleted( $post_id ) {
		$post_id = absint( $post_id );
		$row = Storage::find( $post_id );
		if ( ! $row ) {
			return;
		}

		$group_uuid = $row['group_uuid'];
		$was_root = absint( $row['source_id'] ) === $post_id;
		if ( ! Storage::delete( $post_id ) ) {
			return;
		}
		// A translation went: the original and the other translations stay as they are (their «da aggiornare» too).
		if ( ! $was_root ) {
			return;
		}
		$remaining = Storage::group_rows( $group_uuid );
		if ( empty( $remaining ) ) {
			return;
		}

		$root = null;
		foreach ( $remaining as $candidate ) {
			$candidate_post = get_post( absint( $candidate['object_id'] ) );
			if ( $candidate_post && 'trash' !== $candidate_post->post_status && Languages::default_code() === $candidate['language'] ) {
				$root = $candidate;
				break;
			}
		}
		if ( ! $root ) {
			$remaining = array_values(
				array_filter(
					$remaining,
					static function ( $candidate ) {
						$candidate_post = get_post( absint( $candidate['object_id'] ) );
						return $candidate_post && 'trash' !== $candidate_post->post_status;
					}
				)
			);
			if ( empty( $remaining ) ) {
				return;
			}
			usort(
				$remaining,
				static function ( $left, $right ) {
					return absint( $left['object_id'] ) <=> absint( $right['object_id'] );
				}
			);
			$root = reset( $remaining );
		}

		$root_id = absint( $root['object_id'] );
		if ( get_post( $root_id ) && Storage::update_group_source( $group_uuid, $root_id, self::core_hash( $root_id ) ) ) {
			foreach ( $remaining as $candidate ) {
				update_post_meta( absint( $candidate['object_id'] ), '_lingua_bridge_source_id', $root_id );
			}
		}
	}

	private static function copy_terms( $source_id, $target_id, $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			// get_the_terms() reads the term cache, filled for every taxonomy of the post at once (P4): a taxonomy
			// empty on both sides costs nothing, the default category of a new post still goes when the original has none.
			$terms = get_the_terms( $source_id, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$term_ids = $terms ? array_map( static fn( $term ) => absint( $term->term_id ), $terms ) : array();
			if ( ! $term_ids && ! get_the_terms( $target_id, $taxonomy ) ) {
				continue;
			}
			wp_set_object_terms( $target_id, $term_ids, $taxonomy, false );
		}
	}
}
