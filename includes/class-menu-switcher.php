<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The language switcher in the site's menu, with no shortcode or block to place (docs/SWITCHER.md): a setting
 * (Impostazioni → URL e SEO, or the setup) puts it at the end of a classic menu location, or of the theme's
 * navigation block. On a page with translations it leads to its published versions; elsewhere to the language
 * home pages. Links only, escaped; the current language marked with aria-current.
 */
final class MenuSwitcher {
	/** 'nav-block' for the navigation block of a block theme, or a classic menu location slug; '' off. */
	const SETTING = 'switcher_menu';

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		add_filter( 'wp_nav_menu_items', array( __CLASS__, 'classic' ), 20, 2 );
		add_filter( 'render_block_core/navigation', array( __CLASS__, 'navigation_block' ), 20 );
	}

	public static function where() {
		$value = (string) ( Languages::settings()[ self::SETTING ] ?? '' );
		return 'nav-block' === $value || preg_match( '/\A[a-z0-9_-]{1,64}\z/i', $value ) ? $value : '';
	}

	/** Where a switcher was last seen on a page of the site ('' never): the checklist counts it done only then. */
	const SEEN_OPTION = 'lingua_bridge_switcher_seen';

	/**
	 * The choice the setup makes: the navigation block of a block theme that has one in its templates (Etch draws its
	 * menu as a component, with no navigation block), else the first location with a menu, else ''.
	 */
	public static function automatic() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			if ( self::theme_has_navigation_block() ) {
				return 'nav-block';
			}
			return '';
		}
		foreach ( get_nav_menu_locations() as $location => $menu ) {
			if ( $menu ) {
				return (string) $location;
			}
		}
		$locations = array_keys( get_registered_nav_menus() );
		return $locations ? (string) $locations[0] : '';
	}

	/** A template or a template part of the theme with a core navigation block, also inside the patterns they use. */
	private static function theme_has_navigation_block() {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return true;
		}
		$patterns = class_exists( 'WP_Block_Patterns_Registry' ) ? \WP_Block_Patterns_Registry::get_instance() : null;
		foreach ( array( 'wp_template_part', 'wp_template' ) as $type ) {
			foreach ( get_block_templates( array(), $type ) as $template ) {
				$content = (string) $template->content;
				// Twenty Twenty-Five's header part is a pattern: its navigation block is in the pattern's content.
				if ( $patterns && preg_match_all( '~<!-- wp:pattern \{"slug":"([^"]+)"~', $content, $used ) ) {
					foreach ( $used[1] as $slug ) {
						$pattern = $patterns->get_registered( $slug );
						$content .= is_array( $pattern ) ? (string) ( $pattern['content'] ?? '' ) : '';
					}
				}
				if ( false !== strpos( $content, '<!-- wp:navigation' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** A switcher was drawn on this page: remembered once per place (a menu location, the block, the shortcode). */
	public static function seen( $where ) {
		$where = sanitize_key( (string) $where );
		if ( '' !== $where && get_option( self::SEEN_OPTION, '' ) !== $where ) {
			update_option( self::SEEN_OPTION, $where, true );
		}
	}

	/**
	 * After the setup: the home page asked once, as a visitor sees it, to know whether a switcher is on it.
	 *
	 * @return bool|null True when one is there, false when not, null when the site could not be asked.
	 */
	public static function check_home() {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 10,
				'redirection' => 2,
				// The site asking itself: as WordPress's own loopback checks (Site Health) do.
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$html = (string) wp_remote_retrieve_body( $response );
		return false !== strpos( $html, 'lb-menu-switcher' ) || false !== strpos( $html, 'lb-language-switcher' );
	}

	/**
	 * The links: the published versions of the page shown, or the home of each language.
	 *
	 * @return list<array{code: string, label: string, url: string, current: bool}>
	 */
	/**
	 * The versions of the page in every language: of the content, of a translated term, of a list page under the
	 * prefixes (an archive, a search, the blog), else each language's home. With `$show_missing` (F4) a language
	 * without this content leads to its home.
	 */
	public static function links( $show_missing = false ) {
		$current = Frontend::current_language();
		$links = array();
		$post_id = Frontend::queried_post_id();
		$row = $post_id ? Storage::find( $post_id ) : null;
		$list = ! $row && Routing::enabled() && ( is_archive() || is_search() || ( is_home() && ! is_front_page() ) ) && ! Terms::versions( get_queried_object() );
		if ( $row ) {
			foreach ( Storage::group_rows( $row['group_uuid'] ) as $member ) {
				$post = get_post( absint( $member['object_id'] ) );
				if ( Frontend::online( $post ) ) {
					$links[ $member['language'] ] = array( 'code' => (string) $member['language'], 'label' => Languages::label( $member['language'] ), 'url' => (string) get_permalink( $post ), 'current' => absint( $post->ID ) === $post_id );
				}
			}
		} elseif ( Terms::versions( get_queried_object() ) ) {
			// The archive of a translated term: the same term in the other languages.
			foreach ( Terms::page_versions( get_queried_object() ) as $code => $url ) {
				if ( isset( Languages::all()[ $code ] ) ) {
					$links[ $code ] = array( 'code' => (string) $code, 'label' => Languages::label( $code ), 'url' => $url, 'current' => $code === $current );
				}
			}
		} elseif ( $list ) {
			// A list page: the same path under each language's prefix, without its page number. As for hreflang (C3)
			// only the languages whose list has content, and the page's own: never a link to an empty list. A search
			// is asked again in every language.
			$path = self::path_without_prefix();
			foreach ( Languages::all() as $code => $label ) {
				if ( $code !== $current && ! is_search() && ! Routing::list_has_content( $code ) ) {
					continue;
				}
				$url = home_url( $path );
				$url = Languages::default_code() === $code ? $url : Routing::language_url( $url, $code );
				$links[ $code ] = array( 'code' => $code, 'label' => $label, 'url' => is_search() ? add_query_arg( 's', rawurlencode( get_search_query( false ) ), $url ) : $url, 'current' => $code === $current );
			}
		} else {
			foreach ( Languages::all() as $code => $label ) {
				$home = self::language_home( $code );
				if ( $home ) {
					$links[ $code ] = array( 'code' => $code, 'label' => $label, 'url' => $home, 'current' => $code === $current );
				}
			}
		}
		if ( $show_missing ) {
			foreach ( Languages::all() as $code => $label ) {
				$home = isset( $links[ $code ] ) ? '' : self::language_home( $code );
				if ( $home ) {
					$links[ $code ] = array( 'code' => $code, 'label' => $label, 'url' => $home, 'current' => false );
				}
			}
		}
		// Languages in the order of the settings.
		$ordered = array();
		foreach ( array_keys( Languages::all() ) as $code ) {
			if ( isset( $links[ $code ] ) ) {
				$ordered[] = $links[ $code ];
			}
		}
		return count( $ordered ) > 1 ? $ordered : array();
	}

	/** A language's home: its translated front page, or its list of articles when the home is the blog; else ''. */
	public static function language_home( $code ) {
		if ( Languages::default_code() === $code ) {
			return home_url( '/' );
		}
		if ( Routing::translated_home_id( $code ) ) {
			return (string) get_permalink( Routing::translated_home_id( $code ) );
		}
		return Routing::enabled() && 'page' !== get_option( 'show_on_front' ) ? Routing::language_url( home_url( '/' ), $code ) : '';
	}

	/** The path of the request without its language prefix and its page number (/page/2/): '/category/news/'. */
	private static function path_without_prefix() {
		$path = '/' . trim( (string) ( $GLOBALS['wp']->request ?? '' ), '/' ) . '/';
		$language = Routing::request_language();
		$prefix = '' !== $language ? (string) array_search( $language, Routing::language_prefixes(), true ) : '';
		if ( '' !== $prefix && 0 === strpos( $path, '/' . $prefix . '/' ) ) {
			// C17: the language's own base (categorie) back to the original's, which language_url() translates again.
			$path = '/' . Routing::original_base( trim( substr( $path, strlen( $prefix ) + 1 ), '/' ), $language ) . '/';
		}
		return (string) preg_replace( '~/page/[0-9]+/$~', '/', '//' === $path ? '/' : $path );
	}

	private static function items( $item_class, $link_class ) {
		$out = '';
		$links = self::links();
		if ( $links ) {
			self::seen( self::where() );
			Switcher::drawn( self::where() );
		}
		foreach ( $links as $link ) {
			$out .= '<li class="' . esc_attr( $item_class ) . ' lb-menu-switcher"><a class="' . esc_attr( $link_class ) . '" href="' . esc_url( $link['url'] ) . '" hreflang="' . esc_attr( $link['code'] ) . '" lang="' . esc_attr( $link['code'] ) . '" data-lb-choice="' . esc_attr( $link['code'] ) . '"' . ( $link['current'] ? ' aria-current="true"' : '' ) . '>' . esc_html( strtoupper( $link['code'] ) ) . '<span class="screen-reader-text"> ' . esc_html( $link['label'] ) . '</span></a></li>';
		}
		return $out;
	}

	public static function classic( $items, $args ) {
		$where = self::where();
		if ( '' === $where || 'nav-block' === $where || ! is_object( $args ) || ( $args->theme_location ?? '' ) !== $where ) {
			return $items;
		}
		return $items . self::items( 'menu-item', '' );
	}

	/** At the end of the navigation block's list (the first one of the page: the header's). */
	public static function navigation_block( $html ) {
		static $done = false;
		if ( $done || 'nav-block' !== self::where() || ! is_string( $html ) ) {
			return $html;
		}
		$items = self::items( 'wp-block-navigation-item', 'wp-block-navigation-item__content' );
		$position = strrpos( $html, '</ul>' );
		if ( '' === $items || false === $position ) {
			return $html;
		}
		$done = true;
		return substr( $html, 0, $position ) . $items . substr( $html, $position );
	}
}
