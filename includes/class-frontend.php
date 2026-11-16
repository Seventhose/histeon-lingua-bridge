<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Frontend {
	private static $component_variant_cache = array();
	private static $current_language_cache = null;
	private static $translated_front_page_cache = null;

	public static function init() {
		add_shortcode( 'lingua_bridge_switcher', array( __CLASS__, 'render_switcher' ) );
		add_action( 'wp_head', array( __CLASS__, 'alternate_links' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'language_hint' ) );
		add_action( 'wp_head', array( __CLASS__, 'translated_home_styles' ), 20 );
		add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_classes' ) );
		add_filter( 'render_block_data', array( __CLASS__, 'translate_etch_component' ), 10, 2 );
	}

	/**
	 * The id of the post the page shows, or 0. On an archive the queried object is a term (or a user, a post
	 * type): its id is not a post's, and must never be looked up as one.
	 */
	public static function queried_post_id() {
		$object = get_queried_object();
		return $object instanceof \WP_Post ? absint( $object->ID ) : 0;
	}

	/** The translated components drawn on this page (id => true): the editor on the page can save into them. */
	private static $rendered_components = array();

	/** @return list<int> */
	public static function rendered_components() {
		return array_map( 'absint', array_keys( self::$rendered_components ) );
	}

	public static function translate_etch_component( $parsed_block, $source_block ) {
		if ( is_admin() || empty( $parsed_block['blockName'] ) || 'etch/component' !== $parsed_block['blockName'] ) {
			return $parsed_block;
		}
		$language = self::current_language();
		if ( Languages::default_code() === $language ) {
			return $parsed_block;
		}
		$attributes = isset( $parsed_block['attrs'] ) && is_array( $parsed_block['attrs'] ) ? $parsed_block['attrs'] : array();
		// Etch writes "ref" (1.6 and later); "componentId" and "component_id" are kept for older markup.
		$key = '';
		foreach ( array( 'ref', 'componentId', 'component_id' ) as $candidate ) {
			if ( isset( $attributes[ $candidate ] ) && is_numeric( $attributes[ $candidate ] ) ) {
				$key = $candidate;
				break;
			}
		}
		$component_id = '' !== $key ? absint( $attributes[ $key ] ) : 0;
		if ( ! $component_id ) {
			return $parsed_block;
		}
		$cache_key = $component_id . ':' . $language;
		if ( ! array_key_exists( $cache_key, self::$component_variant_cache ) ) {
			$component = Storage::find( $component_id );
			$variant_id = 0;
			if ( $component ) {
				$variant = Storage::find_group_language( $component['group_uuid'], $language );
				if ( $variant ) {
					$variant_post = get_post( absint( $variant['object_id'] ) );
					// A component is a wp_block: a member of another type (a page, a variation) never takes its place.
					if ( $variant_post && 'wp_block' === $variant_post->post_type && 'publish' === $variant_post->post_status ) {
						$variant_id = absint( $variant_post->ID );
					}
				}
			}
			self::$component_variant_cache[ $cache_key ] = $variant_id;
		}
		$variant_id = self::$component_variant_cache[ $cache_key ];
		if ( $variant_id && $variant_id !== $component_id ) {
			$parsed_block['attrs'][ $key ] = $variant_id;
			self::$rendered_components[ $variant_id ] = true;
		}
		return $parsed_block;
	}

	public static function body_classes( $classes ) {
		$post_id = self::queried_post_id();
		$row = $post_id ? Storage::find( $post_id ) : null;
		$language = $row ? $row['language'] : self::current_language();
		$language_class = 'lingua-bridge-lang-' . sanitize_html_class( $language );
		if ( ! in_array( $language_class, $classes, true ) ) {
			$classes[] = $language_class;
		}
		// C34: themes style a right-to-left page by the body class «rtl», which WordPress adds only with the language pack.
		if ( Languages::is_rtl( $language ) && ! in_array( 'rtl', $classes, true ) ) {
			$classes[] = 'rtl';
		} elseif ( ! Languages::is_rtl( $language ) && in_array( 'rtl', $classes, true ) ) {
			$classes = array_values( array_diff( $classes, array( 'rtl' ) ) );
		}
		if ( self::is_translated_front_page() && ! in_array( 'lingua-bridge-translated-home', $classes, true ) ) {
			$classes[] = 'lingua-bridge-translated-home';
		}
		return $classes;
	}

	public static function current_language() {
		if ( null !== self::$current_language_cache ) {
			return self::$current_language_cache;
		}
		$post_id = self::queried_post_id();
		$row = $post_id ? Storage::find( $post_id ) : null;
		// Without a single linked post (an archive, a search, a page of lists) the URL prefix says the language.
		$routed = Routing::request_language();
		self::$current_language_cache = $row ? $row['language'] : ( '' !== $routed ? $routed : Languages::default_code() );
		return self::$current_language_cache;
	}

	public static function translated_home_styles() {
		if ( ! self::is_translated_front_page() ) {
			return;
		}
		// Which elements repeat the page's own title on a translated home depends on the theme: a setting.
		$selectors = (array) apply_filters( 'lingua_bridge_translated_home_hide_selectors', Languages::translated_home_hide_selectors() );
		$selectors = array_values( array_filter( $selectors, array( Languages::class, 'is_safe_selector' ) ) );
		if ( empty( $selectors ) ) {
			return;
		}
		$scoped = array();
		foreach ( $selectors as $selector ) {
			$scoped[] = 'body.lingua-bridge-translated-home ' . $selector;
		}
		// Selectors passed is_safe_selector(): no quote, brace, semicolon or angle bracket can reach the output.
		echo '<style id="lingua-bridge-translated-home">' . implode( ', ', $scoped ) . '{display:none!important}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function is_translated_front_page() {
		if ( null !== self::$translated_front_page_cache ) {
			return self::$translated_front_page_cache;
		}
		$front_id = Routing::source_front_page_id();
		$post_id = self::queried_post_id();
		if ( ! $front_id || ! $post_id || $front_id === $post_id ) {
			self::$translated_front_page_cache = false;
			return self::$translated_front_page_cache;
		}
		$current = Storage::find( $post_id );
		if ( ! $current || Languages::default_code() === $current['language'] ) {
			self::$translated_front_page_cache = false;
			return self::$translated_front_page_cache;
		}
		if ( absint( $current['source_id'] ) === $front_id ) {
			self::$translated_front_page_cache = true;
			return self::$translated_front_page_cache;
		}
		$front = Storage::find( $front_id );
		self::$translated_front_page_cache = $front && $front['group_uuid'] === $current['group_uuid'];
		return self::$translated_front_page_cache;
	}

	/**
	 * The switcher of the shortcode and the block (F4). `$atts`: `style` list (default) or dropdown (a <details>,
	 * no script: it works in a static copy too), `label` name (default), code or both, `hide_current`, and
	 * `show_missing` (the languages without this content lead to their home). On a list page, a search or a home it
	 * shows the same list in the other languages (MenuSwitcher::links()).
	 */
	public static function render_switcher( $atts = array() ) {
		return self::render( self::switcher_options( $atts ), '' );
	}

	/** C1: the switcher fixed in a corner of the page (bottom-right, bottom-left, top-right, top-left). */
	public static function render_floating( $atts, $corner ) {
		$atts = self::switcher_options( $atts );
		return in_array( $corner, array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ), true ) ? self::render( array( 'floating' => $corner ) + $atts, $corner ) : '';
	}

	private static function render( $atts, $corner ) {
		$post_id = self::queried_post_id();
		$links = array();
		// A content in one language only: no switcher, unless the other languages lead to their homes.
		if ( $post_id && ! $atts['show_missing'] && ! Storage::find( $post_id ) ) {
			return '';
		}
		// With the plugin off, the always-on router loads four files only: MenuSwitcher is not among them.
		if ( class_exists( __NAMESPACE__ . '\\MenuSwitcher' ) ) {
			$links = MenuSwitcher::links( $atts['show_missing'] );
		} elseif ( $post_id && Storage::find( $post_id ) ) {
			foreach ( self::published_items( Storage::group_rows( Storage::find( $post_id )['group_uuid'] ) ) as $item ) {
				$links[] = array( 'code' => $item['language'], 'label' => Languages::label( $item['language'] ), 'url' => (string) get_permalink( $item['post'] ), 'current' => absint( $item['post']->ID ) === $post_id );
			}
		}
		if ( count( $links ) < 2 ) {
			return '';
		}
		if ( class_exists( __NAMESPACE__ . '\\MenuSwitcher' ) ) {
			MenuSwitcher::seen( '' !== $corner ? 'floating' : 'switcher' );
		}
		if ( '' === $corner && class_exists( __NAMESPACE__ . '\\Switcher' ) ) {
			Switcher::drawn( 'switcher' );
		}
		return self::switcher_markup( $links, $atts );
	}

	/**
	 * The switcher's markup for `$links` (MenuSwitcher::links()) with the options of switcher_options(): a <nav> with
	 * a list, or a <details> dropdown; every link with hreflang, lang and data-lb-choice (the visitor's choice, C2).
	 * With `floating` (a corner, C1) it is fixed in that corner of the page.
	 */
	public static function switcher_markup( $links, $atts ) {
		$current = '';
		$items = '';
		foreach ( $links as $link ) {
			$text = 'code' === $atts['label'] ? strtoupper( $link['code'] ) : ( 'both' === $atts['label'] ? strtoupper( $link['code'] ) . ' · ' . $link['label'] : $link['label'] );
			if ( $link['current'] ) {
				$current = $text;
				if ( $atts['hide_current'] ) {
					continue;
				}
			}
			$items .= '<li><a hreflang="' . esc_attr( $link['code'] ) . '" lang="' . esc_attr( $link['code'] ) . '" href="' . esc_url( $link['url'] ) . '" data-lb-choice="' . esc_attr( $link['code'] ) . '"' . ( $link['current'] ? ' aria-current="page"' : '' ) . '>' . esc_html( $text ) . ( 'code' === $atts['label'] ? '<span class="screen-reader-text"> ' . esc_html( $link['label'] ) . '</span>' : '' ) . '</a></li>';
		}
		$list = '<ul>' . $items . '</ul>';
		if ( 'dropdown' === $atts['style'] ) {
			$list = '<details class="lb-language-switcher__menu"><summary>' . esc_html( '' !== $current ? $current : __( 'Lingua', 'lingua-bridge' ) ) . '</summary>' . $list . '</details>';
		}
		$floating = (string) ( $atts['floating'] ?? '' );
		$classes = 'lb-language-switcher' . ( 'dropdown' === $atts['style'] ? ' lb-language-switcher--dropdown' : '' ) . ( '' !== $floating ? ' lb-language-switcher--floating lb-language-switcher--' . $floating : '' );
		$styles = self::switcher_styles() . ( '' !== $floating && class_exists( __NAMESPACE__ . '\\Switcher' ) ? '<style id="lingua-bridge-floating-switcher">' . Switcher::floating_styles() . '</style>' : '' );
		return $styles . '<nav class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr__( 'Lingua', 'lingua-bridge' ) . '">' . $list . '</nav>';
	}

	/** The switcher's options, from the shortcode (snake case, yes/1/true) or the block (camel case, booleans). */
	public static function switcher_options( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();
		$flag = static function ( $value ) {
			return true === $value || in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'si', 'sì' ), true );
		};
		$style = strtolower( (string) ( $atts['style'] ?? 'list' ) );
		$label = strtolower( (string) ( $atts['label'] ?? 'name' ) );
		return array(
			// C1: render_floating() sets the corner; a shortcode or a block never does.
			'floating'     => '',
			'style'        => 'dropdown' === $style ? 'dropdown' : 'list',
			'label'        => in_array( $label, array( 'code', 'both' ), true ) ? $label : 'name',
			'hide_current' => $flag( $atts['hide_current'] ?? ( $atts['hideCurrent'] ?? false ) ),
			'show_missing' => $flag( $atts['show_missing'] ?? ( $atts['showMissing'] ?? false ) ),
		);
	}

	/**
	 * Minimal styles for the shortcode and block switcher, printed once, with the first switcher of the page:
	 * a compact row, the current language marked without changing its width. `:where()` keeps them at zero
	 * specificity, so any theme rule wins; `lingua_bridge_switcher_styles` (false) turns them off.
	 */
	private static function switcher_styles() {
		static $printed = false;
		if ( $printed || ! apply_filters( 'lingua_bridge_switcher_styles', true ) ) {
			return '';
		}
		$printed = true;
		return '<style id="lingua-bridge-switcher">:where(.lb-language-switcher) ul{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem;margin:0;padding:0;list-style:none}'
			. ':where(.lb-language-switcher) a{display:inline-block;padding:.2em .5em;border-radius:.25rem;color:inherit;line-height:1.2;text-decoration:none}'
			. ':where(.lb-language-switcher) a:hover{text-decoration:underline}'
			. ':where(.lb-language-switcher) a[aria-current="page"]{background:color-mix(in srgb,currentColor 12%,transparent)}'
			. ':where(.lb-language-switcher) a:focus-visible{outline:2px solid currentColor;outline-offset:2px}'
			. ':where(.lb-language-switcher__menu){position:relative;display:inline-block}'
			. ':where(.lb-language-switcher__menu) summary{cursor:pointer;padding:.2em .5em;list-style:none}'
			. ':where(.lb-language-switcher__menu) summary::-webkit-details-marker{display:none}'
			. ':where(.lb-language-switcher__menu) summary::after{content:"";display:inline-block;margin-inline-start:.45em;padding:.17em;border:solid currentColor;border-width:0 .12em .12em 0;transform:translateY(-.15em) rotate(45deg)}'
			. ':where(.lb-language-switcher__menu[open]) summary::after{transform:translateY(.1em) rotate(-135deg)}'
			. ':where(.lb-language-switcher__menu) ul{position:absolute;z-index:10;flex-direction:column;align-items:stretch;min-width:100%;padding:.25rem;background:Canvas;color:CanvasText;box-shadow:0 2px 8px rgb(0 0 0/.2)}</style>';
	}

	/**
	 * N8, C2: unless `visitor_language` is off, a small script that reads the page's own hreflang links (also in a
	 * static copy) and offers the version in the browser's language (suggest), or on the first page of a visit takes
	 * the visitor there (redirect). Always in the browser, never on the server: caches and search engines see one page
	 * per address. For a redirect it runs in the head, before the page is drawn. The names of the languages come with
	 * it; the visitor's choice stays in the browser, without cookies.
	 */
	public static function language_hint() {
		$mode = Languages::visitor_language();
		// With the plugin off the always-on router loads this class, not the plugin's constants nor its assets.
		if ( 'off' === $mode || count( Languages::all() ) < 2 || ! defined( 'LINGUA_BRIDGE_FILE' ) ) {
			return;
		}
		$redirect = 'redirect' === $mode;
		wp_enqueue_script( 'lingua-bridge-language-hint', plugins_url( 'assets/language-hint.js', LINGUA_BRIDGE_FILE ), array(), LINGUA_BRIDGE_VERSION, $redirect ? array( 'in_footer' => false ) : array( 'in_footer' => true, 'strategy' => 'defer' ) );
		$names = array();
		foreach ( Languages::all() as $code => $label ) {
			$names[ $code ] = Languages::label( $code );
		}
		wp_add_inline_script( 'lingua-bridge-language-hint', 'window.lbLanguageHint=' . wp_json_encode( array( 'mode' => $mode, 'names' => $names, 'close' => __( 'Chiudi', 'lingua-bridge' ) ) ) . ';', 'before' );
	}

	public static function alternate_links() {
		$post_id = self::queried_post_id();
		$row = $post_id ? Storage::find( $post_id ) : null;
		if ( ! $row ) {
			self::list_alternate_links();
			return;
		}
		$items = self::published_items( Storage::group_rows( $row['group_uuid'] ) );
		$versions = array();
		foreach ( $items as $item ) {
			$versions[ $item['language'] ] = (string) get_permalink( $item['post'] );
			echo '<link rel="alternate" hreflang="' . esc_attr( $item['language'] ) . '" href="' . esc_url( $versions[ $item['language'] ] ) . '" />' . "\n";
		}
		$default_url = Languages::x_default( $versions );
		if ( $default_url ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $default_url ) . '" />' . "\n";
		}
	}

	/**
	 * C3: the versions of a list page (the blog home, an archive of a type or of a term not translated, an author, a
	 * date): the same list under each language's prefix (MenuSwitcher::links()), for the languages whose list has
	 * published content; x-default to the original's. Not for a search, nor for page 2 and after (their canonical is
	 * the first page), nor with the plugin off (only the router is loaded then).
	 */
	private static function list_alternate_links() {
		if ( ! class_exists( __NAMESPACE__ . '\\MenuSwitcher' ) || ! Routing::enabled() || is_search() || is_paged() || is_404() || is_singular() ) {
			return;
		}
		if ( ! ( is_home() || is_archive() ) || ( class_exists( __NAMESPACE__ . '\\Terms' ) && Terms::versions( get_queried_object() ) ) ) {
			return;
		}
		$default = Languages::default_code();
		$links = array();
		foreach ( MenuSwitcher::links() as $link ) {
			if ( Routing::list_has_content( $link['code'] ) ) {
				$links[ $link['code'] ] = $link['url'];
			}
		}
		if ( count( $links ) < 2 ) {
			return;
		}
		foreach ( $links as $code => $url ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
		if ( '' !== Languages::x_default( $links ) ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( Languages::x_default( $links ) ) . '" />' . "\n";
		}
	}

	public static function language_attributes( $attributes ) {
		$post_id = self::queried_post_id();
		$row = $post_id ? Storage::find( $post_id ) : null;
		if ( ! $row && '' === Routing::request_language() ) {
			// The original's home, lists and pages not linked yet are in the original's language, which can differ
			// from WordPress's own (contents in Italian, site in en_US). WordPress's value stays when it agrees.
			$default = Languages::default_code();
			if ( is_admin() || 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' ) || ( preg_match( '/\blang=("|\')([^"\']*)\1/', $attributes, $site ) && 0 === strpos( strtolower( $site[2] ) . '-', $default . '-' ) ) ) {
				return $attributes;
			}
			$row = array( 'language' => $default );
		}
		$language = esc_attr( $row ? $row['language'] : Routing::request_language() );
		// C34: the direction of the page's language, also without WordPress's language pack.
		$direction = Languages::is_rtl( $language ) ? 'rtl' : 'ltr';
		$attributes = (string) preg_replace( '/\s*\bdir=("|\').*?\1/', '', $attributes );
		$attributes = trim( $attributes . ( 'rtl' === $direction || ( function_exists( 'is_rtl' ) && is_rtl() ) ? ' dir="' . $direction . '"' : '' ) );
		if ( preg_match( '/\blang=("|\').*?\1/', $attributes ) ) {
			return preg_replace( '/\blang=("|\').*?\1/', 'lang="' . $language . '"', $attributes, 1 );
		}
		return trim( $attributes . ' lang="' . $language . '"' );
	}

	/**
	 * The one rule for "a link may point here" (hreflang, switchers, menus): published, not a media file, and a
	 * variation only while its product is published too.
	 */
	public static function online( $post ) {
		$post = $post ? get_post( $post ) : null;
		if ( ! $post || 'publish' !== $post->post_status || 'attachment' === $post->post_type ) {
			return false;
		}
		return 'product_variation' !== $post->post_type || ! $post->post_parent || 'publish' === get_post_status( $post->post_parent );
	}

	private static function published_items( $rows ) {
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$post = get_post( absint( $row['object_id'] ) );
			if ( self::online( $post ) ) {
				$items[] = array(
					'language' => sanitize_text_field( $row['language'] ),
					'post'     => $post,
				);
			}
		}
		return $items;
	}
}
