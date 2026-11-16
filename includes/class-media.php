<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The texts of a media file in every language (N9 of the 1.5.10 verification): the alternative text and the caption
 * of the library, which blocks and themes read from the file, not from the page. They are not copies of the file:
 * one meta of the attachment keeps them per language, written in the media screen by whoever may edit the file,
 * and used when an image is printed on a page of that language.
 *
 * C30 (1.11.0): a language may also use another file of the library (`file`, an attachment id): an image with words
 * in it, a PDF. On the pages of that language the image and file blocks, `wp_get_attachment_image()`, the address of
 * the file and the `srcset` take the other file, with its own sizes.
 */
final class Media {
	/** Attachment meta: language => {alt, caption}. */
	const META = '_lingua_bridge_media';

	/** Guard: the other file is read with the same functions this class filters. */
	private static $swapping = false;

	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'fields' ), 20, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save' ), 20, 2 );
		if ( ! is_admin() ) {
			add_filter( 'wp_get_attachment_url', array( __CLASS__, 'file_url' ), 20, 2 );
			add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'image_src' ), 20, 4 );
			add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'srcset' ), 20, 5 );
			add_filter( 'render_block_data', array( __CLASS__, 'block_data' ), 20 );
			add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), 20, 2 );
			add_filter( 'wp_get_attachment_caption', array( __CLASS__, 'caption' ), 20, 2 );
			add_filter( 'get_post_metadata', array( __CLASS__, 'library_alt' ), 20, 4 );
		}
	}

	/** @return array<string, array{alt: string, caption: string}> */
	public static function texts( $attachment_id ) {
		$saved = get_post_meta( absint( $attachment_id ), self::META, true );
		return is_array( $saved ) ? $saved : array();
	}

	/** C30: the other file of the library that `$attachment_id` uses in `$language`, 0 for none. */
	public static function file_for( $attachment_id, $language ) {
		$id = absint( self::texts( $attachment_id )[ $language ]['file'] ?? 0 );
		return $id && $id !== absint( $attachment_id ) && 'attachment' === get_post_type( $id ) ? $id : 0;
	}

	/** The other file of the page's language, 0 on the original's pages or while reading the other file. */
	private static function swap_for( $attachment_id ) {
		$language = Frontend::current_language();
		return self::$swapping || Languages::default_code() === $language ? 0 : self::file_for( $attachment_id, $language );
	}

	public static function file_url( $url, $attachment_id ) {
		$other = self::swap_for( $attachment_id );
		if ( ! $other ) {
			return $url;
		}
		self::$swapping = true;
		$swapped = wp_get_attachment_url( $other );
		self::$swapping = false;
		return $swapped ? $swapped : $url;
	}

	public static function image_src( $image, $attachment_id, $size, $icon ) {
		$other = self::swap_for( $attachment_id );
		if ( ! $other ) {
			return $image;
		}
		self::$swapping = true;
		$swapped = wp_get_attachment_image_src( $other, $size, $icon );
		self::$swapping = false;
		return $swapped ? $swapped : $image;
	}

	/** The srcset of the other file, from its own sizes. */
	public static function srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$other = self::swap_for( $attachment_id );
		if ( ! $other ) {
			return $sources;
		}
		self::$swapping = true;
		$meta = wp_get_attachment_metadata( $other );
		$src = wp_get_attachment_image_src( $other, $size_array );
		$swapped = is_array( $meta ) && $src ? self::sources( $size_array, $src[0], $meta, $other ) : false;
		self::$swapping = false;
		return $swapped ? $swapped : $sources;
	}

	/** The candidates of the srcset of a file, as WordPress computes them (wp_calculate_image_srcset() gives a string). */
	private static function sources( $size_array, $src, $meta, $id ) {
		$set = wp_calculate_image_srcset( $size_array, $src, $meta, $id );
		if ( ! is_string( $set ) || '' === $set ) {
			return false;
		}
		$out = array();
		foreach ( explode( ', ', $set ) as $candidate ) {
			$parts = explode( ' ', trim( $candidate ) );
			if ( 2 === count( $parts ) && preg_match( '/^(\d+)w$/', $parts[1], $m ) ) {
				$out[ (int) $m[1] ] = array( 'url' => $parts[0], 'descriptor' => 'w', 'value' => (int) $m[1] );
			}
		}
		return $out ? $out : false;
	}

	/**
	 * The image and file blocks of a page in a language with another file: the block names the other file, so its
	 * address, its class `wp-image-<id>` and so its srcset (added later by WordPress) are the other file's.
	 */
	public static function block_data( $block ) {
		$name = (string) ( $block['blockName'] ?? '' );
		$id = absint( $block['attrs']['id'] ?? 0 );
		if ( ! in_array( $name, array( 'core/image', 'core/file', 'core/media-text', 'core/cover' ), true ) || ! $id ) {
			return $block;
		}
		$other = self::swap_for( $id );
		if ( ! $other ) {
			return $block;
		}
		self::$swapping = true;
		$old_url = wp_get_attachment_url( $id );
		$new_url = wp_get_attachment_url( $other );
		$size = (string) ( $block['attrs']['sizeSlug'] ?? 'full' );
		$old_src = wp_get_attachment_image_src( $id, $size );
		$new_src = wp_get_attachment_image_src( $other, $size );
		self::$swapping = false;
		$pairs = array( 'wp-image-' . $id . '"' => 'wp-image-' . $other . '"', 'wp-image-' . $id . ' ' => 'wp-image-' . $other . ' ' );
		if ( $old_src && $new_src ) {
			$pairs[ $old_src[0] ] = $new_src[0];
		}
		if ( $old_url && $new_url ) {
			$pairs[ $old_url ] = $new_url;
		}
		$block['attrs']['id'] = $other;
		foreach ( array( 'innerHTML' ) as $key ) {
			if ( isset( $block[ $key ] ) && is_string( $block[ $key ] ) ) {
				$block[ $key ] = strtr( $block[ $key ], $pairs );
			}
		}
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $i => $part ) {
			if ( is_string( $part ) ) {
				$block['innerContent'][ $i ] = strtr( $part, $pairs );
			}
		}
		foreach ( array( 'href', 'url', 'mediaLink' ) as $attr ) {
			if ( isset( $block['attrs'][ $attr ] ) && is_string( $block['attrs'][ $attr ] ) ) {
				$block['attrs'][ $attr ] = strtr( $block['attrs'][ $attr ], $pairs );
			}
		}
		return $block;
	}

	/** One text of a file in a language, or '' when it has none (the original's is used). */
	public static function text( $attachment_id, $language, $key ) {
		$texts = self::texts( $attachment_id );
		return isset( $texts[ $language ][ $key ] ) && is_string( $texts[ $language ][ $key ] ) ? $texts[ $language ][ $key ] : '';
	}

	/** The fields of the media screen (and of the media modal): alt and caption for each language of translation. */
	public static function fields( $fields, $post ) {
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) || count( Languages::all() ) < 2 ) {
			return $fields;
		}
		foreach ( Languages::all() as $code => $label ) {
			if ( Languages::default_code() === $code ) {
				continue;
			}
			if ( wp_attachment_is_image( $post ) ) {
				$fields[ 'lingua_bridge_alt_' . $code ] = array(
					/* translators: %s: language name. */
					'label' => sprintf( __( 'Testo alternativo (%s)', 'lingua-bridge' ), $label ),
					'input' => 'text',
					'value' => self::text( $post->ID, $code, 'alt' ),
				);
			}
			// C30: another file of the library for this language.
			$other = self::file_for( $post->ID, $code );
			$fields[ 'lingua_bridge_file_' . $code ] = array(
				/* translators: %s: language name. */
				'label' => sprintf( __( 'File in %s', 'lingua-bridge' ), $label ),
				'input' => 'text',
				'value' => $other ? (string) $other : '',
				'helps' => $other ? sprintf( /* translators: %s: file name. */ __( 'Usa «%s». Vuoto: lo stesso file dell’originale.', 'lingua-bridge' ), wp_basename( (string) get_attached_file( $other ) ) ) : __( 'L’ID di un altro file della libreria da usare nelle pagine di questa lingua (un’immagine con il testo tradotto, un PDF). Vuoto: lo stesso file.', 'lingua-bridge' ),
			);
			$fields[ 'lingua_bridge_caption_' . $code ] = array(
				/* translators: %s: language name. */
				'label' => sprintf( __( 'Didascalia (%s)', 'lingua-bridge' ), $label ),
				'input' => 'textarea',
				'value' => self::text( $post->ID, $code, 'caption' ),
				'helps' => __( 'Vuoto: le pagine di questa lingua mostrano quella dell’originale.', 'lingua-bridge' ),
			);
		}
		return $fields;
	}

	/** Saved with the media screen: plain text, per language; an empty text removes it. */
	public static function save( $post, $attachment ) {
		$id = absint( $post['ID'] ?? 0 );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! is_array( $attachment ) ) {
			return $post;
		}
		$texts = self::texts( $id );
		foreach ( Languages::all() as $code => $label ) {
			if ( Languages::default_code() === $code ) {
				continue;
			}
			foreach ( array( 'alt', 'caption' ) as $key ) {
				$field = 'lingua_bridge_' . $key . '_' . $code;
				if ( ! array_key_exists( $field, $attachment ) ) {
					continue;
				}
				$value = 'alt' === $key ? sanitize_text_field( (string) $attachment[ $field ] ) : sanitize_textarea_field( (string) $attachment[ $field ] );
				if ( '' === trim( $value ) ) {
					unset( $texts[ $code ][ $key ] );
				} else {
					$texts[ $code ][ $key ] = $value;
				}
			}
			$field = 'lingua_bridge_file_' . $code;
			if ( array_key_exists( $field, $attachment ) ) {
				$other = absint( $attachment[ $field ] );
				if ( $other && $other !== $id && 'attachment' === get_post_type( $other ) && current_user_can( 'read_post', $other ) ) {
					$texts[ $code ]['file'] = $other;
				} else {
					unset( $texts[ $code ]['file'] );
				}
			}
			if ( empty( $texts[ $code ] ) ) {
				unset( $texts[ $code ] );
			}
		}
		$texts ? update_post_meta( $id, self::META, $texts ) : delete_post_meta( $id, self::META );
		Storage::content_changed();
		return $post;
	}

	/** An image printed from the library on a page of another language: its alternative text in that language. */
	public static function image_attributes( $attributes, $attachment ) {
		$language = Frontend::current_language();
		if ( ! $attachment instanceof \WP_Post || Languages::default_code() === $language ) {
			return $attributes;
		}
		$alt = self::text( $attachment->ID, $language, 'alt' );
		// Only the library's own text is replaced: an alt written in the page is the page's translation.
		// The saved alt, read without library_alt(): all the meta of the file, where that filter does not act.
		$library = get_post_meta( $attachment->ID )['_wp_attachment_image_alt'][0] ?? '';
		if ( '' !== $alt && ( ! isset( $attributes['alt'] ) || in_array( (string) $attributes['alt'], array( (string) $library, $alt ), true ) ) ) {
			$attributes['alt'] = $alt;
		}
		return $attributes;
	}

	/** Builders that read the library's alt as a meta (Etch does) get it in the language of the page too. */
	public static function library_alt( $value, $object_id, $meta_key, $single ) {
		if ( '_wp_attachment_image_alt' !== $meta_key || null !== $value || Languages::default_code() === Frontend::current_language() ) {
			return $value;
		}
		$alt = self::text( $object_id, Frontend::current_language(), 'alt' );
		return '' !== $alt ? array( $alt ) : $value;
	}

	public static function caption( $caption, $attachment_id ) {
		$language = Frontend::current_language();
		if ( Languages::default_code() === $language ) {
			return $caption;
		}
		$translated = self::text( $attachment_id, $language, 'caption' );
		return '' !== $translated ? $translated : $caption;
	}
}
