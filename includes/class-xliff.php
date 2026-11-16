<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XLIFF 1.2 for translators and agencies (docs/XLIFF.md). A content goes out as clean text segments (title,
 * excerpt, each visible text of the blocks, each translatable field) and comes back as a draft built from the
 * original's structure with the translated texts: the markup is never the translator's to change.
 */
final class Xliff {
	const NS = 'urn:oasis:names:tc:xliff:document:1.2';
	const LB_NS = 'https://lingua-bridge.dev/xliff';
	const MAX_BYTES = 10485760;
	const MAX_CONTENTS = 200;
	/** F7: contents per file in a download; more of them, or more languages, go in a ZIP of files. */
	const PER_FILE = 100;
	/** Block attributes that hold text for people. */
	/** The inline formats a rich prop of a Histeon component may hold, as Histeon's own `.inline()` keeps them. */
	const INLINE_TAGS = array(
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'u'      => array(),
		's'      => array(),
		'sub'    => array(),
		'sup'    => array(),
		'code'   => array(),
		'br'     => array(),
		'a'      => array( 'href' => true ),
	);
	const JSON_KEYS = 'content|text|label|alt|title|placeholder|caption|heading|description|buttonText|ariaLabel|citation|value|name|bio|author|publisher|linkLabel|question|answer|place|date';
	/** The HTML attributes an Etch element holds for people (its "attributes" object): the rest (name, class, href…) is code. */
	const ETCH_ATTRIBUTE_KEYS = 'aria-label|aria-description|aria-placeholder|aria-roledescription|alt|title|placeholder|label';
	const MARK = "\x00LB%d\x00";

	public static function init() {
		add_action( 'admin_post_lingua_bridge_xliff_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_lingua_bridge_xliff_import', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * The visible texts of a content, in order, and the content with a marker in place of each.
	 *
	 * `ok` is false when a pattern failed (content too long or too complex): then nothing may be built from it.
	 *
	 * @return array{template: string, segments: list<array{kind: string, text: string}>, ok: bool}
	 */
	public static function segment( $content ) {
		$segments = array();
		$failed = false;
		$mark = static function ( $kind, $text ) use ( &$segments ) {
			$segments[] = array( 'kind' => $kind, 'text' => $text );
			return sprintf( self::MARK, count( $segments ) - 1 );
		};
		// Words for people: letters, and not just an expression of the builder ({post.title}).
		$has_words = static fn( $text ) => (bool) preg_match( '/\p{L}/u', $text ) && ! preg_match( '/\A\s*\{[^{}]*\}\s*\z/u', $text );
		$template = preg_replace_callback(
			'~(<!--\s+wp:[a-z0-9/_-]+\s+(\{.*?\})\s+/?-->)|(<!--.*?-->)|(<(script|style|code|pre|textarea|svg)\b[^>]*>.*?</\5\s*>)|(</?[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>)|([^<]+)|(<)~su',
			static function ( $m ) use ( $mark, $has_words, &$failed ) {
				if ( '' !== $m[1] ) {
					// The props of a Histeon component may hold inline formats (bold, a link): those texts keep them.
					$component = (bool) preg_match( '~\A<!--\s+wp:xb/component\s~', $m[1] );
					// Text values inside the block's JSON attributes.
					$texts = static function ( $json, $keys ) use ( $mark, $has_words, $component ) {
						return preg_replace_callback(
							'~("(?:' . $keys . ')":\s*")((?:[^"\\\\]|\\\\.)*)(")~u',
							static function ( $j ) use ( $mark, $has_words, $component ) {
								$text = json_decode( '"' . $j[2] . '"' );
								$kind = $component && is_string( $text ) && preg_match( '~<(?:strong|em|b|i|u|s|sub|sup|code|br|a)\b~i', $text ) ? 'json-inline' : 'json';
								return is_string( $text ) && $has_words( $text ) ? $j[1] . $mark( $kind, $text ) . $j[3] : $j[0];
							},
							$json
						);
					};
					// The editor's own labels ("metadata": layer names, bindings) are never text of the site, and an Etch
					// element's HTML attributes give only their texts for people (a button's value, not a field's).
					$etch = (bool) preg_match( '~\A<!--\s+wp:etch/~', $m[1] );
					$inner = '';
					$at = 0;
					foreach ( self::object_ranges( $m[1], $etch ? array( 'metadata', 'attributes' ) : array( 'metadata' ) ) as $range ) {
						$piece = $texts( substr( $m[1], $at, $range[0] - $at ), self::JSON_KEYS );
						$object = substr( $m[1], $range[0], $range[1] - $range[0] );
						if ( 'attributes' === $range[2] ) {
							$keys = preg_match( '~"type":\s*"(?:submit|button|reset)"~i', $object ) ? self::ETCH_ATTRIBUTE_KEYS . '|value' : self::ETCH_ATTRIBUTE_KEYS;
							$object = $texts( $object, $keys );
						}
						if ( null === $piece || null === $object ) {
							$inner = null;
							break;
						}
						$inner .= $piece . $object;
						$at = $range[1];
					}
					if ( null !== $inner ) {
						$rest = $texts( substr( $m[1], $at ), self::JSON_KEYS );
						$inner = null === $rest ? null : $inner . $rest;
					}
					$failed = $failed || null === $inner;
					return null === $inner ? $m[1] : $inner;
				}
				if ( '' !== $m[3] || '' !== $m[4] || isset( $m[8] ) ) {
					return $m[0];
				}
				if ( '' !== $m[6] ) {
					$inner = preg_replace_callback(
						'~(\s(?:alt|title|placeholder|aria-label)=")([^"]*)(")~u',
						static function ( $a ) use ( $mark, $has_words ) {
							$text = html_entity_decode( $a[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
							return $has_words( $text ) ? $a[1] . $mark( 'attr', $text ) . $a[3] : $a[0];
						},
						$m[6]
					);
					$failed = $failed || null === $inner;
					return null === $inner ? $m[6] : $inner;
				}
				$raw = isset( $m[7] ) ? $m[7] : '';
				if ( ! $has_words( $raw ) ) {
					return $raw;
				}
				preg_match( '/^\s*/u', $raw, $lead );
				preg_match( '/\s*$/u', $raw, $trail );
				$text = html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				return $lead[0] . $mark( 'text', $text ) . $trail[0];
			},
			(string) $content
		);
		$failed = $failed || null === $template;
		return array( 'template' => null === $template ? (string) $content : $template, 'segments' => $failed ? array() : $segments, 'ok' => ! $failed );
	}

	/**
	 * Where the JSON objects of the given keys are in a block comment ("metadata": {…}), outermost first, in order.
	 *
	 * @return list<array{0: int, 1: int, 2: string}> Start and end offsets of each object, and its key.
	 */
	private static function object_ranges( $json, $keys ) {
		$ranges = array();
		if ( ! preg_match_all( '~"(' . implode( '|', array_map( 'preg_quote', $keys ) ) . ')":\s*\{~', $json, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return $ranges;
		}
		$length = strlen( $json );
		$end = 0;
		foreach ( $found as $match ) {
			$start = $match[0][1] + strlen( $match[0][0] ) - 1;
			if ( $start < $end ) {
				continue; // inside an object already taken
			}
			$depth = 0;
			$in_string = false;
			for ( $i = $start; $i < $length; $i++ ) {
				$char = $json[ $i ];
				if ( $in_string ) {
					if ( '\\' === $char ) {
						++$i;
					} elseif ( '"' === $char ) {
						$in_string = false;
					}
				} elseif ( '"' === $char ) {
					$in_string = true;
				} elseif ( '{' === $char ) {
					++$depth;
				} elseif ( '}' === $char && 0 === --$depth ) {
					break;
				}
			}
			$end = min( $i + 1, $length );
			$ranges[] = array( $start, $end, $match[1][0] );
		}
		return $ranges;
	}

	/** The content again, from the template and one text per segment, each escaped for where it lands. */
	public static function rebuild( $template, $segments, $texts ) {
		$replace = array();
		// What is right before and after each marker in the template: the space a text node had outside its segment.
		$around = array();
		if ( preg_match_all( '~\x00LB(\d+)\x00~', $template, $marks, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			foreach ( $marks as $found ) {
				$at = $found[0][1];
				$end = $at + strlen( $found[0][0] );
				$around[ (int) $found[1][0] ] = array(
					$at > 0 && ctype_space( $template[ $at - 1 ] ),
					$end < strlen( $template ) && ctype_space( $template[ $end ] ),
				);
			}
		}
		foreach ( $segments as $i => $segment ) {
			$translated = isset( $texts[ $i ] ) && '' !== trim( (string) $texts[ $i ] );
			$edge = $translated ? self::edges( (string) $texts[ $i ], $segment['text'], $around[ $i ] ?? array( false, false ) ) : array( '', '' );
			$text = $translated ? trim( (string) $texts[ $i ] ) : $segment['text'];
			$changed = $edge[0] . $text . $edge[1] !== $segment['text'];
			// A translated text in a block's JSON may be printed raw by a builder: plain text, no angle brackets.
			if ( 'json' === $segment['kind'] && $changed ) {
				$text = str_replace( array( '<', '>' ), '', wp_strip_all_tags( $text ) );
			}
			// A rich prop of a Histeon component, which had inline formats: its translation keeps only those, and
			// Histeon prints them through .inline(), which escapes anything else.
			if ( 'json-inline' === $segment['kind'] && $changed ) {
				$text = wp_kses( $text, self::INLINE_TAGS, array( 'http', 'https', 'mailto', 'tel' ) );
			}
			$text = $edge[0] . $text . $edge[1];
			if ( 'json' === $segment['kind'] || 'json-inline' === $segment['kind'] ) {
				$encoded = (string) wp_json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$encoded = str_replace( array( '--', '<', '>', '&', '\\"' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ), substr( $encoded, 1, -1 ) );
			} elseif ( 'attr' === $segment['kind'] ) {
				$encoded = esc_attr( $text );
			} else {
				// A text node: quotes need no escaping there, and a shortcode's attributes only work with real ones.
				$encoded = str_replace( array( '&quot;', '&#039;' ), array( '"', "'" ), esc_html( $text ) );
			}
			$replace[ sprintf( self::MARK, $i ) ] = $encoded;
		}
		return strtr( $template, $replace );
	}

	/**
	 * The spaces at the edges of a translated text: the translator's own (a translation may need one the original
	 * had not: «dell’<a>» becomes «of <a>»), else the original's; none where the template has one there already.
	 *
	 * @param array{0: bool, 1: bool} $outside Whether the template has a space right before and right after the segment.
	 * @return array{0: string, 1: string} The space before the text and the one after it.
	 */
	private static function edges( $text, $original, $outside ) {
		$space = static function ( $pattern, $translated, $source ) {
			if ( preg_match( $pattern, $translated, $m ) && '' !== $m[0] ) {
				return $m[0];
			}
			return preg_match( $pattern, $source, $m ) ? $m[0] : '';
		};
		$lead = $outside[0] ? '' : $space( '/\A\s*/u', $text, $original );
		$trail = $outside[1] ? '' : $space( '/\s*\z/u', $text, $original );
		return array( $lead, $trail );
	}

	/**
	 * The units of a content: title, excerpt, content segments, fields. Ids are stable for one version of it.
	 *
	 * @return list<array{id: string, resname: string, source: string}>
	 */
	public static function units( \WP_Post $post ) {
		$units = array( array( 'id' => 'title', 'resname' => 'title', 'source' => $post->post_title ) );
		if ( '' !== trim( $post->post_excerpt ) ) {
			$units[] = array( 'id' => 'excerpt', 'resname' => 'excerpt', 'source' => $post->post_excerpt );
		}
		foreach ( self::segment( $post->post_content )['segments'] as $i => $segment ) {
			$units[] = array( 'id' => 'c' . ( $i + 1 ), 'resname' => 'content:' . $segment['kind'], 'source' => $segment['text'] );
		}
		foreach ( Fields::translatable_values( $post->ID ) as $i => $field ) {
			if ( is_array( $field ) && isset( $field['value'] ) && is_string( $field['value'] ) && '' !== trim( $field['value'] ) ) {
				$units[] = array( 'id' => 'f' . ( $i + 1 ), 'resname' => 'field:' . ( $field['provider'] ?? '' ) . ':' . ( $field['key'] ?? '' ), 'source' => $field['value'] );
			}
		}
		return $units;
	}

	/** One XLIFF document with a <file> per content (originals the user can edit). */
	public static function export( $post_ids, $language ) {
		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		$xliff = $doc->createElementNS( self::NS, 'xliff' );
		$xliff->setAttribute( 'version', '1.2' );
		$xliff->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:lb', self::LB_NS );
		$doc->appendChild( $xliff );
		$count = 0;
		// Only to a configured language of translation: no file for the original's own language or a typo.
		$post_ids = isset( Languages::all()[ $language ] ) && Languages::default_code() !== $language ? $post_ids : array();
		foreach ( array_slice( array_map( 'absint', (array) $post_ids ), 0, self::MAX_CONTENTS ) as $id ) {
			$post = get_post( $id );
			$row = $post ? Storage::find( $id ) : null;
			$source_language = $row ? $row['language'] : Languages::default_code();
			if ( ! $post || ! Translations::supports_post( $post ) || ! Translators::may_translate( $id, $language ) || $language === $source_language ) {
				continue;
			}
			$file = $doc->createElementNS( self::NS, 'file' );
			$file->setAttribute( 'original', 'lingua-bridge:post:' . $id );
			$file->setAttribute( 'source-language', $source_language );
			$file->setAttribute( 'target-language', $language );
			$file->setAttribute( 'datatype', 'plaintext' );
			$file->setAttributeNS( self::LB_NS, 'lb:source-hash', Translations::core_hash( $id ) );
			$header = $doc->createElementNS( self::NS, 'header' );
			$note = $doc->createElementNS( self::NS, 'note' );
			/* translators: note for the translator in the XLIFF file. 1: content title, 2: content URL. */
			$note->appendChild( $doc->createTextNode( sprintf( __( '%1$s — %2$s. Traduci solo i testi; i segmenti tra graffe ({…}) sono espressioni e restano identici.', 'lingua-bridge' ), get_the_title( $post ), get_permalink( $post ) ) ) );
			$header->appendChild( $note );
			$terms = self::glossary_note( $source_language, $language );
			if ( '' !== $terms ) {
				$glossary = $doc->createElementNS( self::NS, 'note' );
				$glossary->setAttribute( 'from', 'lingua-bridge-glossary' );
				$glossary->appendChild( $doc->createTextNode( $terms ) );
				$header->appendChild( $glossary );
			}
			$file->appendChild( $header );
			$body = $doc->createElementNS( self::NS, 'body' );
			$existing = self::existing_targets( $post, $language );
			$units = self::units( $post );
			// Sentences translated before, elsewhere (translation memory): targets to review, for what is not done.
			$memory = Memory::lookup( array_column( array_filter( $units, static fn( $u ) => ! isset( $existing[ $u['id'] ] ) ), 'source' ), $language );
			// C25: a sentence alike, for the others: a target to review, with how alike in a note.
			$alike = Memory::similar( array_column( array_filter( $units, static fn( $u ) => ! isset( $existing[ $u['id'] ] ) && ! isset( $memory[ Strings::normalize( $u['source'] ) ] ) ), 'source' ), $language );
			foreach ( $units as $unit ) {
				$tu = $doc->createElementNS( self::NS, 'trans-unit' );
				$tu->setAttribute( 'id', $unit['id'] );
				$tu->setAttribute( 'resname', $unit['resname'] );
				$source = $doc->createElementNS( self::NS, 'source' );
				$source->appendChild( $doc->createTextNode( $unit['source'] ) );
				$tu->appendChild( $source );
				if ( isset( $existing[ $unit['id'] ] ) ) {
					$target = $doc->createElementNS( self::NS, 'target' );
					// Written by the translation memory, not by a person: a suggestion to review.
					$target->setAttribute( 'state', $existing[ $unit['id'] ]['memory'] ? 'needs-review-translation' : 'translated' );
					if ( $existing[ $unit['id'] ]['memory'] ) {
						$target->setAttribute( 'state-qualifier', 'tm-suggestion' );
					}
					$target->appendChild( $doc->createTextNode( $existing[ $unit['id'] ]['text'] ) );
					$tu->appendChild( $target );
				} elseif ( isset( $memory[ Strings::normalize( $unit['source'] ) ] ) ) {
					$target = $doc->createElementNS( self::NS, 'target' );
					$target->setAttribute( 'state', 'needs-review-translation' );
					$target->setAttribute( 'state-qualifier', 'tm-suggestion' );
					$target->appendChild( $doc->createTextNode( $memory[ Strings::normalize( $unit['source'] ) ] ) );
					$tu->appendChild( $target );
				} elseif ( isset( $alike[ Strings::normalize( $unit['source'] ) ] ) ) {
					$match = $alike[ Strings::normalize( $unit['source'] ) ];
					$target = $doc->createElementNS( self::NS, 'target' );
					$target->setAttribute( 'state', 'needs-review-translation' );
					$target->setAttribute( 'state-qualifier', 'fuzzy-match' );
					$target->appendChild( $doc->createTextNode( $match['target'] ) );
					$tu->appendChild( $target );
					$note = $doc->createElementNS( self::NS, 'note' );
					$note->setAttribute( 'from', 'lingua-bridge-memory' );
					/* translators: 1: percent, 2: the remembered sentence. */
					$note->appendChild( $doc->createTextNode( sprintf( __( 'Dalla memoria, simile al %1$d%%: «%2$s»', 'lingua-bridge' ), $match['percent'], $match['source'] ) ) );
					$tu->appendChild( $note );
				}
				$body->appendChild( $tu );
			}
			$file->appendChild( $body );
			$xliff->appendChild( $file );
			++$count;
		}
		return array( 'xml' => $doc->saveXML(), 'count' => $count );
	}

	/** F7: the site's glossary for one pair of languages, for the translator: «Chiostro → Cloister (nota)», one a line. */
	private static function glossary_note( $source_language, $language ) {
		$lines = array();
		foreach ( (array) ( Languages::settings()['translation_glossary'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && ( $entry['source_language'] ?? '' ) === $source_language && ( $entry['target_language'] ?? '' ) === $language ) {
				$lines[] = $entry['source_term'] . ' → ' . $entry['target_term'] . ( '' !== (string) ( $entry['note'] ?? '' ) ? ' (' . $entry['note'] . ')' : '' );
			}
		}
		/* translators: %s: the glossary's terms, one a line. */
		return $lines ? sprintf( __( "Glossario del sito, da rispettare:\n%s", 'lingua-bridge' ), implode( "\n", array_slice( $lines, 0, 300 ) ) ) : '';
	}

	/**
	 * The texts of an existing translation, unit by unit, when it has the same structure as the original.
	 *
	 * @return array<string, array{text: string, memory: bool}> Unit id ⇒ its text, and whether the memory wrote it.
	 */
	private static function existing_targets( \WP_Post $post, $language ) {
		$row = Storage::find( $post->ID );
		$member = $row ? Storage::find_group_language( $row['group_uuid'], $language ) : null;
		$translation = $member ? get_post( absint( $member['object_id'] ) ) : null;
		if ( ! $translation || ! current_user_can( 'edit_post', $translation->ID ) ) {
			return array();
		}
		$source_units = self::units( $post );
		$target_units = self::units( $translation );
		if ( array_column( $source_units, 'id' ) !== array_column( $target_units, 'id' ) ) {
			return array();
		}
		// A text still equal to the original is not translated (a new draft is a copy): a CAT tool must offer it,
		// unless someone chose to keep it as it is in the editor on the page.
		$kept = array_flip( (array) get_post_meta( $translation->ID, Editor::KEPT_META, true ) );
		$filled = array_flip( (array) get_post_meta( $translation->ID, Memory::FILLED_META, true ) );
		$source_texts = array_column( $source_units, 'source', 'id' );
		$out = array();
		foreach ( $target_units as $unit ) {
			$hash = sha1( Strings::normalize( $unit['source'] ) );
			$same = Strings::normalize( $unit['source'] ) === Strings::normalize( (string) ( $source_texts[ $unit['id'] ] ?? '' ) );
			if ( ! $same || isset( $kept[ $hash ] ) ) {
				$out[ $unit['id'] ] = array( 'text' => $unit['source'], 'memory' => isset( $filled[ $hash ] ) );
			}
		}
		return $out;
	}

	/**
	 * Reads an XLIFF file and saves one draft per <file>: the original's structure with the translated texts.
	 *
	 * @return list<array{post: int, language: string, ok: bool, message: string, draft: int, untranslated: int}>|\WP_Error
	 */
	public static function import( $xml ) {
		if ( ! is_string( $xml ) || '' === $xml || strlen( $xml ) > self::MAX_BYTES ) {
			return new \WP_Error( 'lingua_xliff_size', __( 'File vuoto o troppo grande (massimo 10 MB).', 'lingua-bridge' ) );
		}
		// UTF-16 is valid UTF-8 byte by byte when it is ASCII: null bytes and a UTF-16 mark give it away.
		if ( ! mb_check_encoding( $xml, 'UTF-8' ) || false !== strpos( $xml, "\0" ) || 0 === strpos( $xml, "\xFE\xFF" ) || 0 === strpos( $xml, "\xFF\xFE" ) ) {
			return new \WP_Error( 'lingua_xliff_encoding', __( 'Il file deve essere in UTF-8.', 'lingua-bridge' ) );
		}
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return new \WP_Error( 'lingua_xliff_doctype', __( 'Il file contiene una dichiarazione DOCTYPE o di entità: non viene letto.', 'lingua-bridge' ) );
		}
		$previous = libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$loaded = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA | ( defined( 'LIBXML_NO_XXE' ) ? LIBXML_NO_XXE : 0 ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( $loaded && null !== $doc->doctype ) {
			return new \WP_Error( 'lingua_xliff_doctype', __( 'Il file contiene una dichiarazione DOCTYPE o di entità: non viene letto.', 'lingua-bridge' ) );
		}
		if ( ! $loaded || 'xliff' !== $doc->documentElement->localName ) {
			return new \WP_Error( 'lingua_xliff_invalid', __( 'Non è un file XLIFF valido.', 'lingua-bridge' ) );
		}
		$results = array();
		foreach ( $doc->getElementsByTagNameNS( self::NS, 'file' ) as $file ) {
			$results[] = self::import_file( $file );
		}
		return $results;
	}

	private static function import_file( \DOMElement $file ) {
		if ( 0 === strpos( $file->getAttribute( 'original' ), 'lingua-bridge:terms:' ) ) {
			return Terms::import_xliff( $file );
		}
		$result = array( 'post' => 0, 'language' => '', 'ok' => false, 'message' => '', 'draft' => 0, 'untranslated' => 0, 'same' => 0 );
		if ( ! preg_match( '/\Alingua-bridge:post:(\d+)\z/', $file->getAttribute( 'original' ), $m ) ) {
			$result['message'] = __( 'Non viene da Lingua Bridge.', 'lingua-bridge' );
			return $result;
		}
		$id = absint( $m[1] );
		$language = sanitize_key( $file->getAttribute( 'target-language' ) );
		$result['language'] = $language;
		$post = get_post( $id );
		if ( ! $post || ! Translations::supports_post( $post ) || ! Translators::may_translate( $id, $language ) ) {
			$result['message'] = __( 'Contenuto inesistente o non modificabile.', 'lingua-bridge' );
			return $result;
		}
		// Only now the id (and so its title in the results): the user may edit this content.
		$result['post'] = $id;
		if ( ! isset( Languages::all()[ $language ] ) ) {
			$result['message'] = __( 'Lingua di destinazione non configurata.', 'lingua-bridge' );
			return $result;
		}
		if ( $file->getAttributeNS( self::LB_NS, 'source-hash' ) !== Translations::core_hash( $id ) ) {
			$result['message'] = __( 'L’originale è cambiato dopo l’esportazione: esporta di nuovo e riporta le traduzioni nel file nuovo (il programma di traduzione le ritrova nella sua memoria).', 'lingua-bridge' );
			return $result;
		}
		$targets = array();
		foreach ( $file->getElementsByTagNameNS( self::NS, 'trans-unit' ) as $tu ) {
			$target = $tu->getElementsByTagNameNS( self::NS, 'target' )->item( 0 );
			// Not trimmed: a space at the edge of a segment may be the translator's (rebuild() keeps it).
			$text = $target ? wp_check_invalid_utf8( (string) $target->textContent ) : '';
			$targets[ $tu->getAttribute( 'id' ) ] = '' === trim( $text ) ? '' : $text;
		}
		$units = self::units( $post );
		$untranslated = 0;
		$same = 0;
		foreach ( $units as $unit ) {
			if ( '' === ( $targets[ $unit['id'] ] ?? '' ) ) {
				++$untranslated;
			} elseif ( Strings::normalize( $targets[ $unit['id'] ] ) === Strings::normalize( $unit['source'] ) ) {
				// F7: a text the translator left as it was: a name, a number… or one forgotten.
				++$same;
			}
		}
		$result['untranslated'] = $untranslated;
		$result['same'] = $same;
		$text_of = static fn( $id_unit, $fallback ) => '' !== ( $targets[ $id_unit ] ?? '' ) ? wp_strip_all_tags( $targets[ $id_unit ] ) : $fallback;
		$parts = self::segment( $post->post_content );
		if ( ! $parts['ok'] ) {
			$result['message'] = __( 'Il contenuto è troppo lungo o complesso per essere scomposto: nulla è stato salvato. Traducilo nell’editor.', 'lingua-bridge' );
			return $result;
		}
		$texts = array();
		foreach ( $parts['segments'] as $i => $segment ) {
			$raw = $targets[ 'c' . ( $i + 1 ) ] ?? '';
			// wp_strip_all_tags() trims: the spaces at the edges of the text go back around it.
			preg_match( '/\A(\s*).*?(\s*)\z/su', $raw, $edge );
			$texts[ $i ] = '' === $raw ? '' : ( $edge[1] ?? '' ) . wp_strip_all_tags( $raw ) . ( $edge[2] ?? '' );
		}
		$values = array(
			'title'   => $text_of( 'title', $post->post_title ),
			'content' => self::rebuild( $parts['template'], $parts['segments'], $texts ),
		);
		if ( '' !== trim( $post->post_excerpt ) ) {
			$values['excerpt'] = $text_of( 'excerpt', $post->post_excerpt );
		}
		$fields = Fields::translatable_values( $id );
		foreach ( $fields as $i => $field ) {
			if ( is_array( $field ) && isset( $field['value'] ) && is_string( $field['value'] ) && '' !== ( $targets[ 'f' . ( $i + 1 ) ] ?? '' ) ) {
				$fields[ $i ]['value'] = wp_strip_all_tags( $targets[ 'f' . ( $i + 1 ) ] );
			}
		}
		if ( ! empty( $fields ) ) {
			$values['fields'] = array_values(
				array_map(
					static fn( $f ) => array( 'provider' => (string) ( $f['provider'] ?? '' ), 'key' => (string) ( $f['key'] ?? '' ), 'value' => (string) ( $f['value'] ?? '' ) ),
					array_filter( $fields, static fn( $f ) => is_array( $f ) && isset( $f['provider'], $f['key'], $f['value'] ) && is_string( $f['value'] ) )
				)
			);
		}
		$row = Storage::find( $id );
		$member = $row ? Storage::find_group_language( $row['group_uuid'], $language ) : null;
		$existing = $member ? get_post( absint( $member['object_id'] ) ) : null;
		if ( $existing && 'publish' === $existing->post_status ) {
			$saved = Translations::create_or_update_correction_draft( $id, $language, $values );
			$result['message'] = __( 'Bozza di correzione della traduzione pubblicata.', 'lingua-bridge' );
		} else {
			$saved = Translations::create_or_update_draft( $id, $language, $values );
			$result['message'] = __( 'Bozza salvata.', 'lingua-bridge' );
		}
		if ( is_wp_error( $saved ) ) {
			$result['message'] = $saved->get_error_message();
			return $result;
		}
		$result['ok'] = true;
		$result['draft'] = absint( $saved );
		return $result;
	}

	/**
	 * F7: what a download takes, for one language: up to 300 contents to do (listWork, 100 at a time), in files of
	 * PER_FILE contents.
	 *
	 * @return list<list<int>> The source ids of each file.
	 */
	public static function work_chunks( $language, $state, $type ) {
		$ids = array();
		foreach ( array( 0, 100, 200 ) as $offset ) {
			$work = Connector::run( 'listWork', array_filter( array( 'language' => $language, 'state' => $state, 'type' => $type, 'limit' => 100, 'offset' => $offset ) ) );
			$items = is_wp_error( $work ) ? array() : (array) ( $work['items'] ?? array() );
			$ids = array_merge( $ids, array_map( 'absint', array_column( $items, 'source_id' ) ) );
			if ( count( $items ) < 100 ) {
				break;
			}
		}
		return array_chunk( array_values( array_unique( $ids ) ), self::PER_FILE );
	}

	public static function handle_export() {
		check_admin_referer( 'lingua_bridge_xliff_export' );
		if ( ! Connector::can_connect() ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		$state = isset( $_POST['state'] ) && 'all' === $_POST['state'] ? 'missing,stale,draft,published' : 'missing,stale';
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$back = admin_url( 'admin.php?page=lingua-bridge-xliff' );
		// F7: «all» is every language of translation, in a ZIP with one file per language (and per 100 contents).
		$languages = 'all' === $language ? array_keys( array_diff_key( Languages::all(), array( Languages::default_code() => true ) ) ) : array( $language );
		$files = array();
		$stamp = gmdate( 'Ymd-His' );
		foreach ( $languages as $code ) {
			$chunks = self::work_chunks( $code, $state, $type );
			foreach ( $chunks as $n => $ids ) {
				$export = self::export( $ids, $code );
				if ( ! empty( $export['count'] ) ) {
					$files[ 'lingua-bridge-' . $code . '-' . $stamp . ( count( $chunks ) > 1 ? '-' . ( $n + 1 ) : '' ) . '.xlf' ] = $export['xml'];
				}
			}
		}
		if ( ! $files ) {
			wp_safe_redirect( add_query_arg( 'lb_xliff', 'empty', $back ) );
			exit;
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		if ( 1 === count( $files ) ) {
			header( 'Content-Type: application/x-xliff+xml; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . key( $files ) . '"' );
			echo reset( $files ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an XML file built with DOMDocument.
			exit;
		}
		$zip_path = class_exists( '\\ZipArchive' ) ? wp_tempnam( 'lingua-bridge-xliff' ) : '';
		$zip = $zip_path ? new \ZipArchive() : null;
		if ( ! $zip || true !== $zip->open( $zip_path, \ZipArchive::OVERWRITE ) ) {
			wp_safe_redirect( add_query_arg( 'lb_xliff', 'nozip', $back ) );
			exit;
		}
		foreach ( $files as $name => $xml ) {
			$zip->addFromString( $name, $xml );
		}
		$zip->close();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="lingua-bridge-xliff-' . $stamp . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- the ZIP just written.
		wp_delete_file( $zip_path );
		exit;
	}

	public static function handle_import() {
		check_admin_referer( 'lingua_bridge_xliff_import' );
		if ( ! Connector::can_connect() ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		$file = isset( $_FILES['xliff'] ) && is_array( $_FILES['xliff'] ) ? $_FILES['xliff'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- read as data below.
		$xml = $file && UPLOAD_ERR_OK === (int) $file['error'] && is_uploaded_file( $file['tmp_name'] ) && (int) $file['size'] <= self::MAX_BYTES ? (string) file_get_contents( $file['tmp_name'] ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded file.
		$results = self::import( $xml );
		$key = wp_generate_password( 12, false );
		set_transient( 'lingua_bridge_xliff_' . get_current_user_id() . '_' . $key, is_wp_error( $results ) ? $results->get_error_message() : $results, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'page' => 'lingua-bridge-xliff', 'lb_result' => $key ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! Connector::can_connect() ) {
			wp_die( esc_html__( 'Non hai i permessi per visualizzare questa schermata.', 'lingua-bridge' ) );
		}
		echo '<div class="wrap lb-wrap lb-xliff">';
		Admin::page_header( 'lingua-bridge-xliff', __( 'XLIFF per traduttori e agenzie', 'lingua-bridge' ) );
		echo '<p class="lb-ai__lead">' . esc_html__( 'Esporta i contenuti da tradurre in un file XLIFF, il formato dei programmi di traduzione (Trados, memoQ, Phrase, Smartcat, OmegaT…). Ogni testo è un segmento: titolo, estratto, ogni testo dei blocchi, i campi. Al ritorno, ogni contenuto diventa una bozza con la struttura dell’originale e i testi tradotti.', 'lingua-bridge' ) . '</p>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- which result to show (a per-user transient key).
		if ( isset( $_GET['lb_xliff'] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html( 'nozip' === $_GET['lb_xliff'] ? __( 'Il file ZIP non si può creare su questo server (manca ZipArchive): esporta una lingua alla volta.', 'lingua-bridge' ) : __( 'Nessun contenuto da esportare con queste scelte.', 'lingua-bridge' ) ) . '</p></div>';
		}
		$key = isset( $_GET['lb_result'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['lb_result'] ) ) : '';
		// phpcs:enable
		if ( '' !== $key ) {
			$transient = 'lingua_bridge_xliff_' . get_current_user_id() . '_' . $key;
			$results = get_transient( $transient );
			delete_transient( $transient );
			if ( is_string( $results ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $results ) . '</p></div>';
			} elseif ( is_array( $results ) ) {
				echo '<h2>' . esc_html__( 'Importazione', 'lingua-bridge' ) . '</h2><table class="widefat striped lb-xliff__results"><thead><tr><th>' . esc_html__( 'Contenuto', 'lingua-bridge' ) . '</th><th>' . esc_html__( 'Lingua', 'lingua-bridge' ) . '</th><th>' . esc_html__( 'Esito', 'lingua-bridge' ) . '</th></tr></thead><tbody>';
				foreach ( $results as $r ) {
					$link = $r['draft'] ? ' <a href="' . esc_url( (string) get_edit_post_link( $r['draft'] ) ) . '">' . esc_html__( 'Apri la bozza', 'lingua-bridge' ) . '</a> · <a href="' . esc_url( add_query_arg( Editor::QUERY_VAR, $r['draft'], home_url( '/' ) ) ) . '">' . esc_html__( 'Rivedi sulla pagina', 'lingua-bridge' ) . '</a>' : '';
					/* translators: %d: number of untranslated texts. */
					$missing = $r['untranslated'] ? ' ' . sprintf( __( '(%d testi senza traduzione: restano nella lingua originale)', 'lingua-bridge' ), $r['untranslated'] ) : '';
					/* translators: %d: number of texts equal to the original. */
					$missing .= ! empty( $r['same'] ) ? ' ' . sprintf( __( '(%d testi uguali all’originale: controlla che vadano lasciati così)', 'lingua-bridge' ), $r['same'] ) : '';
					echo '<tr class="' . ( $r['ok'] ? 'is-ok' : 'is-error' ) . '"><td>' . esc_html( $r['post'] ? get_the_title( $r['post'] ) . ' (#' . $r['post'] . ')' : '—' ) . '</td><td>' . esc_html( $r['language'] ) . '</td><td>' . esc_html( $r['message'] . $missing ) . $link . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link built with esc_*.
				}
				echo '</tbody></table>';
			}
		}
		$targets = array_diff_key( Languages::all(), array( Languages::default_code() => true ) );
		echo '<div class="lb-xliff__cols"><form method="post" class="lb-setup__form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lingua_bridge_xliff_export">';
		wp_nonce_field( 'lingua_bridge_xliff_export' );
		echo '<h2>' . esc_html__( 'Esporta', 'lingua-bridge' ) . '</h2><p><label>' . esc_html__( 'Lingua', 'lingua-bridge' ) . ' <select name="language">';
		if ( count( $targets ) > 1 ) {
			echo '<option value="all">' . esc_html__( 'Tutte le lingue (un file ZIP)', 'lingua-bridge' ) . '</option>';
		}
		foreach ( $targets as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p><p><label>' . esc_html__( 'Tipo', 'lingua-bridge' ) . ' <select name="type"><option value="">' . esc_html__( 'Tutti', 'lingua-bridge' ) . '</option>';
		foreach ( Admin::editable_post_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( $object ) {
				echo '<option value="' . esc_attr( $type ) . '">' . esc_html( $object->labels->name ) . '</option>';
			}
		}
		echo '</select></label></p><fieldset><legend class="screen-reader-text">' . esc_html__( 'Quali contenuti', 'lingua-bridge' ) . '</legend><label><input type="radio" name="state" value="todo" checked> ' . esc_html__( 'Da tradurre o da aggiornare', 'lingua-bridge' ) . '</label><br><label><input type="radio" name="state" value="all"> ' . esc_html__( 'Tutti (anche quelli già tradotti, per una revisione)', 'lingua-bridge' ) . '</label></fieldset>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Scarica il file XLIFF', 'lingua-bridge' ) . '</button></p><p class="description">' . esc_html__( 'Fino a 100 contenuti per file, i più recenti, e fino a 300 per lingua: con più file, o più lingue, si scarica un file ZIP. Il glossario del sito va nel file come nota per il traduttore.', 'lingua-bridge' ) . '</p></form>';
		echo '<form method="post" enctype="multipart/form-data" class="lb-setup__form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lingua_bridge_xliff_import">';
		wp_nonce_field( 'lingua_bridge_xliff_import' );
		echo '<h2>' . esc_html__( 'Importa', 'lingua-bridge' ) . '</h2><p><label for="lb-xliff-file">' . esc_html__( 'File XLIFF tradotto', 'lingua-bridge' ) . '</label><br><input id="lb-xliff-file" type="file" name="xliff" accept=".xlf,.xliff,.xml" required></p><p><button class="button button-primary">' . esc_html__( 'Importa', 'lingua-bridge' ) . '</button></p><p class="description">' . esc_html__( 'Ogni contenuto diventa una bozza (o una bozza di correzione, se la traduzione è già pubblicata). Nulla viene pubblicato.', 'lingua-bridge' ) . '</p></form></div></div>';
	}
}
