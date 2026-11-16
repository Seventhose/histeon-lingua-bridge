<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C11 of the competition list (1.8.2): the versions of each content in the sitemaps of the SEO plugins, as
 * `xhtml:link rel="alternate"`, as Archives does for WordPress's own sitemaps (N11). With Yoast, Rank Math or Slim
 * SEO the sitemap of WordPress is off and theirs had no hreflang for Lingua Bridge.
 *
 * - Rank Math and Yoast list every content, translations too: each entry gets the versions of its group. Their
 *   urlset gets the xhtml namespace.
 * - Slim SEO lists the contents its query finds, at the root only the original's language: each original gets the
 *   versions, and each translation follows as an entry of its own with the same links (as its integrations of
 *   Polylang and WPML do). Terms get the versions of their group.
 */
final class Seo {
	const XHTML = 'http://www.w3.org/1999/xhtml';

	/** @var array<string, int> The content of each address of the sitemap being printed. */
	private static $posts = array();

	public static function init() {
		// Rank Math.
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'remember_entry' ), 10, 3 );
		add_filter( 'rank_math/sitemap/url', array( __CLASS__, 'url_output' ), 10, 2 );
		// Yoast SEO.
		add_filter( 'wpseo_sitemap_entry', array( __CLASS__, 'remember_entry' ), 10, 3 );
		add_filter( 'wpseo_sitemap_url', array( __CLASS__, 'url_output' ), 10, 2 );
		add_filter( 'wpseo_sitemap_urlset', array( __CLASS__, 'urlset' ) );
		// Slim SEO.
		add_action( 'slim_seo_sitemap_post', array( __CLASS__, 'slim_post' ) );
		add_action( 'slim_seo_sitemap_term', array( __CLASS__, 'slim_term' ) );
		add_action( 'init', array( __CLASS__, 'rank_math_urlsets' ), 20 );
	}

	/** Rank Math filters the urlset of each type by name (`rank_math/sitemap/post_urlset`…). */
	public static function rank_math_urlsets() {
		if ( ! class_exists( '\RankMath\Helper' ) && ! defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}
		foreach ( array_merge( get_post_types( array( 'public' => true ) ), get_taxonomies( array( 'public' => true ) ) ) as $type ) {
			add_filter( 'rank_math/sitemap/' . $type . '_urlset', array( __CLASS__, 'urlset' ) );
		}
	}

	/** The urlset of a sitemap with the xhtml namespace, once. */
	public static function urlset( $urlset ) {
		return is_string( $urlset ) && false === strpos( $urlset, 'xmlns:xhtml' ) ? (string) preg_replace( '/<urlset\b/', '<urlset xmlns:xhtml="' . self::XHTML . '"', $urlset, 1 ) : $urlset;
	}

	/** An entry of Rank Math's or Yoast's sitemap: which content (or term) its address is. */
	public static function remember_entry( $url, $type = '', $object = null ) {
		if ( is_array( $url ) && isset( $url['loc'] ) && Storage::is_available() ) {
			if ( $object instanceof \WP_Post ) {
				self::$posts[ (string) $url['loc'] ] = absint( $object->ID );
			} elseif ( $object instanceof \WP_Term ) {
				self::$posts[ (string) $url['loc'] ] = -absint( $object->term_id );
			}
		}
		return $url;
	}

	/** The `<url>` of an entry, with its versions before `</url>`. */
	public static function url_output( $output, $url ) {
		$loc = is_array( $url ) && isset( $url['loc'] ) ? (string) $url['loc'] : '';
		$id = self::$posts[ $loc ] ?? 0;
		$links = $id > 0 ? self::post_links( $id ) : ( $id < 0 ? self::term_links( -$id ) : '' );
		if ( '' === $links || ! is_string( $output ) || false === strpos( $output, '</url>' ) ) {
			return $output;
		}
		$position = strrpos( $output, '</url>' );
		return substr( $output, 0, $position ) . $links . substr( $output, $position );
	}

	/** Slim SEO: an original with its versions, then each published translation as an entry of its own. */
	public static function slim_post( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$versions = self::post_versions( $post->ID );
		if ( count( $versions ) < 2 ) {
			return;
		}
		$links = self::links( $versions );
		echo $links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_attr() and esc_url().
		$own = (string) get_permalink( $post );
		foreach ( $versions as $code => $url ) {
			if ( 'x-default' === $code || $url === $own ) {
				continue;
			}
			// The open <url> is closed by Slim SEO after this action: each translation closes the previous one.
			echo "\t</url>\n\t<url>\n\t\t<loc>" . esc_url( $url ) . "</loc>\n" . $links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/** Slim SEO: a term with the versions of its group. */
	public static function slim_term( $term ) {
		if ( $term instanceof \WP_Term ) {
			echo self::term_links( $term->term_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * The published versions of a content, language => address, with x-default for the original's; only when there
	 * are at least two. The order is the settings'.
	 *
	 * @return array<string, string>
	 */
	public static function post_versions( $post_id ) {
		$row = Storage::is_available() ? Storage::find( absint( $post_id ) ) : null;
		$found = array();
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$post = get_post( absint( $member['object_id'] ) );
			if ( Frontend::online( $post ) && '' === (string) $post->post_password && isset( Languages::all()[ $member['language'] ] ) ) {
				$found[ (string) $member['language'] ] = (string) get_permalink( $post );
			}
		}
		$versions = array();
		foreach ( array_keys( Languages::all() ) as $code ) {
			if ( isset( $found[ $code ] ) ) {
				$versions[ $code ] = $found[ $code ];
			}
		}
		if ( count( $versions ) < 2 ) {
			return array();
		}
		if ( '' !== Languages::x_default( $versions ) ) {
			$versions['x-default'] = Languages::x_default( $versions );
		}
		return $versions;
	}

	private static function post_links( $post_id ) {
		return self::links( self::post_versions( $post_id ) );
	}

	private static function term_links( $term_id ) {
		$term = get_term( absint( $term_id ) );
		$versions = $term instanceof \WP_Term && class_exists( __NAMESPACE__ . '\\Terms' ) ? Terms::versions( $term ) : array();
		if ( '' !== Languages::x_default( $versions ) ) {
			$versions['x-default'] = Languages::x_default( $versions );
		}
		return count( $versions ) > 1 ? self::links( $versions ) : '';
	}

	/** @param array<string, string> $versions */
	private static function links( $versions ) {
		$out = '';
		foreach ( $versions as $code => $url ) {
			$out .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $code ) . '" href="' . esc_url( $url ) . "\"/>\n";
		}
		return $out;
	}
}
