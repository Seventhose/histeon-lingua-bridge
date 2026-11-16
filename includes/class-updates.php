<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When the original changes (docs/MEMORY.md): which of its sentences are new or changed since the translation
 * was last brought up to date, by comparing the picture taken then (Translations::snapshot) with the original now.
 * - A draft that follows the original is rebuilt on the new structure: unchanged sentences keep their
 *   translation, the others take the memory or stay in the original's words (marked in the editor).
 * - A published translation never shows untranslated sentences: the editor lists the changes and leads to them;
 *   «Segna come aggiornata» when done.
 */
final class Updates {
	/**
	 * @return array{known: bool, aligned: bool, changes: list<array{original: string, before: string, translation: string}>, title: array{before: string, original: string}|null}
	 */
	public static function changes( $translation_id ) {
		$row = Storage::find( $translation_id );
		$post = get_post( $translation_id );
		$source = $row ? get_post( absint( $row['source_id'] ) ) : null;
		$out = array( 'known' => false, 'aligned' => false, 'changes' => array(), 'title' => null );
		if ( ! $row || ! $post || ! $source || ! Translations::is_stale( $row ) ) {
			return $out;
		}
		$snapshot = Translations::snapshot_of( $translation_id );
		if ( null === $snapshot ) {
			return $out;
		}
		$out['known'] = true;
		$old = $snapshot['segments'];
		$now = Xliff::segment( $source->post_content );
		$mine = Xliff::segment( $post->post_content );
		$out['aligned'] = $now['ok'] && $mine['ok'] && array_column( $old, 'kind' ) === array_column( $mine['segments'], 'kind' );
		$map = self::match( array_column( $old, 'text' ), array_column( $now['segments'], 'text' ) );
		foreach ( $now['segments'] as $j => $segment ) {
			if ( isset( $map[ $j ] ) ) {
				continue;
			}
			// The old sentence at this place, if any: an edit rather than an addition.
			$before = '';
			$translation = '';
			$i = self::neighbour( $map, $j );
			if ( null !== $i && isset( $old[ $i ] ) && ! in_array( $i, $map, true ) ) {
				$before = $old[ $i ]['text'];
				$translation = $out['aligned'] ? $mine['segments'][ $i ]['text'] : '';
			}
			$out['changes'][] = array( 'original' => $segment['text'], 'before' => $before, 'translation' => $translation );
		}
		if ( Strings::normalize( $snapshot['title'] ?? '' ) !== Strings::normalize( $source->post_title ) ) {
			$out['title'] = array( 'before' => (string) ( $snapshot['title'] ?? '' ), 'original' => $source->post_title );
		}
		return $out;
	}

	/**
	 * Index of the new sequence => index of the old one, for the sentences that did not change (longest common
	 * subsequence on normalized texts).
	 *
	 * @return array<int, int>
	 */
	public static function match( $old, $new ) {
		$a = array_map( array( Strings::class, 'normalize' ), array_values( $old ) );
		$b = array_map( array( Strings::class, 'normalize' ), array_values( $new ) );
		$map = array();
		// The common start and end first: an update usually touches a few sentences in the middle.
		$start = 0;
		while ( $start < count( $a ) && $start < count( $b ) && $a[ $start ] === $b[ $start ] ) {
			$map[ $start ] = $start;
			++$start;
		}
		$end_a = count( $a ) - 1;
		$end_b = count( $b ) - 1;
		while ( $end_a >= $start && $end_b >= $start && $a[ $end_a ] === $b[ $end_b ] ) {
			$map[ $end_b ] = $end_a;
			--$end_a;
			--$end_b;
		}
		$mid_a = array_slice( $a, $start, $end_a - $start + 1 );
		$mid_b = array_slice( $b, $start, $end_b - $start + 1 );
		$n = count( $mid_a );
		$m = count( $mid_b );
		if ( 0 === $n || 0 === $m || $n > 400 || $m > 400 ) {
			ksort( $map );
			return $map; // Beyond 400 sentences changed at once, the middle counts as new.
		}
		$len = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$len[ $i ][ $j ] = $mid_a[ $i ] === $mid_b[ $j ] ? $len[ $i + 1 ][ $j + 1 ] + 1 : max( $len[ $i + 1 ][ $j ], $len[ $i ][ $j + 1 ] );
			}
		}
		for ( $i = 0, $j = 0; $i < $n && $j < $m; ) {
			if ( $mid_a[ $i ] === $mid_b[ $j ] ) {
				$map[ $start + $j ] = $start + $i;
				++$i;
				++$j;
			} elseif ( $len[ $i + 1 ][ $j ] >= $len[ $i ][ $j + 1 ] ) {
				++$i;
			} else {
				++$j;
			}
		}
		ksort( $map );
		return $map;
	}

	/** The old sentence a new one replaces: in the gap between unchanged neighbours, paired in order. */
	private static function neighbour( $map, $j ) {
		$prev_new = null;
		$next_new = null;
		foreach ( $map as $new => $old ) {
			if ( $new < $j ) {
				$prev_new = $new;
			} elseif ( $new > $j && null === $next_new ) {
				$next_new = $new;
			}
		}
		$from = null === $prev_new ? 0 : $map[ $prev_new ] + 1;
		$to = null === $next_new ? PHP_INT_MAX : $map[ $next_new ] - 1;
		$new_from = null === $prev_new ? 0 : $prev_new + 1;
		$new_to = null === $next_new ? PHP_INT_MAX : $next_new - 1;
		// In the same gap, old and new sentences pair up in order: edits; the new ones left over are additions.
		$candidate = $from + ( $j - $new_from );
		return $candidate <= $to && $candidate >= $from ? $candidate : null;
	}

	/**
	 * A draft that follows its original, rebuilt on the original's new structure: unchanged sentences keep their
	 * translation, the others take the memory or the original's words. Returns the counts, or a WP_Error.
	 *
	 * @return array{kept: int, memory: int, todo: int}|\WP_Error
	 */
	public static function rebuild_draft( $translation_id ) {
		$row = Storage::find( $translation_id );
		$post = get_post( $translation_id );
		$source = $row ? get_post( absint( $row['source_id'] ) ) : null;
		// The new sentences come from the original: whoever cannot open it cannot read them this way either.
		if ( ! $row || ! $post || ! $source || ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'edit_post', $source->ID ) ) {
			return new \WP_Error( 'lingua_update_forbidden', __( 'Non puoi aggiornare questa traduzione.', 'lingua-bridge' ) );
		}
		if ( 'draft' !== $post->post_status || Translations::is_independent( $post->ID ) ) {
			return new \WP_Error( 'lingua_update_not_draft', __( 'Si ricostruisce solo una bozza che segue l’originale.', 'lingua-bridge' ) );
		}
		$snapshot = Translations::snapshot_of( $post->ID );
		$now = Xliff::segment( $source->post_content );
		$mine = Xliff::segment( $post->post_content );
		if ( null === $snapshot || ! $now['ok'] || ! $mine['ok'] || array_column( $snapshot['segments'], 'kind' ) !== array_column( $mine['segments'], 'kind' ) ) {
			return new \WP_Error( 'lingua_update_unaligned', __( 'La struttura della traduzione non corrisponde più a quella dell’originale: aggiornala nell’editor.', 'lingua-bridge' ) );
		}
		$map = self::match( array_column( $snapshot['segments'], 'text' ), array_column( $now['segments'], 'text' ) );
		$known = Memory::lookup( array_column( $now['segments'], 'text' ), $row['language'] );
		$texts = array();
		$filled = array();
		$counts = array( 'kept' => 0, 'memory' => 0, 'todo' => 0 );
		foreach ( $now['segments'] as $j => $segment ) {
			if ( isset( $map[ $j ] ) ) {
				$texts[ $j ] = $mine['segments'][ $map[ $j ] ]['text'];
				++$counts['kept'];
			} elseif ( isset( $known[ Strings::normalize( $segment['text'] ) ] ) ) {
				$texts[ $j ] = $known[ Strings::normalize( $segment['text'] ) ];
				$filled[] = sha1( Strings::normalize( $texts[ $j ] ) );
				++$counts['memory'];
			} else {
				$texts[ $j ] = $segment['text'];
				++$counts['todo'];
			}
		}
		$data = array( 'ID' => $post->ID, 'post_content' => Xliff::rebuild( $now['template'], $now['segments'], $texts ) );
		if ( is_wp_error( Translations::check_active_markup( array( 'content' => $source->post_content ), array( 'content' => $data['post_content'] ) ) ) ) {
			return new \WP_Error( 'lingua_update_markup', __( 'L’aggiornamento cambierebbe il codice della pagina: aggiornala nell’editor.', 'lingua-bridge' ) );
		}
		if ( Strings::normalize( $snapshot['title'] ?? '' ) !== Strings::normalize( $source->post_title ) ) {
			$title = Memory::lookup( array( $source->post_title ), $row['language'] );
			$data['post_title'] = $title[ Strings::normalize( $source->post_title ) ] ?? $source->post_title;
		}
		$saved = wp_update_post( wp_slash( $data ), true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		Translations::snapshot( $post->ID );
		Memory::mark_filled( $post->ID, $filled );
		Storage::update_review_state( $post->ID, 'needs_review' );
		return $counts;
	}

	/** The translator says the translation follows the current original. */
	public static function mark_updated( $translation_id ) {
		if ( ! current_user_can( 'edit_post', $translation_id ) || ! Storage::find( $translation_id ) ) {
			return new \WP_Error( 'lingua_update_forbidden', __( 'Non puoi aggiornare questa traduzione.', 'lingua-bridge' ) );
		}
		Translations::snapshot( $translation_id );
		return true;
	}
}
