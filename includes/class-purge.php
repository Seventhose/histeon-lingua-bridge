<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C40 of the competition list (1.11.0): the page caches follow the translations.
 *
 * - A content saved: the addresses of its other published versions (the ones that name it in their switcher and
 *   hreflang) are purged too, through the action `lingua_bridge_purge_urls` (addresses, content id) and, only when
 *   those plugins are there, WP Rocket (`rocket_clean_files()`) and LiteSpeed Cache (`litespeed_purge_url`).
 * - A string of the site published: every page may show it, so the action `lingua_bridge_purge_all` and the
 *   «purge all» of the same plugins, once per request.
 */
final class Purge {
	/** At most so many addresses in one purge: a content has a version per language. */
	const MAX_URLS = 50;

	private static $all = false;

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'content_saved' ), 60, 2 );
		add_action( 'lingua_bridge_strings_changed', array( __CLASS__, 'strings_changed' ) );
	}

	/** The addresses of the published versions of `$post_id` other than itself, same site only. */
	public static function urls_for( $post_id ) {
		$row = Storage::find( absint( $post_id ) );
		if ( ! $row ) {
			return array();
		}
		$urls = array();
		foreach ( Storage::group_rows( $row['group_uuid'] ) as $member ) {
			$id = absint( $member['object_id'] );
			if ( $id && $id !== absint( $post_id ) && 'publish' === get_post_status( $id ) && '' === get_post_field( 'post_password', $id ) ) {
				$link = get_permalink( $id );
				if ( is_string( $link ) && '' !== $link ) {
					$urls[] = $link;
				}
			}
		}
		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_URLS );
	}

	/** A content saved: its other versions leave the caches. */
	public static function content_saved( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::purge( self::urls_for( $post_id ), $post_id );
	}

	/** Addresses to purge: the action for any cache, then WP Rocket and LiteSpeed when they are active. */
	public static function purge( $urls, $post_id = 0 ) {
		$urls = array_values( array_filter( (array) $urls, 'is_string' ) );
		if ( empty( $urls ) ) {
			return;
		}
		do_action( 'lingua_bridge_purge_urls', $urls, absint( $post_id ) );
		if ( function_exists( 'rocket_clean_files' ) ) {
			\rocket_clean_files( $urls );
		}
		if ( has_action( 'litespeed_purge_url' ) ) {
			foreach ( $urls as $url ) {
				do_action( 'litespeed_purge_url', $url );
			}
		}
	}

	/** A string published: every page may show it. Once per request. */
	public static function strings_changed() {
		if ( self::$all ) {
			return;
		}
		self::$all = true;
		do_action( 'lingua_bridge_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			\rocket_clean_domain();
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
		}
	}
}
