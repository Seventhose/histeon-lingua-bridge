<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C23 and C24 of the competition list (1.10.0).
 *
 * - «Traduci in sottofondo» (`auto_translate`: language => post types, off by default): an original published or
 *   saved again puts its translation in that language in the queue of the automatic service, created when missing
 *   and translated when still a draft. Only drafts, always: a published translation waits for a person (it shows as
 *   to update). Only contents dated from `auto_from`, at most `auto_daily` a day; the jobs run as the administrator
 *   who turned it on (`auto_user`), with their permissions.
 * - The estimate before translating: words and characters that would go to the service, without the sentences the
 *   memory already has; the cost when the owner writes the price of their service per million characters.
 */
final class Auto {
	const COUNT_OPTION = 'lingua_bridge_auto_count';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'original_saved' ), 50, 2 );
	}

	/** @return array<string, string[]> language => post types, only languages of translation and translatable types. */
	public static function config() {
		$saved = (array) ( Languages::settings()['auto_translate'] ?? array() );
		$types = Translations::translatable_types();
		$out = array();
		foreach ( $saved as $language => $list ) {
			if ( isset( Languages::all()[ $language ] ) && Languages::default_code() !== $language ) {
				$list = array_values( array_intersect( (array) $list, $types ) );
				if ( $list ) {
					$out[ (string) $language ] = $list;
				}
			}
		}
		return $out;
	}

	/** How many jobs the automatic translation may still queue today. */
	public static function room_today() {
		$daily = max( 1, absint( Languages::settings()['auto_daily'] ?? 30 ) );
		$count = get_option( self::COUNT_OPTION, array() );
		$today = gmdate( 'Ymd' );
		return max( 0, $daily - ( is_array( $count ) && ( $count['day'] ?? '' ) === $today ? absint( $count['n'] ?? 0 ) : 0 ) );
	}

	/** An original published or saved: its missing or draft translations of the chosen languages go to the queue. */
	public static function original_saved( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! MachineTranslation::configured() ) {
			return;
		}
		$config = self::config();
		$user = absint( Languages::settings()['auto_user'] ?? 0 );
		if ( empty( $config ) || ! $user || ! get_userdata( $user ) ) {
			return;
		}
		$row = Storage::find( $post_id );
		if ( $row && Languages::default_code() !== $row['language'] ) {
			return;
		}
		$from = (string) ( Languages::settings()['auto_from'] ?? '' );
		if ( '' !== $from && strtotime( $post->post_date_gmt . ' UTC' ) < strtotime( $from . ' 00:00:00 UTC' ) ) {
			return;
		}
		foreach ( $config as $language => $types ) {
			if ( ! in_array( $post->post_type, $types, true ) || self::room_today() < 1 ) {
				continue;
			}
			$member = $row ? Storage::find_group_language( $row['group_uuid'], $language ) : null;
			if ( $member && 'draft' !== get_post_status( absint( $member['object_id'] ) ) ) {
				continue;
			}
			if ( Queue::add( 'machine', array( absint( $post_id ) ), $language, $user ) ) {
				$count = get_option( self::COUNT_OPTION, array() );
				$today = gmdate( 'Ymd' );
				$n = is_array( $count ) && ( $count['day'] ?? '' ) === $today ? absint( $count['n'] ?? 0 ) : 0;
				update_option( self::COUNT_OPTION, array( 'day' => $today, 'n' => $n + 1 ), false );
			}
		}
	}

	/** The drafts written by the service and not reviewed yet: for the dashboard («12 bozze nuove da rivedere»). */
	public static function drafts_to_review() {
		global $wpdb;
		if ( ! Storage::is_available() ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s INNER JOIN " . Storage::table() . " l ON l.object_id = p.ID WHERE p.post_status = 'draft' AND l.review_state = 'needs_review'", MachineTranslation::MACHINE_META ) );
	}

	/**
	 * C24: what translating `$ids` into `$languages` would send to the service: contents, words and characters,
	 * without the sentences the memory has for each language. With a price per million characters, the cost.
	 *
	 * @return array{contents: int, words: int, characters: int, cost: float|null}
	 */
	public static function estimate( $ids, $languages ) {
		$out = array( 'contents' => 0, 'words' => 0, 'characters' => 0, 'cost' => null );
		foreach ( array_slice( array_map( 'absint', (array) $ids ), 0, 100 ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$texts = array( $post->post_title );
			$parts = Xliff::segment( $post->post_content );
			foreach ( $parts['ok'] ? $parts['segments'] : array() as $segment ) {
				$texts[] = (string) $segment['text'];
			}
			foreach ( Fields::translatable_values( $id ) as $field ) {
				$texts[] = (string) ( $field['value'] ?? '' );
			}
			$texts = array_values( array_filter( array_map( 'trim', $texts ), 'strlen' ) );
			foreach ( (array) $languages as $language ) {
				$known = Memory::lookup( $texts, $language );
				foreach ( $texts as $text ) {
					if ( ! isset( $known[ Strings::normalize( $text ) ] ) ) {
						$out['characters'] += mb_strlen( $text );
						$out['words'] += count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) );
					}
				}
				++$out['contents'];
			}
		}
		$price = (float) ( MachineTranslation::settings()['price'] ?? 0 );
		$out['cost'] = $price > 0 ? round( $out['characters'] / 1000000 * $price, 4 ) : null;
		return $out;
	}
}
