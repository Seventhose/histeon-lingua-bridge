<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fields {
	/**
	 * Copy field values using the owning provider where a provider API is available.
	 * Unknown public post meta is copied as a fallback; protected WordPress metadata is not.
	 */
	/**
	 * What a translation draft of this post takes with it, in words, before it is made: what is translated, what
	 * is only copied (to adjust by hand) and what stays behind. Shown on "Crea traduzione".
	 *
	 * @return array<int, array{label: string, state: string, detail: string}> state: translated|copied|missing
	 */
	public static function support_report( $post_id ) {
		$post_id = absint( $post_id );
		$rows = array(
			array( 'label' => __( 'Titolo, contenuto (blocchi Gutenberg/Etch), riassunto', 'lingua-bridge' ), 'state' => 'translated', 'detail' => __( 'copiati nella bozza e inviati alla traduzione', 'lingua-bridge' ) ),
			array( 'label' => __( 'Immagine in evidenza, categorie e tag', 'lingua-bridge' ), 'state' => 'copied', 'detail' => __( 'uguali all’originale, da cambiare se serve', 'lingua-bridge' ) ),
		);
		if ( function_exists( 'acf_get_field_groups' ) ) {
			$groups = acf_get_field_groups( array( 'post_id' => $post_id ) );
			if ( ! empty( $groups ) ) {
				$rows[] = array( 'label' => sprintf( /* translators: %d: number of groups */ __( 'Campi ACF/SCF (%d gruppi)', 'lingua-bridge' ), count( $groups ) ), 'state' => 'translated', 'detail' => __( 'testi tradotti; immagini, relazioni, ripetitori e scelte copiati', 'lingua-bridge' ) );
			}
		}
		$etch = self::etch_groups_for_post( $post_id );
		if ( ! empty( $etch ) ) {
			$rows[] = array( 'label' => sprintf( /* translators: %d: number of groups */ __( 'Campi Etch (%d gruppi)', 'lingua-bridge' ), count( $etch ) ), 'state' => 'copied', 'detail' => __( 'copiati, da tradurre nell’editor', 'lingua-bridge' ) );
		}
		$allow = array_flip( Languages::translatable_meta_keys() );
		$public = 0;
		$translated = 0;
		$private = 0;
		$seo_meta = array_flip( array_map( static fn( $key ) => (string) strtok( (string) $key, ':' ), array_keys( self::seo_fields() ) ) );
		foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
			if ( isset( $seo_meta[ $key ] ) ) {
				continue;
			}
			if ( 0 === strpos( (string) $key, '_' ) ) {
				++$private;
			} elseif ( isset( $allow[ $key ] ) ) {
				++$translated;
			} else {
				++$public;
			}
		}
		if ( $translated ) {
			$rows[] = array( 'label' => sprintf( /* translators: %d: number of fields */ __( 'Meta testuali scelti nelle impostazioni (%d)', 'lingua-bridge' ), $translated ), 'state' => 'translated', 'detail' => __( 'inviati alla traduzione', 'lingua-bridge' ) );
		}
		if ( $public ) {
			$rows[] = array( 'label' => sprintf( /* translators: %d: number of fields */ __( 'Altri campi personalizzati (%d)', 'lingua-bridge' ), $public ), 'state' => 'copied', 'detail' => __( 'copiati così come sono (anche quelli di JetEngine e Meta Box)', 'lingua-bridge' ) );
		}
		if ( $private ) {
			$rows[] = array( 'label' => sprintf( /* translators: %d: number of fields */ __( 'Dati interni dei plugin (%d chiavi che iniziano con _)', 'lingua-bridge' ), $private ), 'state' => 'missing', 'detail' => __( 'non copiati: li rigenerano i plugin che li possiedono', 'lingua-bridge' ) );
		}
		$seo = 0;
		foreach ( array_keys( self::seo_fields() ) as $key ) {
			$seo += '' !== trim( self::seo_value( $post_id, $key ) ) ? 1 : 0;
		}
		if ( $seo ) {
			$rows[] = array( 'label' => sprintf( /* translators: %d: number of fields */ __( 'Titoli e descrizioni SEO (%d)', 'lingua-bridge' ), $seo ), 'state' => 'translated', 'detail' => __( 'Yoast, Rank Math, SEOPress o Slim SEO: copiati e inviati alla traduzione', 'lingua-bridge' ) );
		}
		if ( function_exists( 'jet_engine' ) ) {
			$rows[] = array( 'label' => __( 'JetEngine: relazioni e Custom Content Types', 'lingua-bridge' ), 'state' => 'missing', 'detail' => __( 'stanno in tabelle proprie di JetEngine: vanno ricollegati a mano nella traduzione', 'lingua-bridge' ) );
		}
		return apply_filters( 'lingua_bridge_support_report', $rows, $post_id );
	}

	public static function copy( $source_id, $target_id ) {
		self::copy_acf_compatible_fields( $source_id, $target_id );
		self::copy_public_meta( $source_id, $target_id );
		self::copy_etch_fields( $source_id, $target_id );
		self::copy_seo_meta( $source_id, $target_id );
		do_action( 'lingua_bridge_copy_custom_fields', absint( $source_id ), absint( $target_id ) );
	}

	/**
	 * Copy Etch native post fields and their group reference metadata.
	 * Etch reads the `_{$key}` reference to resolve a field through the `etch` namespace.
	 */
	private static function copy_etch_fields( $source_id, $target_id ) {
		foreach ( self::etch_groups_for_post( $source_id ) as $group_id => $group ) {
			foreach ( isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array() as $field ) {
				if ( ! is_array( $field ) || empty( $field['key'] ) ) {
					continue;
				}
				$key = sanitize_text_field( $field['key'] );
				if ( ! metadata_exists( 'post', absint( $source_id ), $key ) ) {
					continue;
				}
				update_post_meta( absint( $target_id ), $key, wp_slash( get_post_meta( absint( $source_id ), $key, true ) ) );
				update_post_meta( absint( $target_id ), '_' . $key, 'etch_field_group_' . sanitize_key( $group_id ) );
			}
		}
	}

	/**
	 * Return Etch field groups that apply to a post through a post-type assignment.
	 */
	private static function etch_groups_for_post( $post_id ) {
		$groups = get_option( 'etch_cfs', array() );
		$post_type = get_post_type( absint( $post_id ) );
		if ( ! is_array( $groups ) || ! $post_type ) {
			return array();
		}
		$matched = array();
		foreach ( $groups as $group_id => $group ) {
			$assignment = isset( $group['assigned_to'] ) && is_array( $group['assigned_to'] ) ? $group['assigned_to'] : array();
			$post_types = isset( $assignment['post_types'] ) && is_array( $assignment['post_types'] ) ? $assignment['post_types'] : array();
			if ( empty( $post_types ) ) {
				continue;
			}
			$included = in_array( $post_type, $post_types, true );
			$applies = isset( $assignment['op'] ) && 'isNotIn' === $assignment['op'] ? ! $included : $included;
			if ( $applies ) {
				$matched[ (string) $group_id ] = $group;
			}
		}
		return $matched;
	}

	private static function copy_acf_compatible_fields( $source_id, $target_id ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) || ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
			return;
		}
		$groups = acf_get_field_groups( array( 'post_id' => absint( $source_id ) ) );
		if ( ! is_array( $groups ) ) {
			return;
		}
		foreach ( $groups as $group ) {
			$fields = acf_get_fields( $group );
			if ( ! is_array( $fields ) ) {
				continue;
			}
			self::copy_acf_field_tree( $fields, $source_id, $target_id );
		}
	}

	private static function copy_acf_field_tree( $fields, $source_id, $target_id ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			$value = get_field( $field['key'], absint( $source_id ), false );
			if ( null !== $value && false !== $value && 'ignore' !== Sync::mode( (string) ( $field['name'] ?? '' ) ) ) {
				update_field( $field['key'], $value, absint( $target_id ) );
			}
		}
	}

	private static function copy_public_meta( $source_id, $target_id ) {
		$all_meta = get_post_meta( absint( $source_id ) );
		if ( ! is_array( $all_meta ) ) {
			return;
		}
		// A canonical address is the original's own: the translation has its own (C10).
		$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_thumbnail_id', 'rank_math_canonical_url' );
		$skip = apply_filters( 'lingua_bridge_skip_meta_keys', $skip, absint( $source_id ) );
		foreach ( $all_meta as $key => $values ) {
			// C13: a field set to «Ignora» stays behind.
			if ( 0 === strpos( $key, '_' ) || in_array( $key, $skip, true ) || ! is_array( $values ) || 'ignore' === Sync::mode( $key ) ) {
				continue;
			}
			delete_post_meta( absint( $target_id ), $key );
			foreach ( $values as $value ) {
				// add_post_meta() unslashes: slashed here, a backslash in a value survives the copy.
				add_post_meta( absint( $target_id ), $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * C10: the SEO texts of a content, by plugin: meta key (or `meta:key` inside an array meta, as Slim SEO keeps
	 * them) => array( label, type ). Yoast and SEOPress keep them in meta starting with `_`, which copy_public_meta()
	 * leaves behind: copy_seo_meta() copies these. `lingua_bridge_seo_fields` adds or removes some.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function seo_fields() {
		$title = __( 'titolo SEO', 'lingua-bridge' );
		$description = __( 'descrizione SEO', 'lingua-bridge' );
		$keyword = __( 'parola chiave', 'lingua-bridge' );
		$social_title = __( 'titolo social', 'lingua-bridge' );
		$social_description = __( 'descrizione social', 'lingua-bridge' );
		$fields = array();
		foreach ( array(
			'Yoast'     => array( '_yoast_wpseo_title' => $title, '_yoast_wpseo_metadesc' => $description, '_yoast_wpseo_focuskw' => $keyword, '_yoast_wpseo_opengraph-title' => $social_title, '_yoast_wpseo_opengraph-description' => $social_description, '_yoast_wpseo_twitter-title' => $social_title . ' (X)', '_yoast_wpseo_twitter-description' => $social_description . ' (X)' ),
			'Rank Math' => array( 'rank_math_title' => $title, 'rank_math_description' => $description, 'rank_math_focus_keyword' => $keyword, 'rank_math_facebook_title' => $social_title, 'rank_math_facebook_description' => $social_description, 'rank_math_twitter_title' => $social_title . ' (X)', 'rank_math_twitter_description' => $social_description . ' (X)' ),
			'SEOPress'  => array( '_seopress_titles_title' => $title, '_seopress_titles_desc' => $description, '_seopress_analysis_target_kw' => $keyword, '_seopress_social_fb_title' => $social_title, '_seopress_social_fb_desc' => $social_description, '_seopress_social_twitter_title' => $social_title . ' (X)', '_seopress_social_twitter_desc' => $social_description . ' (X)' ),
			'Slim SEO'  => array( 'slim_seo:title' => $title, 'slim_seo:description' => $description ),
		) as $plugin => $keys ) {
			foreach ( $keys as $key => $label ) {
				$fields[ $key ] = array( $plugin . ': ' . $label, false !== strpos( $key, 'desc' ) ? 'textarea' : 'text' );
			}
		}
		return (array) apply_filters( 'lingua_bridge_seo_fields', $fields );
	}

	/** The value of an SEO field of `seo_fields()` on a post: a string, '' when there is none. */
	public static function seo_value( $post_id, $key ) {
		$parts = explode( ':', (string) $key, 2 );
		$value = get_post_meta( absint( $post_id ), $parts[0], true );
		if ( isset( $parts[1] ) ) {
			$value = is_array( $value ) && isset( $value[ $parts[1] ] ) ? $value[ $parts[1] ] : '';
		}
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Writes an SEO text of `seo_fields()` on a translation: plain text, and the plugin's variables (Yoast's
	 * `%%title%%`, Rank Math's `%sep%`) exactly as in the original. Returns false when the text was refused.
	 */
	public static function save_seo_value( $post_id, $key, $value, $source_id = 0 ) {
		$fields = self::seo_fields();
		if ( ! isset( $fields[ $key ] ) || ! is_string( $value ) ) {
			return false;
		}
		$clean = 'textarea' === $fields[ $key ][1] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		$variables = static function ( $text ) {
			preg_match_all( '/%%?[a-z_]+%%?/i', (string) $text, $found );
			sort( $found[0] );
			return $found[0];
		};
		if ( $source_id && $variables( self::seo_value( $source_id, $key ) ) !== $variables( $clean ) ) {
			return false;
		}
		$parts = explode( ':', (string) $key, 2 );
		if ( isset( $parts[1] ) ) {
			$array = get_post_meta( absint( $post_id ), $parts[0], true );
			$array = is_array( $array ) ? $array : array();
			$array[ $parts[1] ] = $clean;
			update_post_meta( absint( $post_id ), $parts[0], wp_slash( $array ) );
		} else {
			update_post_meta( absint( $post_id ), $parts[0], wp_slash( $clean ) );
		}
		return true;
	}

	/**
	 * The SEO texts go with a new translation, to be translated (C10): those in meta starting with `_` (Yoast,
	 * SEOPress), which the public copy leaves behind. A canonical address is never copied: it is the original's.
	 */
	private static function copy_seo_meta( $source_id, $target_id ) {
		foreach ( array_keys( self::seo_fields() ) as $key ) {
			$meta = (string) strtok( (string) $key, ':' );
			if ( 0 === strpos( $meta, '_' ) && metadata_exists( 'post', absint( $source_id ), $meta ) ) {
				update_post_meta( absint( $target_id ), $meta, wp_slash( get_post_meta( absint( $source_id ), $meta, true ) ) );
			}
		}
		$slim = get_post_meta( absint( $target_id ), 'slim_seo', true );
		if ( is_array( $slim ) && isset( $slim['canonical'] ) ) {
			unset( $slim['canonical'] );
			update_post_meta( absint( $target_id ), 'slim_seo', wp_slash( $slim ) );
		}
	}

	public static function translatable_values( $post_id ) {
		$fields = array();
		foreach ( self::seo_fields() as $key => $definition ) {
			$value = self::seo_value( $post_id, $key );
			if ( '' !== trim( $value ) ) {
				$fields[] = array(
					'provider' => 'seo',
					'key'      => $key,
					'label'    => $definition[0],
					'type'     => $definition[1],
					'value'    => $value,
				);
			}
		}
		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'get_field' ) ) {
			$groups = acf_get_field_groups( array( 'post_id' => absint( $post_id ) ) );
			if ( is_array( $groups ) ) {
				foreach ( $groups as $group ) {
					$definitions = acf_get_fields( $group );
					if ( is_array( $definitions ) ) {
						self::collect_acf_text_fields( $definitions, $post_id, $fields );
					}
				}
			}
		}

		foreach ( Languages::translatable_meta_keys() as $key ) {
			$value = get_post_meta( absint( $post_id ), $key, true );
			if ( is_string( $value ) ) {
				$fields[] = array(
					'provider' => 'post_meta',
					'key'      => $key,
					'label'    => $key,
					'type'     => 'text',
					'value'    => $value,
				);
			}
		}

		foreach ( self::etch_groups_for_post( $post_id ) as $group_id => $group ) {
			foreach ( isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array() as $field ) {
				if ( ! is_array( $field ) || empty( $field['key'] ) || ! in_array( isset( $field['type'] ) ? $field['type'] : '', array( 'text', 'textarea' ), true ) ) {
					continue;
				}
				$key = sanitize_text_field( $field['key'] );
				$value = get_post_meta( absint( $post_id ), $key, true );
				if ( is_string( $value ) && '' !== $value ) {
					$fields[] = array(
						'provider' => 'etch',
						'key'      => $key,
						'label'    => sanitize_text_field( isset( $field['label'] ) ? $field['label'] : $key ),
						'type'     => sanitize_key( $field['type'] ),
						'group_id' => sanitize_key( $group_id ),
						'value'    => $value,
					);
				}
			}
		}

		// C14: the texts inside repeaters, flexible contents and groups, one by one.
		$fields = array_merge( $fields, Acf::sub_fields( $post_id ) );
		return apply_filters( 'lingua_bridge_translatable_fields', $fields, absint( $post_id ) );
	}

	private static function collect_acf_text_fields( $definitions, $post_id, &$fields ) {
		$allowed = array( 'text', 'textarea', 'wysiwyg', 'email', 'url' );
		foreach ( $definitions as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			if ( ! in_array( isset( $field['type'] ) ? $field['type'] : '', $allowed, true ) ) {
				continue;
			}
			// C13: a text field set to copy or to ignore is not sent to translation.
			if ( 'copy_once' !== Sync::mode( (string) ( $field['name'] ?? '' ) ) && 'translate' !== Sync::mode( (string) ( $field['name'] ?? '' ) ) ) {
				continue;
			}
			$value = get_field( $field['key'], absint( $post_id ), false );
			if ( is_string( $value ) && '' !== $value ) {
				$fields[] = array(
					'provider' => 'acf',
					'key'      => sanitize_text_field( $field['key'] ),
					'label'    => sanitize_text_field( isset( $field['label'] ) ? $field['label'] : $field['name'] ),
					'type'     => sanitize_key( $field['type'] ),
					'value'    => $value,
				);
			}
		}
	}

	public static function save_translatable_values( $post_id, $values ) {
		if ( ! is_array( $values ) ) {
			return new \WP_Error( 'lingua_invalid_fields', __( 'I campi tradotti devono essere una lista.', 'lingua-bridge' ) );
		}
		$source_id = absint( get_post_meta( absint( $post_id ), '_lingua_bridge_source_id', true ) );
		if ( ! $source_id ) {
			$translation_row = Storage::find( absint( $post_id ) );
			$source_id = $translation_row ? absint( $translation_row['source_id'] ) : 0;
		}
		$allowed_acf = array();
		if ( $source_id && function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			$groups = acf_get_field_groups( array( 'post_id' => $source_id ) );
			foreach ( is_array( $groups ) ? $groups : array() as $group ) {
				$defs = acf_get_fields( $group );
				if ( is_array( $defs ) ) {
					self::collect_allowed_acf_keys( $defs, $allowed_acf );
				}
			}
		}
		$meta_keys = Languages::translatable_meta_keys();
		$etch_fields = array();
		foreach ( $source_id ? self::etch_groups_for_post( $source_id ) : array() as $group_id => $group ) {
			foreach ( isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array() as $field_definition ) {
				if ( is_array( $field_definition ) && ! empty( $field_definition['key'] ) && in_array( isset( $field_definition['type'] ) ? $field_definition['type'] : '', array( 'text', 'textarea' ), true ) ) {
					$etch_fields[ sanitize_text_field( $field_definition['key'] ) ] = array( 'group_id' => sanitize_key( $group_id ), 'type' => sanitize_key( $field_definition['type'] ) );
				}
			}
		}
		foreach ( $values as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['provider'], $field['key'], $field['value'] ) || ! is_string( $field['value'] ) ) {
				continue;
			}
			$key = sanitize_text_field( $field['key'] );
			if ( 'acf' === $field['provider'] && isset( $allowed_acf[ $key ] ) && function_exists( 'update_field' ) ) {
				// A link stays a link (http, https, mailto, tel): never javascript: from a translator's file.
				$type = $allowed_acf[ $key ];
				$clean = 'url' === $type ? esc_url_raw( $field['value'], array( 'http', 'https', 'mailto', 'tel' ) ) : ( 'email' === $type ? sanitize_email( $field['value'] ) : wp_kses_post( $field['value'] ) );
				update_field( $key, $clean, absint( $post_id ) );
			} elseif ( 'post_meta' === $field['provider'] && in_array( $key, $meta_keys, true ) ) {
				// update_post_meta() unslashes: wp_slash() keeps a «\\» of the translation.
				update_post_meta( absint( $post_id ), $key, wp_slash( wp_kses_post( $field['value'] ) ) );
			} elseif ( 'acf_sub' === $field['provider'] && $source_id ) {
				Acf::save_sub( $post_id, $key, $field['value'], $source_id );
			} elseif ( 'seo' === $field['provider'] ) {
				self::save_seo_value( $post_id, $key, $field['value'], $source_id );
			} elseif ( 'etch' === $field['provider'] && isset( $etch_fields[ $key ] ) ) {
				$clean_value = 'textarea' === $etch_fields[ $key ]['type'] ? sanitize_textarea_field( $field['value'] ) : sanitize_text_field( $field['value'] );
				update_post_meta( absint( $post_id ), $key, wp_slash( $clean_value ) );
				update_post_meta( absint( $post_id ), '_' . $key, 'etch_field_group_' . $etch_fields[ $key ]['group_id'] );
			}
		}
		do_action( 'lingua_bridge_save_translatable_fields', absint( $post_id ), $values, $source_id );
		return true;
	}

	private static function collect_allowed_acf_keys( $definitions, &$keys ) {
		$allowed = array( 'text', 'textarea', 'wysiwyg', 'email', 'url' );
		foreach ( $definitions as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			if ( in_array( isset( $field['type'] ) ? $field['type'] : '', $allowed, true ) ) {
				$keys[ sanitize_text_field( $field['key'] ) ] = (string) $field['type'];
			}
		}
	}
}
