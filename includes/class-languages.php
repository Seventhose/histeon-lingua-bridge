<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Languages {
	const OPTION = 'lingua_bridge_settings';

	public static function defaults() {
		return array(
			'default_language' => 'it',
			'prefix_language_urls' => false,
			'languages'        => array(
				'it' => 'Italiano',
				'en' => 'English',
			),
			'translatable_meta_keys' => array(),
			'translation_context'   => '',
			'translation_instructions' => '',
			'translation_glossary'  => array(),
			'translated_home_hide_selectors' => array(),
			'keep_routing'          => false,
			'locales'               => array(),
			'switcher_menu'         => '',
			'rewrite_links'         => true,
			'suggest_language'      => false,
			'language_domains'      => array(),
			// C1: the floating switcher, on where no other switcher is drawn.
			'floating_switcher'     => 'auto',
			'floating_position'     => 'bottom-right',
			'floating_style'        => 'dropdown',
			'floating_label'        => 'name',
			// C2: off, suggest (the bar of N8) or redirect, on the first page of a visit.
			'visitor_language'      => 'suggest',
			// C12: where x-default points: '' the original, a language's code, or 'none'.
			'x_default'             => '',
			// C23: language => post types translated in the background; from this date; at most this many a day; as whom.
			'auto_translate'        => array(),
			'auto_from'             => '',
			'auto_daily'            => 30,
			// C32: language => (place of the theme => menu): the menu of a language where the theme has one.
			'language_menus'        => array(),
			// C21: language => currency of WooPayments' multi-currency the language starts with.
			'language_currencies'   => array(),
			// C26: the emails to whoever a translation is assigned to: each, daily or off.
			'notify'                => 'each',
			'auto_user'             => 0,
			// C28: language => formal|informal (empty: as the service chooses).
			'formality'             => array(),
			// C17: language => (base => translated base).
			'language_bases'        => array(),
			// C13: meta key => translate|copy|copy_once|ignore; C15: the data kept the same as the original's.
			'field_modes'           => array(),
			'sync'                  => array(),
		);
	}

	/** What 1.1 hid on a translated home page, for one site's theme: kept for sites that saved settings before 1.2. */
	const LEGACY_HOME_SELECTORS = array( '.cu-interior-hero', '.wp-block-post-title', '.entry-title', '.page-title', '.cu-interior-hero__title', '.cu-interior-hero__breadcrumbs', '.breadcrumbs', '.breadcrumb', '.breadcrumb-trail', '.rank-math-breadcrumb', '.yoast-breadcrumb', '.wpseo-breadcrumb', '.aioseo-breadcrumbs' );

	/** A CSS selector made of plain parts only: nothing that could close the rule or the style element. */
	public static function is_safe_selector( $selector ) {
		return is_string( $selector ) && strlen( $selector ) <= 200 && (bool) preg_match( '/\A[A-Za-z0-9_\-.#\s>+~:()\[\]=*]+\z/', $selector );
	}

	/** Selectors hidden on a translated home page (its own title and breadcrumbs), from the settings. */
	public static function translated_home_hide_selectors() {
		$saved = get_option( self::OPTION, array() );
		$settings = self::settings();
		if ( is_array( $saved ) && ! empty( $saved ) && ! array_key_exists( 'translated_home_hide_selectors', $saved ) ) {
			return self::LEGACY_HOME_SELECTORS;
		}
		return array_values( array_filter( (array) $settings['translated_home_hide_selectors'], array( __CLASS__, 'is_safe_selector' ) ) );
	}

	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function all() {
		$settings = self::settings();
		$languages = isset( $settings['languages'] ) && is_array( $settings['languages'] ) ? $settings['languages'] : array();
		if ( empty( $languages ) ) {
			$languages = self::defaults()['languages'];
		}
		// The original first, also for settings saved before 1.5.14 (the setup added it last).
		$default = (string) ( $settings['default_language'] ?? '' );
		return isset( $languages[ $default ] ) ? array( $default => $languages[ $default ] ) + $languages : $languages;
	}

	public static function default_code() {
		$settings = self::settings();
		$code = isset( $settings['default_language'] ) ? sanitize_key( $settings['default_language'] ) : 'it';
		return isset( self::all()[ $code ] ) ? $code : array_key_first( self::all() );
	}

	public static function prefix_urls_enabled() {
		$settings = self::settings();
		return ! empty( $settings['prefix_language_urls'] );
	}

	public static function label( $code ) {
		$languages = self::all();
		return isset( $languages[ $code ] ) ? $languages[ $code ] : strtoupper( $code );
	}

	public static function parse_language_lines( $raw ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		$languages = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 !== count( $parts ) || ! self::is_language_tag( $parts[0] ) || '' === $parts[1] ) {
				continue;
			}
			$languages[ strtolower( $parts[0] ) ] = sanitize_text_field( $parts[1] );
		}
		return $languages;
	}

	/** Sections of the settings screen, each saved on its own (the others keep their values). */
	const SECTIONS = array( 'languages', 'urls', 'switcher', 'ai', 'advanced' );

	/** C28: the register of a language, `formal`, `informal` or '' (as the service chooses). */
	public static function formality( $code ) {
		$value = (string) ( ( self::settings()['formality'] ?? array() )[ $code ] ?? '' );
		return in_array( $value, array( 'formal', 'informal' ), true ) ? $value : '';
	}

	/** The sentence that tells a model the register of `$code`, '' when none is chosen. */
	public static function formality_instruction( $code ) {
		$formality = self::formality( $code );
		return '' === $formality ? '' : sprintf( 'Registro in %s: %s.', self::label( $code ), 'formal' === $formality ? 'formale (dai del Lei, del vous, del Sie…)' : 'informale (dai del tu)' );
	}

	/** C34: the languages written from right to left (their main code, before any «-»). */
	const RTL = array( 'ar', 'he', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb', 'syr', 'arc' );

	/** Whether `$code` is written from right to left: `ar`, `ar-eg`, `fa-af`… */
	public static function is_rtl( $code ) {
		return in_array( strtolower( (string) strtok( (string) $code, '-' ) ), (array) apply_filters( 'lingua_bridge_rtl_languages', self::RTL ), true );
	}

	/**
	 * C12: the address of x-default among `$versions` (language => address): the original's of serie, the chosen
	 * language's when it has a version (else the original's), none with «Nessuno». Filter `lingua_bridge_x_default`.
	 */
	public static function x_default( $versions ) {
		$versions = is_array( $versions ) ? $versions : array();
		$choice = (string) ( self::settings()['x_default'] ?? '' );
		$url = '';
		if ( 'none' !== $choice ) {
			$url = (string) ( $versions[ $choice ] ?? ( $versions[ self::default_code() ] ?? '' ) );
		}
		return (string) apply_filters( 'lingua_bridge_x_default', $url, $versions );
	}

	/** C2: what happens to a visitor whose browser reads another language: off, suggest or redirect. */
	public static function visitor_language() {
		$value = (string) ( self::settings()['visitor_language'] ?? 'suggest' );
		return in_array( $value, array( 'off', 'suggest', 'redirect' ), true ) ? $value : 'suggest';
	}

	/** Code => name of the languages offered (includes/language-catalog.php). */
	/** Frequent short words of some languages: enough to tell which one a site's contents are written in. */
	const STOPWORDS = array(
		'it' => array( 'il', 'lo', 'la', 'gli', 'le', 'di', 'che', 'è', 'e', 'per', 'una', 'un', 'del', 'della', 'dei', 'delle', 'con', 'non', 'sono', 'nel', 'alla', 'sul', 'sull', 'anche', 'come', 'più', 'chi' ),
		'en' => array( 'the', 'and', 'of', 'to', 'is', 'in', 'that', 'for', 'with', 'are', 'this', 'it', 'on', 'be', 'as', 'you', 'not', 'or', 'from', 'by' ),
		'fr' => array( 'le', 'la', 'les', 'des', 'et', 'est', 'une', 'dans', 'pour', 'que', 'du', 'sur', 'pas', 'au', 'avec', 'ce', 'qui', 'nous', 'vous', 'sont' ),
		'de' => array( 'der', 'die', 'das', 'und', 'ist', 'nicht', 'mit', 'ein', 'eine', 'für', 'auf', 'den', 'dem', 'zu', 'sich', 'von', 'auch', 'es', 'wir', 'sie' ),
		'es' => array( 'el', 'los', 'las', 'del', 'que', 'y', 'para', 'una', 'por', 'con', 'es', 'se', 'no', 'en', 'lo', 'al', 'como', 'más', 'su', 'son' ),
		'pt' => array( 'o', 'os', 'as', 'que', 'não', 'uma', 'para', 'com', 'do', 'da', 'em', 'um', 'por', 'mais', 'dos', 'das', 'é', 'se', 'ao', 'na' ),
		'nl' => array( 'de', 'het', 'een', 'en', 'van', 'niet', 'is', 'dat', 'op', 'voor', 'met', 'zijn', 'ook', 'aan', 'als', 'bij', 'wij', 'je', 'er', 'maar' ),
	);

	/**
	 * The language a few texts are written in, from their frequent words (as simple detectors do), or '' when the
	 * texts say too little (fewer than 12 known words, fewer than 4 different ones, or no clear winner).
	 */
	public static function guess_language( $texts ) {
		$words = preg_split( '/[^\p{L}]+/u', mb_strtolower( wp_strip_all_tags( implode( ' ', array_map( 'strval', (array) $texts ) ) ) ), -1, PREG_SPLIT_NO_EMPTY );
		$counts = array_count_values( is_array( $words ) ? $words : array() );
		$scores = array();
		foreach ( self::STOPWORDS as $code => $list ) {
			$found = array_intersect_key( $counts, array_flip( $list ) );
			// One word repeated («da», a name) says nothing: at least four different frequent words of the language.
			$scores[ $code ] = count( $found ) >= 4 ? array_sum( $found ) : 0;
		}
		arsort( $scores );
		$best = (string) key( $scores );
		$first = (int) reset( $scores );
		$second = (int) next( $scores );
		return $first >= 12 && $first >= 1.5 * $second ? $best : '';
	}

	/** The language of the site's latest contents (titles and texts of the last 20), or '' when unclear. */
	public static function guess_content_language() {
		// Originals only (Admin::source_query_clauses()): a translation already made says nothing of the original.
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 20, 'orderby' => 'modified', 'lb_source_only' => true, 'suppress_filters' => false ) );
		$texts = array();
		foreach ( $posts as $post ) {
			$texts[] = $post->post_title . ' ' . mb_substr( wp_strip_all_tags( (string) $post->post_content ), 0, 1500 );
		}
		return self::guess_language( $texts );
	}

	public static function catalog() {
		static $catalog = null;
		if ( null === $catalog ) {
			$catalog = require LINGUA_BRIDGE_DIR . 'includes/language-catalog.php';
		}
		return $catalog;
	}

	/**
	 * Saves the settings sent by a form. `$section` is one of SECTIONS: only its fields change, the rest stays
	 * as saved. 'all' (the default) reads every field, as the single form of 1.1 did.
	 */
	public static function save_from_request( $request, $section = 'all' ) {
		$request = is_array( $request ) ? $request : array();
		$all = 'all' === $section;
		if ( ! $all && ! in_array( $section, self::SECTIONS, true ) ) {
			return new \WP_Error( 'lingua_unknown_section', __( 'Sezione delle impostazioni sconosciuta.', 'lingua-bridge' ) );
		}
		$settings = self::settings();
		$field = static function ( $name ) use ( $request ) {
			return isset( $request[ $name ] ) ? wp_unslash( $request[ $name ] ) : null;
		};

		if ( $all || 'languages' === $section ) {
			$languages = self::languages_from_request( $request );
			if ( empty( $languages ) ) {
				return new \WP_Error( 'lingua_no_languages', __( 'Indica almeno una lingua.', 'lingua-bridge' ) );
			}
			$default = sanitize_key( (string) $field( 'default_language' ) );
			if ( ! isset( $languages[ $default ] ) ) {
				return new \WP_Error( 'lingua_invalid_default', __( 'La lingua predefinita deve comparire nell’elenco.', 'lingua-bridge' ) );
			}
			$used_languages = Storage::used_languages();
			if ( ! empty( $used_languages ) && $default !== self::default_code() ) {
				return new \WP_Error( 'lingua_default_in_use', __( 'La lingua predefinita non può essere cambiata dopo aver creato la prima relazione di traduzione.', 'lingua-bridge' ) );
			}
			if ( array_diff( $used_languages, array_keys( $languages ) ) ) {
				return new \WP_Error( 'lingua_language_in_use', __( 'Non puoi rimuovere una lingua già assegnata a contenuti tradotti.', 'lingua-bridge' ) );
			}
			// The original always first (the switchers and the tables follow this order), the others as chosen.
			$settings['languages'] = array( $default => $languages[ $default ] ) + $languages;
			$settings['default_language'] = $default;
			// The table sends each language's WordPress locale; other forms (lines, the setup) keep the saved ones.
			$locales = isset( $request['lb_languages'] ) && is_array( $request['lb_languages'] ) ? array() : array_intersect_key( (array) ( $settings['locales'] ?? array() ), $languages );
			foreach ( isset( $request['lb_languages'] ) && is_array( $request['lb_languages'] ) ? wp_unslash( $request['lb_languages'] ) : array() as $code => $row ) {
				$locale = is_array( $row ) && isset( $row['locale'] ) ? trim( (string) $row['locale'] ) : '';
				if ( isset( $languages[ strtolower( (string) $code ) ] ) && '' !== $locale ) {
					if ( ! Locale::is_locale( $locale ) ) {
						return new \WP_Error( 'lingua_invalid_locale', __( 'Una lingua di WordPress non è valida: usa la forma it_IT, en_US, de_DE_formal.', 'lingua-bridge' ) );
					}
					$locales[ strtolower( (string) $code ) ] = $locale;
				}
			}
			$settings['locales'] = $locales;
		}

		if ( $all || 'urls' === $section ) {
			$settings['prefix_language_urls'] = '1' === (string) $field( 'prefix_language_urls' );
			$settings['keep_routing'] = '1' === (string) $field( 'keep_routing' );
			// On unless turned off: the setup's step and older forms without the field keep the links rewritten.
			$settings['rewrite_links'] = null === $field( 'rewrite_links_shown' ) || '1' === (string) $field( 'rewrite_links' );
			if ( null !== $field( 'x_default' ) ) {
				$choice = sanitize_key( (string) $field( 'x_default' ) );
				$settings['x_default'] = 'none' === $choice || isset( $settings['languages'][ $choice ] ) ? $choice : '';
			}
			// N10: «fr example.fr» a line; only languages of translation, real host names, none twice, not the site's.
			if ( null !== $field( 'language_domains' ) ) {
				$domains = array();
				$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $field( 'language_domains' ) ) as $line ) {
					$parts = preg_split( '/[\s|]+/', strtolower( trim( $line ) ) );
					$code = (string) ( $parts[0] ?? '' );
					$host = (string) preg_replace( '~^https?://|/.*$~', '', (string) ( $parts[1] ?? '' ) );
					if ( isset( $settings['languages'][ $code ] ) && $settings['default_language'] !== $code && preg_match( '/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?\z/', $host ) && $home_host !== (string) strtok( $host, ':' ) && ! isset( $domains[ $code ] ) && ! in_array( $host, $domains, true ) ) {
						$domains[ $code ] = $host;
					}
				}
				$settings['language_domains'] = $domains;
			}
			// C17: «fr category categorie» a line; only languages of translation, plain segments, none reserved.
			if ( null !== $field( 'language_bases' ) ) {
				$bases = array();
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $field( 'language_bases' ) ) as $line ) {
					$parts = preg_split( '/[\s|]+/', strtolower( trim( $line ) ) );
					$code = (string) ( $parts[0] ?? '' );
					$base = (string) ( $parts[1] ?? '' );
					$translated = (string) ( $parts[2] ?? '' );
					if ( isset( $settings['languages'][ $code ] ) && $settings['default_language'] !== $code && preg_match( '/\A[a-z0-9-]{1,64}\z/', $base ) && preg_match( '/\A[a-z0-9-]{1,64}\z/', $translated ) && $base !== $translated && ! in_array( $translated, array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'feed', 'page', 'search' ), true ) && ! in_array( $translated, $bases[ $code ] ?? array(), true ) ) {
						$bases[ $code ][ $base ] = $translated;
					}
				}
				$settings['language_bases'] = $bases;
			}
			// The setup's step 2 (C1, C2): where the switcher goes, and the visitor's language.
			$place = (string) $field( 'switcher_place' );
			if ( 'floating' === $place || 'manual' === $place ) {
				$settings['floating_switcher'] = 'floating' === $place ? 'auto' : 'off';
				$settings['switcher_menu'] = '';
			} elseif ( 'header' === $place ) {
				$settings['floating_switcher'] = 'auto';
				$settings['switcher_menu'] = MenuSwitcher::automatic();
			}
			if ( in_array( (string) $field( 'visitor_language' ), array( 'off', 'suggest', 'redirect' ), true ) ) {
				$settings['visitor_language'] = (string) $field( 'visitor_language' );
			}
			$selectors = array();
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $field( 'translated_home_hide_selectors' ) ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				if ( ! self::is_safe_selector( $line ) ) {
					return new \WP_Error( 'lingua_invalid_selector', __( 'Un selettore da nascondere sulla homepage tradotta contiene caratteri non ammessi (virgolette, graffe, punto e virgola…).', 'lingua-bridge' ) );
				}
				$selectors[] = $line;
			}
			$settings['translated_home_hide_selectors'] = $selectors;
			// Where the language switcher goes in the menu: chosen, or found by the setup.
			if ( '1' === (string) $field( 'switcher_auto' ) ) {
				$settings['switcher_menu'] = MenuSwitcher::automatic();
			} elseif ( null !== $field( 'switcher_menu' ) ) {
				$where = sanitize_key( (string) $field( 'switcher_menu' ) );
				$allowed = array_merge( array( '', 'nav-block' ), array_map( 'strval', array_keys( get_registered_nav_menus() ) ) );
				$settings['switcher_menu'] = in_array( $where, $allowed, true ) ? $where : '';
			}
		}

		// C1, C4: the «Selettore» tab.
		if ( $all || 'switcher' === $section ) {
			// C32: the menu of each language in each place of the theme (the table «Menu delle lingue»).
			if ( isset( $request['lb_language_menus'] ) && is_array( $request['lb_language_menus'] ) ) {
				$menus = array();
				foreach ( wp_unslash( $request['lb_language_menus'] ) as $code => $places ) {
					if ( ! isset( $settings['languages'][ $code ] ) || $settings['default_language'] === $code ) {
						continue;
					}
					foreach ( (array) $places as $place => $menu ) {
						if ( absint( $menu ) && is_nav_menu( absint( $menu ) ) ) {
							$menus[ (string) $code ][ sanitize_key( (string) $place ) ] = absint( $menu );
						}
					}
				}
				$settings['language_menus'] = $menus;
			}
			if ( null !== $field( 'floating_shown' ) ) {
				$settings['floating_switcher'] = 'auto' === (string) $field( 'floating_switcher' ) ? 'auto' : 'off';
			}
			foreach ( array(
				'floating_position' => array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' ),
				'floating_style'    => array( 'dropdown', 'list' ),
				'floating_label'    => array( 'name', 'code', 'both' ),
				'visitor_language'  => array( 'off', 'suggest', 'redirect' ),
			) as $key => $allowed ) {
				$value = (string) $field( $key );
				if ( in_array( $value, $allowed, true ) ) {
					$settings[ $key ] = $value;
				}
			}
			if ( null !== $field( 'switcher_menu' ) ) {
				$where = sanitize_key( (string) $field( 'switcher_menu' ) );
				$allowed = array_merge( array( '', 'nav-block' ), array_map( 'strval', array_keys( get_registered_nav_menus() ) ) );
				$settings['switcher_menu'] = in_array( $where, $allowed, true ) ? $where : '';
			}
		}

		if ( $all || 'ai' === $section ) {
			// Only what the form sends: the setup's third step has the context alone, and keeps the glossary.
			if ( null !== $field( 'translation_context' ) ) {
				$settings['translation_context'] = sanitize_textarea_field( (string) $field( 'translation_context' ) );
			}
			if ( null !== $field( 'translation_instructions' ) ) {
				$settings['translation_instructions'] = sanitize_textarea_field( (string) $field( 'translation_instructions' ) );
			}
			// C23: the background translation, from its table (with the hidden field lb_auto_shown).
			if ( null !== $field( 'lb_auto_shown' ) ) {
				$auto = array();
				foreach ( isset( $request['lb_auto'] ) && is_array( $request['lb_auto'] ) ? wp_unslash( $request['lb_auto'] ) : array() as $code => $types ) {
					if ( isset( $settings['languages'][ $code ] ) && $settings['default_language'] !== $code ) {
						$auto[ (string) $code ] = array_values( array_map( 'sanitize_key', (array) $types ) );
					}
				}
				$settings['auto_translate'] = $auto;
				$from = (string) $field( 'auto_from' );
				$settings['auto_from'] = preg_match( '/\A\d{4}-\d{2}-\d{2}\z/', $from ) ? $from : '';
				$settings['auto_daily'] = max( 1, min( 500, absint( $field( 'auto_daily' ) ?: 30 ) ) );
				$settings['auto_user'] = $auto ? get_current_user_id() : 0;
			}
			// C28: the register of each language.
			if ( isset( $request['lb_formality'] ) && is_array( $request['lb_formality'] ) ) {
				$formality = array();
				foreach ( wp_unslash( $request['lb_formality'] ) as $code => $value ) {
					if ( isset( $settings['languages'][ $code ] ) && in_array( $value, array( 'formal', 'informal' ), true ) ) {
						$formality[ (string) $code ] = (string) $value;
					}
				}
				$settings['formality'] = $formality;
			}
			$glossary = $field( 'translation_glossary' );
			if ( null !== $glossary ) {
				$settings['translation_glossary'] = is_array( $glossary ) ? self::glossary_from_rows( $glossary ) : self::parse_glossary_lines( (string) $glossary );
			}
		}

		if ( $all || 'advanced' === $section ) {
			// C21: the table «Valuta di ogni lingua», only shown with WooPayments' multi-currency.
			if ( isset( $request['lb_language_currencies'] ) && is_array( $request['lb_language_currencies'] ) ) {
				$currencies = array();
				foreach ( wp_unslash( $request['lb_language_currencies'] ) as $code => $currency ) {
					$currency = strtoupper( sanitize_key( (string) $currency ) );
					if ( isset( $settings['languages'][ $code ] ) && preg_match( '/\A[A-Z]{3}\z/', $currency ) ) {
						$currencies[ (string) $code ] = $currency;
					}
				}
				$settings['language_currencies'] = $currencies;
			}
			if ( null !== $field( 'notify' ) ) {
				$notify = sanitize_key( (string) $field( 'notify' ) );
				$settings['notify'] = in_array( $notify, array( 'each', 'daily', 'off' ), true ) ? $notify : 'each';
			}
			$meta_keys = preg_split( '/\r\n|\r|\n/', (string) $field( 'translatable_meta_keys' ) );
			$settings['translatable_meta_keys'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'trim', $meta_keys ) ) ) ) );
			// C13: the mode of each field of the table; «di serie» removes it. C15: the data kept in sync.
			if ( isset( $request['lb_field_modes'] ) && is_array( $request['lb_field_modes'] ) ) {
				$modes = array();
				foreach ( wp_unslash( $request['lb_field_modes'] ) as $key => $mode ) {
					$key = sanitize_key( (string) $key );
					if ( '' !== $key && in_array( $mode, Sync::MODES, true ) ) {
						$modes[ $key ] = (string) $mode;
					}
				}
				$settings['field_modes'] = $modes;
			}
			if ( null !== $field( 'lb_sync_shown' ) ) {
				$sync = isset( $request['lb_sync'] ) && is_array( $request['lb_sync'] ) ? array_map( 'strval', wp_unslash( $request['lb_sync'] ) ) : array();
				$settings['sync'] = array_values( array_intersect( Sync::DATA, $sync ) );
			}
		}

		// Checks on the result, whatever section changed it.
		if ( ! empty( $settings['prefix_language_urls'] ) ) {
			if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
				return new \WP_Error( 'lingua_pretty_permalinks_required', __( 'I prefissi lingua richiedono permalink WordPress leggibili. Configurali prima in Impostazioni > Permalink.', 'lingua-bridge' ) );
			}
			$reserved_prefixes = array( 'api', 'author', 'category', 'comments', 'feed', 'page', 'search', 'tag', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login', 'wp-sitemap' );
			foreach ( array_keys( $settings['languages'] ) as $language_code ) {
				if ( $settings['default_language'] !== $language_code && in_array( strtolower( preg_replace( '/[^a-z0-9-]+/', '-', $language_code ) ), $reserved_prefixes, true ) ) {
					return new \WP_Error( 'lingua_reserved_language_prefix', __( 'Uno dei codici lingua usa un percorso riservato da WordPress. Scegli un altro codice prima di attivare i prefissi URL.', 'lingua-bridge' ) );
				}
			}
		}

		$keys = array_keys( self::defaults() );
		// Read on every page: autoloaded, so it costs no query of its own.
		update_option( self::OPTION, array_intersect_key( $settings, array_flip( $keys ) ), true );
		return true;
	}

	/**
	 * The languages a form sends: the table of the settings (lb_languages[code][label|remove], plus one to add
	 * from the catalog or by hand), or the `code|name` lines of 1.1.
	 *
	 * @return array<string, string>
	 */
	private static function languages_from_request( $request ) {
		if ( isset( $request['languages'] ) && is_string( $request['languages'] ) ) {
			return self::parse_language_lines( wp_unslash( $request['languages'] ) );
		}
		$languages = array();
		$rows = isset( $request['lb_languages'] ) && is_array( $request['lb_languages'] ) ? wp_unslash( $request['lb_languages'] ) : array();
		// The order of the table (the arrows write it): kept as given when there is none.
		$position = 0;
		$order = array();
		foreach ( $rows as $code => $row ) {
			$order[ $code ] = is_array( $row ) && isset( $row['order'] ) && is_numeric( $row['order'] ) ? (int) $row['order'] : 1000 + $position;
			++$position;
		}
		uksort( $rows, static fn( $a, $b ) => $order[ $a ] <=> $order[ $b ] );
		foreach ( $rows as $code => $row ) {
			$code = strtolower( (string) $code );
			if ( ! is_array( $row ) || ! self::is_language_tag( $code ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$languages[ $code ] = '' !== $label ? $label : ( self::catalog()[ $code ] ?? strtoupper( $code ) );
		}
		foreach ( isset( $request['lb_add_languages'] ) && is_array( $request['lb_add_languages'] ) ? wp_unslash( $request['lb_add_languages'] ) : array( isset( $request['lb_add_language'] ) ? wp_unslash( $request['lb_add_language'] ) : '' ) as $add ) {
			$add = strtolower( trim( (string) $add ) );
			if ( isset( self::catalog()[ $add ] ) && ! isset( $languages[ $add ] ) ) {
				$languages[ $add ] = self::catalog()[ $add ];
			}
		}
		$custom_code = strtolower( trim( (string) ( isset( $request['lb_custom_code'] ) ? wp_unslash( $request['lb_custom_code'] ) : '' ) ) );
		$custom_label = sanitize_text_field( (string) ( isset( $request['lb_custom_label'] ) ? wp_unslash( $request['lb_custom_label'] ) : '' ) );
		if ( '' !== $custom_code && self::is_language_tag( $custom_code ) && '' !== $custom_label && ! isset( $languages[ $custom_code ] ) ) {
			$languages[ $custom_code ] = $custom_label;
		}
		return $languages;
	}

	/** Glossary rows from the settings table (empty rows and rows marked for removal are skipped). */
	private static function glossary_from_rows( $rows ) {
		$entries = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}
			$from = strtolower( trim( (string) ( $row['source_language'] ?? '' ) ) );
			$to = strtolower( trim( (string) ( $row['target_language'] ?? '' ) ) );
			$term = sanitize_text_field( (string) ( $row['source_term'] ?? '' ) );
			$use = sanitize_text_field( (string) ( $row['target_term'] ?? '' ) );
			if ( ! self::is_language_tag( $from ) || ! self::is_language_tag( $to ) || '' === $term || '' === $use ) {
				continue;
			}
			$entries[] = array(
				'source_language' => $from,
				'source_term'     => $term,
				'target_language' => $to,
				'target_term'     => $use,
				'note'            => sanitize_text_field( (string) ( $row['note'] ?? '' ) ),
			);
		}
		return $entries;
	}

	/** F6: the glossary as CSV (UTF-8 with its mark, comma separated), with a header row. */
	public static function glossary_csv( $entries ) {
		$out = fopen( 'php://temp', 'r+' );
		$columns = array( 'source_language', 'source_term', 'target_language', 'target_term', 'note' );
		fputcsv( $out, $columns, ',', '"', '' );
		foreach ( (array) $entries as $entry ) {
			// A cell that starts with = + - @ would be a formula in a spreadsheet: a quote keeps it text.
			fputcsv( $out, array_map( static fn( $c ) => preg_match( '/\A[=+\-@\t\r]/', (string) ( $entry[ $c ] ?? '' ) ) ? "'" . $entry[ $c ] : (string) ( $entry[ $c ] ?? '' ), $columns ), ',', '"', '' );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * F6: glossary entries from a CSV (comma or semicolon, as a spreadsheet saves it; with or without the header
	 * row of glossary_csv()), or from a TBX file: every concept gives the default language's term towards each other
	 * configured language that has one. Only valid rows; at most 2,000.
	 *
	 * @return list<array<string, string>>|\WP_Error
	 */
	public static function glossary_from_file( $text ) {
		$text = (string) $text;
		if ( '' === $text || strlen( $text ) > 2 * MB_IN_BYTES ) {
			return new \WP_Error( 'lingua_glossary_size', __( 'File vuoto o troppo grande (massimo 2 MB).', 'lingua-bridge' ) );
		}
		$text = 0 === strpos( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text;
		if ( ! mb_check_encoding( $text, 'UTF-8' ) || false !== strpos( $text, "\0" ) ) {
			return new \WP_Error( 'lingua_glossary_encoding', __( 'Il file deve essere in UTF-8.', 'lingua-bridge' ) );
		}
		$rows = '<' === substr( ltrim( $text ), 0, 1 ) ? self::tbx_rows( $text ) : self::csv_rows( $text );
		return is_wp_error( $rows ) ? $rows : array_slice( self::glossary_from_rows( $rows ), 0, 2000 );
	}

	private static function csv_rows( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $text ) );
		$separator = substr_count( (string) ( $lines[0] ?? '' ), ';' ) > substr_count( (string) ( $lines[0] ?? '' ), ',' ) ? ';' : ',';
		$columns = array( 'source_language', 'source_term', 'target_language', 'target_term', 'note' );
		$rows = array();
		foreach ( $lines as $i => $line ) {
			$cells = array_map( static fn( $c ) => 0 === strpos( (string) $c, "'" ) ? substr( (string) $c, 1 ) : (string) $c, str_getcsv( (string) $line, $separator, '"', '' ) );
			if ( 0 === $i && 'source_language' === strtolower( trim( (string) ( $cells[0] ?? '' ) ) ) ) {
				continue;
			}
			$rows[] = array_combine( $columns, array_pad( array_slice( $cells, 0, 5 ), 5, '' ) );
		}
		return $rows;
	}

	private static function tbx_rows( $xml ) {
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return new \WP_Error( 'lingua_glossary_invalid', __( 'Il file contiene una dichiarazione DOCTYPE o di entità: non viene letto.', 'lingua-bridge' ) );
		}
		$previous = libxml_use_internal_errors( true );
		$doc = new \DOMDocument();
		$loaded = $doc->loadXML( $xml, LIBXML_NONET | ( defined( 'LIBXML_NO_XXE' ) ? LIBXML_NO_XXE : 0 ) );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded || null !== $doc->doctype || ! in_array( strtolower( $doc->documentElement->localName ), array( 'tbx', 'martif' ), true ) ) {
			return new \WP_Error( 'lingua_glossary_invalid', __( 'Non è un file CSV o TBX valido.', 'lingua-bridge' ) );
		}
		$default = self::default_code();
		$rows = array();
		foreach ( $doc->getElementsByTagName( 'termEntry' ) as $entry ) {
			$terms = array();
			foreach ( $entry->getElementsByTagName( 'langSet' ) as $set ) {
				$code = strtolower( (string) strtok( str_replace( '_', '-', $set->getAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'lang' ) ?: $set->getAttribute( 'xml:lang' ) ), '-' ) );
				$term = $set->getElementsByTagName( 'term' )->item( 0 );
				if ( '' !== $code && $term && ! isset( $terms[ $code ] ) ) {
					$terms[ $code ] = trim( (string) $term->textContent );
				}
			}
			foreach ( $terms as $code => $term ) {
				if ( $code !== $default && isset( $terms[ $default ], self::all()[ $code ] ) ) {
					$rows[] = array( 'source_language' => $default, 'source_term' => $terms[ $default ], 'target_language' => $code, 'target_term' => $term, 'note' => '' );
				}
			}
		}
		return $rows;
	}

	/** Whether the site went through the setup (or was configured before it existed). */
	public static function is_configured() {
		return (bool) get_option( 'lingua_bridge_setup_done' ) || false !== get_option( self::OPTION, false );
	}

	private static function parse_glossary_lines( $raw ) {
		$entries = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line, 5 ) );
			if ( 5 !== count( $parts ) || ! self::is_language_tag( $parts[0] ) || ! self::is_language_tag( $parts[2] ) || '' === $parts[1] || '' === $parts[3] ) {
				continue;
			}
			$entries[] = array(
				'source_language' => strtolower( $parts[0] ),
				'source_term'     => sanitize_text_field( $parts[1] ),
				'target_language' => strtolower( $parts[2] ),
				'target_term'     => sanitize_text_field( $parts[3] ),
				'note'            => sanitize_text_field( $parts[4] ),
			);
		}
		return $entries;
	}

	private static function is_language_tag( $code ) {
		// Accept language tags such as en-GB, zh-Hans, and es-419, not only two-letter regions.
		return is_string( $code ) && (bool) preg_match( '/\A[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{1,8})*\z/', $code );
	}

	public static function translatable_meta_keys() {
		$settings = self::settings();
		$keys = isset( $settings['translatable_meta_keys'] ) && is_array( $settings['translatable_meta_keys'] ) ? $settings['translatable_meta_keys'] : array();
		// C13: a field set to «Traduci» in the table of the fields; one set to anything else is no longer translated.
		foreach ( (array) ( $settings['field_modes'] ?? array() ) as $key => $mode ) {
			if ( 'translate' === $mode ) {
				$keys[] = (string) $key;
			} else {
				$keys = array_diff( $keys, array( (string) $key ) );
			}
		}
		return array_values( array_unique( $keys ) );
	}
}
