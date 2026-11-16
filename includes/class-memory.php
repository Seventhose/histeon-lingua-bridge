<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translation memory (docs/MEMORY.md): sentence pairs per language, learned from translations that follow their
 * original (same structure, segment by segment). A sentence translated once is offered again: a new translation
 * starts with the sentences already known, the editor suggests them, XLIFF carries them as targets to review.
 * Independent pages teach nothing (their sentences do not correspond to the original's).
 */
final class Memory {
	const BUILT_OPTION = 'lingua_bridge_memory_built';
	const BATCH = 100;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'lingua_bridge_memory';
	}

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'admin_init', array( __CLASS__, 'build_step' ) );
	}

	public static function key( $text ) {
		return sha1( Strings::normalize( $text ) );
	}

	/** Texts the memory put into a translation, still to be reviewed (sha1 of the normalized text). */
	const FILLED_META = '_lingua_bridge_memory_filled';

	/** One pair, when it is a translation (different words) of a text with letters. Plain text only. */
	public static function learn( $source, $target, $language, $origin = 'person' ) {
		global $wpdb;
		$source = Strings::normalize( $source );
		$target = Strings::clean( $target );
		if ( '' === $source || '' === $target || Strings::normalize( $target ) === $source || ! preg_match( '/\p{L}/u', $source ) || mb_strlen( $source ) > 5000 || ! Storage::is_available() ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$origin = in_array( $origin, self::ORIGINS, true ) ? $origin : 'person';
		// A person's pair is never replaced by a machine's (F5): the agent or the service only fill what persons left.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::table() . " (source_hash, language, source, target, origin, updated_at) VALUES (%s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE target = IF(origin = 'person' AND VALUES(origin) <> 'person', target, VALUES(target)), updated_at = IF(origin = 'person' AND VALUES(origin) <> 'person', updated_at, VALUES(updated_at)), origin = IF(origin = 'person' AND VALUES(origin) <> 'person', origin, VALUES(origin))", self::key( $source ), $language, $source, $target, $origin, current_time( 'mysql', true ) ) );
	}

	/** The pairs of a translation that follows its original, segment by segment, title included. */
	/**
	 * Who teaches the memory: someone who can edit the others' contents (filter lingua_bridge_memory_capability).
	 * The memory fills everybody's translations; an author publishing their own page must not decide them.
	 */
	public static function trusted( $user_id ) {
		return $user_id && user_can( $user_id, (string) apply_filters( 'lingua_bridge_memory_capability', 'edit_others_posts' ) );
	}

	public static function learn_post( $translation_id, $teacher = null ) {
		$row = Storage::find( $translation_id );
		$post = get_post( $translation_id );
		$source = $row ? get_post( absint( $row['source_id'] ) ) : null;
		if ( ! $row || ! $post || ! $source || Languages::default_code() === $row['language'] || $source->ID === $post->ID || Translations::is_independent( $post->ID ) ) {
			return 0;
		}
		// Only what is published teaches: someone with the right to publish stands behind it. A draft (by a
		// contributor, by a machine) never reaches the translations of others.
		if ( 'publish' !== $post->post_status || ! self::trusted( null === $teacher ? get_current_user_id() : absint( $teacher ) ) ) {
			return 0;
		}
		$from = Xliff::segment( $source->post_content );
		$to = Xliff::segment( $post->post_content );
		$count = 0;
		// Who wrote these texts: a machine marks its drafts (WRITER_META); a person's saves clear the mark.
		$origin = (string) get_post_meta( $post->ID, self::WRITER_META, true );
		$origin = in_array( $origin, self::ORIGINS, true ) ? $origin : 'person';
		self::learn( $source->post_title, $post->post_title, $row['language'], $origin );
		if ( $from['ok'] && $to['ok'] && array_column( $from['segments'], 'kind' ) === array_column( $to['segments'], 'kind' ) ) {
			foreach ( $to['segments'] as $i => $segment ) {
				self::learn( $from['segments'][ $i ]['text'], $segment['text'], $row['language'], $origin );
				++$count;
			}
		}
		return $count;
	}

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::learn_post( $post_id );
	}

	/** C25: how alike two sentences must be, in percent, for the memory to offer one for the other. */
	const SIMILAR = 85;

	/**
	 * C25: for the texts the memory does not know as they are, the translation of a sentence alike at least
	 * SIMILAR percent (similar_text on the normalized, lowercase sentences), only among sentences of near length
	 * and at most 2000 of them: a suggestion to review, never applied by itself.
	 *
	 * @return array<string, array{target: string, source: string, percent: int}> normalized text => best match
	 */
	public static function similar( $texts, $language ) {
		global $wpdb;
		$wanted = array();
		foreach ( array_slice( (array) $texts, 0, 200 ) as $text ) {
			$normalized = Strings::normalize( $text );
			$length = mb_strlen( $normalized );
			if ( $length >= 12 && $length <= 1000 ) {
				$wanted[ $normalized ] = $length;
			}
		}
		if ( empty( $wanted ) || ! Storage::is_available() ) {
			return array();
		}
		$min = (int) floor( min( $wanted ) * self::SIMILAR / 100 );
		$max = (int) ceil( max( $wanted ) * 100 / self::SIMILAR );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source, target FROM ' . self::table() . ' WHERE language = %s AND CHAR_LENGTH(source) BETWEEN %d AND %d ORDER BY id DESC LIMIT 2000', $language, $min, $max ), ARRAY_A );
		$out = array();
		foreach ( $wanted as $text => $length ) {
			$lower = mb_strtolower( $text );
			$best = null;
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$other = (string) $row['source'];
				$other_length = mb_strlen( $other );
				if ( $other === $text || $other_length < $length * self::SIMILAR / 100 || $other_length > $length * 100 / self::SIMILAR ) {
					continue;
				}
				similar_text( $lower, mb_strtolower( $other ), $percent );
				if ( $percent >= self::SIMILAR && ( null === $best || $percent > $best['percent'] ) ) {
					$best = array( 'target' => (string) $row['target'], 'source' => $other, 'percent' => (int) floor( $percent ) );
				}
			}
			if ( $best ) {
				$out[ $text ] = $best;
			}
		}
		return $out;
	}

	/** The known translations of some texts in a language: normalized source => target. */
	public static function lookup( $texts, $language ) {
		global $wpdb;
		$hashes = array();
		foreach ( (array) $texts as $text ) {
			$normalized = Strings::normalize( $text );
			if ( '' !== $normalized ) {
				$hashes[ self::key( $normalized ) ] = $normalized;
			}
		}
		if ( empty( $hashes ) || ! Storage::is_available() ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( array_keys( $hashes ), 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and placeholders only.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT source_hash, target FROM ' . self::table() . " WHERE language = %s AND source_hash IN ($placeholders)", array_merge( array( $language ), $chunk ) ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$out[ $hashes[ $row['source_hash'] ] ] = Strings::clean( $row['target'] );
			}
		}
		return $out;
	}

	/**
	 * A translation that still has the original's words takes the known sentences (title, segments). Returns how
	 * many it took. Only for translations that follow their original.
	 */
	public static function apply( $translation_id ) {
		$row = Storage::find( $translation_id );
		$post = get_post( $translation_id );
		$source = $row ? get_post( absint( $row['source_id'] ) ) : null;
		// Only a draft: what the memory fills in is to review, never straight online.
		if ( ! $row || ! $post || ! $source || 'draft' !== $post->post_status || Translations::is_independent( $post->ID ) ) {
			return 0;
		}
		$from = Xliff::segment( $source->post_content );
		$to = Xliff::segment( $post->post_content );
		if ( ! $from['ok'] || ! $to['ok'] || array_column( $from['segments'], 'kind' ) !== array_column( $to['segments'], 'kind' ) ) {
			return 0;
		}
		$known = self::lookup( array_merge( array( $source->post_title ), array_column( $from['segments'], 'text' ) ), $row['language'] );
		$texts = array();
		$used = 0;
		foreach ( $to['segments'] as $i => $segment ) {
			$original = Strings::normalize( $from['segments'][ $i ]['text'] );
			// Only where the translation still has the original's words: never over a sentence already translated.
			if ( Strings::normalize( $segment['text'] ) === $original && isset( $known[ $original ] ) ) {
				$texts[ $i ] = $known[ $original ];
				++$used;
			} else {
				$texts[ $i ] = $segment['text'];
			}
		}
		$data = array( 'ID' => $post->ID );
		if ( Strings::normalize( $post->post_title ) === Strings::normalize( $source->post_title ) && isset( $known[ Strings::normalize( $source->post_title ) ] ) ) {
			$data['post_title'] = $known[ Strings::normalize( $source->post_title ) ];
			++$used;
		}
		if ( $used ) {
			$data['post_content'] = Xliff::rebuild( $to['template'], $to['segments'], $texts );
			if ( is_wp_error( Translations::check_active_markup( array( 'content' => $source->post_content ), array( 'content' => $data['post_content'] ) ) ) ) {
				return 0;
			}
			wp_update_post( wp_slash( $data ) );
			$filled = array();
			foreach ( $texts as $i => $text ) {
				if ( $text !== $to['segments'][ $i ]['text'] ) {
					$filled[] = sha1( Strings::normalize( $text ) );
				}
			}
			if ( isset( $data['post_title'] ) ) {
				$filled[] = sha1( Strings::normalize( $data['post_title'] ) );
			}
			self::mark_filled( $post->ID, $filled );
		}
		return $used;
	}

	/** Texts the memory wrote into a translation: to review until someone saves or confirms them. */
	public static function mark_filled( $post_id, $hashes ) {
		$all = array_values( array_unique( array_merge( (array) get_post_meta( $post_id, self::FILLED_META, true ), (array) $hashes ) ) );
		update_post_meta( $post_id, self::FILLED_META, array_slice( $all, -2000 ) );
	}

	public static function reviewed( $post_id, $text ) {
		$hash = sha1( Strings::normalize( $text ) );
		$all = (array) get_post_meta( $post_id, self::FILLED_META, true );
		if ( in_array( $hash, $all, true ) ) {
			update_post_meta( $post_id, self::FILLED_META, array_values( array_diff( $all, array( $hash ) ) ) );
		}
	}

	/** The memory is built once from the existing translations, a batch per admin page. */
	public static function build_step() {
		$done = get_option( self::BUILT_OPTION );
		if ( 'done' === $done || ! current_user_can( 'edit_posts' ) || ! Storage::is_available() ) {
			return;
		}
		global $wpdb;
		$offset = absint( $done );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT object_id FROM ' . Storage::table() . ' WHERE language <> %s ORDER BY object_id LIMIT %d OFFSET %d', Languages::default_code(), self::BATCH, $offset ) );
		foreach ( $ids as $id ) {
			// Built in the background: the translation's author stands behind it, not whoever opened the admin.
			$post = get_post( absint( $id ) );
			if ( $post ) {
				self::learn_post( $post->ID, absint( $post->post_author ) );
			}
		}
		update_option( self::BUILT_OPTION, count( $ids ) < self::BATCH ? 'done' : (string) ( $offset + self::BATCH ), false );
	}

	/** F5: who wrote a pair the memory learned from. A person's pair is never replaced by a machine's. */
	const ORIGINS = array( 'person', 'agent', 'service' );

	/** Post meta: who wrote the texts of a translation last (agent, service), when not a person. */
	const WRITER_META = '_lingua_bridge_written_by';

	/**
	 * The pairs of a language for the «Memoria» screen: search in source and target, 50 a page.
	 *
	 * @return array{items: list<array>, total: int}
	 */
	public static function search( $language, $search = '', $page = 1 ) {
		global $wpdb;
		if ( ! Storage::is_available() ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$table = self::table();
		$where = $wpdb->prepare( 'language = %s', $language );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND ( source LIKE %s OR target LIKE %s )', $like, $like );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared above.
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT id, source, target, origin, updated_at FROM $table WHERE $where ORDER BY updated_at DESC LIMIT 50 OFFSET %d", 50 * max( 0, absint( $page ) - 1 ) ), ARRAY_A );
		// phpcs:enable
		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => $total );
	}

	/** A pair corrected by a person (the «Memoria» screen): its target, and «person» as origin. */
	public static function correct( $id, $target ) {
		global $wpdb;
		$target = Strings::clean( $target );
		if ( '' === $target ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), array( 'target' => $target, 'origin' => 'person', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => absint( $id ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}

	public static function forget( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/** The pairs of a language as TMX 1.4 (source in the original's language), for a CAT tool or another site. */
	public static function export_tmx( $language ) {
		global $wpdb;
		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		$tmx = $doc->createElement( 'tmx' );
		$tmx->setAttribute( 'version', '1.4' );
		$header = $doc->createElement( 'header' );
		foreach ( array( 'creationtool' => 'Lingua Bridge', 'creationtoolversion' => LINGUA_BRIDGE_VERSION, 'segtype' => 'sentence', 'o-tmf' => 'lingua-bridge', 'adminlang' => 'en', 'srclang' => Languages::default_code(), 'datatype' => 'plaintext' ) as $name => $value ) {
			$header->setAttribute( $name, $value );
		}
		$tmx->appendChild( $header );
		$body = $doc->createElement( 'body' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = Storage::is_available() ? $wpdb->get_results( $wpdb->prepare( 'SELECT source, target, origin FROM ' . self::table() . ' WHERE language = %s ORDER BY id', $language ), ARRAY_A ) : array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$tu = $doc->createElement( 'tu' );
			$prop = $doc->createElement( 'prop' );
			$prop->setAttribute( 'type', 'x-origin' );
			$prop->appendChild( $doc->createTextNode( (string) $row['origin'] ) );
			$tu->appendChild( $prop );
			foreach ( array( Languages::default_code() => $row['source'], $language => $row['target'] ) as $lang => $text ) {
				$tuv = $doc->createElement( 'tuv' );
				$tuv->setAttribute( 'xml:lang', $lang );
				$seg = $doc->createElement( 'seg' );
				$seg->appendChild( $doc->createTextNode( (string) $text ) );
				$tuv->appendChild( $seg );
				$tu->appendChild( $tuv );
			}
			$body->appendChild( $tu );
		}
		$tmx->appendChild( $body );
		$doc->appendChild( $tmx );
		return (string) $doc->saveXML();
	}

	/**
	 * Pairs from a TMX file, into a language's memory as written by a person (whoever imports stands behind them):
	 * the same checks as XLIFF (no DOCTYPE, no network, UTF-8, at most 10 MB).
	 *
	 * @return int|\WP_Error How many pairs were learned.
	 */
	public static function import_tmx( $xml, $language ) {
		if ( ! is_string( $xml ) || '' === $xml || strlen( $xml ) > Xliff::MAX_BYTES ) {
			return new \WP_Error( 'lingua_tmx_size', __( 'File vuoto o troppo grande (massimo 10 MB).', 'lingua-bridge' ) );
		}
		if ( ! mb_check_encoding( $xml, 'UTF-8' ) || false !== strpos( $xml, "\0" ) || false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return new \WP_Error( 'lingua_tmx_invalid', __( 'Il file deve essere TMX in UTF-8, senza dichiarazioni DOCTYPE o di entità.', 'lingua-bridge' ) );
		}
		$previous = libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$loaded = $doc->loadXML( $xml, LIBXML_NONET | ( defined( 'LIBXML_NO_XXE' ) ? LIBXML_NO_XXE : 0 ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || null !== $doc->doctype || 'tmx' !== $doc->documentElement->nodeName ) {
			return new \WP_Error( 'lingua_tmx_invalid', __( 'Il file deve essere TMX in UTF-8, senza dichiarazioni DOCTYPE o di entità.', 'lingua-bridge' ) );
		}
		$count = 0;
		$base = static fn( $code ) => strtolower( (string) strtok( str_replace( '_', '-', (string) $code ), '-' ) );
		foreach ( $doc->getElementsByTagName( 'tu' ) as $tu ) {
			$texts = array();
			foreach ( $tu->getElementsByTagName( 'tuv' ) as $tuv ) {
				$lang = $tuv->getAttribute( 'xml:lang' ) ?: $tuv->getAttribute( 'lang' );
				$seg = $tuv->getElementsByTagName( 'seg' )->item( 0 );
				if ( $seg ) {
					$texts[ $base( $lang ) ] = $seg->textContent;
				}
			}
			if ( isset( $texts[ Languages::default_code() ], $texts[ $base( $language ) ] ) ) {
				self::learn( $texts[ Languages::default_code() ], $texts[ $base( $language ) ], $language, 'person' );
				++$count;
			}
		}
		return $count;
	}

	/** How many pairs each language has. */
	public static function counts() {
		global $wpdb;
		if ( ! Storage::is_available() ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT language, COUNT(*) AS total FROM ' . self::table() . ' GROUP BY language', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? array_column( $rows, 'total', 'language' ) : array();
	}

	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( self::BUILT_OPTION, '0', false );
	}
}
