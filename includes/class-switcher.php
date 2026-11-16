<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The language switcher that shows without anyone placing it, and the place that explains every form of it
 * (C1, C4 of the competition list, 1.8.0).
 *
 * - The floating switcher: at the end of a page where no other switcher was drawn (shortcode, block, menu, Etch
 *   component), the same server-side markup as the shortcode, fixed in a corner. It works without JavaScript and in
 *   a static copy. On unless turned off (`floating_switcher`).
 * - Etch: «Metti il selettore nella testata» puts the switcher block in the header component and in its language
 *   versions, after keeping a copy of each that one click puts back.
 * - The «Selettore» tab of the settings: where it was last seen, the four forms with a preview, the shortcode with
 *   its options, the language of the visitor (C2).
 */
final class Switcher {
	/** The copy of a component taken before the switcher went into it: its content, restored by «Ripristina». */
	const BACKUP_META = '_lingua_bridge_switcher_backup';
	/** form => array{path: string, time: int}: where each form of the switcher was last drawn. */
	const SEEN_PAGES_OPTION = 'lingua_bridge_switcher_seen_pages';
	const POSITIONS = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );

	/** Whether a switcher was drawn on this page (by any form): the floating one stays out then. */
	private static $drawn = false;

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'floating' ), 20 );
		add_action( 'admin_post_lingua_bridge_switcher_header', array( __CLASS__, 'handle_header' ) );
		add_action( 'admin_post_lingua_bridge_switcher_restore', array( __CLASS__, 'handle_restore' ) );
	}

	/**
	 * A switcher was drawn on this page, in `$form` (switcher, menu location, nav-block, floating, histeon): no
	 * floating one is added, and the «Selettore» tab says where it was seen. The option is written at most once a
	 * day per form, never on every visit.
	 */
	public static function drawn( $form ) {
		self::$drawn = true;
		$form = sanitize_key( (string) $form );
		if ( '' === $form || is_admin() ) {
			return;
		}
		$seen = get_option( self::SEEN_PAGES_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		if ( isset( $seen[ $form ]['time'] ) && (int) $seen[ $form ]['time'] > time() - DAY_IN_SECONDS ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only the path is kept, escaped where shown.
		$seen[ $form ] = array( 'path' => '' !== $path ? $path : '/', 'time' => time() );
		update_option( self::SEEN_PAGES_OPTION, array_slice( $seen, -12, null, true ), false );
	}

	/** @return array<string, array{path: string, time: int}> */
	public static function seen_pages() {
		$seen = get_option( self::SEEN_PAGES_OPTION, array() );
		return is_array( $seen ) ? $seen : array();
	}

	/** The floating switcher's settings, with their defaults: on, bottom right, a dropdown, the language names. */
	public static function options() {
		$settings = Languages::settings();
		$position = (string) ( $settings['floating_position'] ?? 'bottom-right' );
		return array(
			'enabled'  => 'off' !== ( $settings['floating_switcher'] ?? 'auto' ),
			'position' => in_array( $position, self::POSITIONS, true ) ? $position : 'bottom-right',
			'style'    => 'list' === ( $settings['floating_style'] ?? 'dropdown' ) ? 'list' : 'dropdown',
			'label'    => in_array( $settings['floating_label'] ?? 'name', array( 'code', 'both' ), true ) ? $settings['floating_label'] : 'name',
		);
	}

	/**
	 * At the end of the page: the floating switcher, when it is on, the page has versions in other languages and no
	 * other switcher was drawn. Never in feeds, embeds, the customizer preview, the on-page editor or the Histeon
	 * canvas; `lingua_bridge_floating_switcher` (false) turns it off for a page.
	 */
	public static function floating() {
		$options = self::options();
		if ( self::$drawn || ! $options['enabled'] || is_admin() || is_feed() || is_embed() || is_customize_preview() || Editor::requested() ) {
			return;
		}
		if ( ! apply_filters( 'lingua_bridge_floating_switcher', true ) ) {
			return;
		}
		$html = Frontend::render_floating( array( 'style' => $options['style'], 'label' => $options['label'] ), $options['position'] );
		if ( '' === $html ) {
			return;
		}
		self::drawn( 'floating' );
		echo $html . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by render_switcher().
	}

	/**
	 * The floating switcher's styles: fixed in its corner, the theme's colors (block themes' base and contrast,
	 * else the system's), at least 44 px to touch, opening towards the page.
	 */
	public static function floating_styles() {
		return ':where(.lb-language-switcher--floating){position:fixed;z-index:99990;margin:0;padding:.25rem;border-radius:.5rem;background:var(--wp--preset--color--base,Canvas);color:var(--wp--preset--color--contrast,CanvasText);box-shadow:0 2px 10px rgb(0 0 0/.25);font-size:1rem}'
			. ':where(.lb-language-switcher--bottom-right){inset:auto 1rem 1rem auto}:where(.lb-language-switcher--bottom-left){inset:auto auto 1rem 1rem}'
			. ':where(.lb-language-switcher--top-right){inset:1rem 1rem auto auto}:where(.lb-language-switcher--top-left){inset:1rem auto auto 1rem}'
			. ':where(.lb-language-switcher--floating) a,:where(.lb-language-switcher--floating) summary{display:inline-flex;align-items:center;min-block-size:44px;min-inline-size:44px;padding:0 .75rem;box-sizing:border-box}'
			. ':where(.lb-language-switcher--floating) .lb-language-switcher__menu ul{background:var(--wp--preset--color--base,Canvas);color:var(--wp--preset--color--contrast,CanvasText)}'
			. ':where(.lb-language-switcher--bottom-right,.lb-language-switcher--bottom-left) .lb-language-switcher__menu ul{bottom:100%;margin-block-end:.25rem}'
			. ':where(.lb-language-switcher--bottom-right,.lb-language-switcher--top-right) .lb-language-switcher__menu ul{right:0}'
			. '@media print{.lb-language-switcher--floating{display:none}}';
	}

	// ------------------------------------------------------------------------------------------------- Etch

	public static function etch_active() {
		return defined( 'ETCH_PLUGIN_FILE' ) || defined( 'ETCH_VERSION' ) || 'etch' === get_template();
	}

	/**
	 * The Etch components that may be the site's header, best first: published originals (not a language version),
	 * those with a <header> element before the others, then those used by more templates and pages.
	 *
	 * @return list<array{id: int, title: string, has_switcher: bool, backup: bool}>
	 */
	public static function etch_headers() {
		global $wpdb;
		$ids = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => 'etch_component_html_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'  => true,
			)
		);
		$found = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			$row = Storage::find( $id );
			if ( ! $post || ( $row && Languages::default_code() !== $row['language'] ) ) {
				continue;
			}
			$content = (string) $post->post_content;
			$is_header = false !== strpos( $content, '"tag":"header"' );
			if ( ! $is_header && ! preg_match( '/header|testata|masthead|intestazione|navbar/i', $post->post_title . ' ' . $post->post_name ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a count over templates and pages, only on this admin screen.
			$uses = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('wp_template','wp_template_part','page','post') AND post_content LIKE %s", '%' . $wpdb->esc_like( '"ref":' . $id . ',' ) . '%' ) );
			$found[] = array(
				'id'           => absint( $id ),
				'title'        => (string) $post->post_title,
				'has_switcher' => self::has_switcher( $content ),
				'backup'       => '' !== (string) get_post_meta( $id, self::BACKUP_META, true ),
				'score'        => ( $is_header ? 1000 : 0 ) + $uses,
			);
		}
		usort(
			$found,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_map(
			static function ( $item ) {
				unset( $item['score'] );
				return $item;
			},
			$found
		);
	}

	/** Content that already draws a switcher: the block, the shortcode or the Etch component of the etch/ folder. */
	public static function has_switcher( $content ) {
		$content = (string) $content;
		return false !== strpos( $content, 'wp:' . Blocks::LANGUAGE_SWITCHER ) || false !== strpos( $content, '[lingua_bridge_switcher' ) || false !== strpos( $content, 'data-lb-etch-language-switcher' );
	}

	/**
	 * The header component `$component_id` and each of its language versions get the switcher block, as a dropdown
	 * of the language codes, at the end of the first <nav> (else of the <header>, else of the component). Before
	 * each is saved its content is kept in BACKUP_META. A version that followed its original still does after.
	 *
	 * @return int|\WP_Error The number of components changed.
	 */
	public static function put_in_header( $component_id ) {
		$component_id = absint( $component_id );
		$component = get_post( $component_id );
		if ( ! $component || 'wp_block' !== $component->post_type || ! current_user_can( 'edit_post', $component_id ) ) {
			return new \WP_Error( 'lingua_switcher_component', __( 'Componente della testata non trovato, o non puoi modificarlo.', 'lingua-bridge' ) );
		}
		$row = Storage::find( $component_id );
		if ( $row && Languages::default_code() !== $row['language'] ) {
			return new \WP_Error( 'lingua_switcher_component', __( 'Scegli il componente dell’originale: le sue versioni nelle altre lingue ricevono il selettore con lui.', 'lingua-bridge' ) );
		}
		$members = array( $component_id );
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$id = absint( $member['object_id'] );
			if ( $id !== $component_id && 'wp_block' === get_post_type( $id ) ) {
				$members[] = $id;
			}
		}
		foreach ( $members as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new \WP_Error( 'lingua_switcher_component', __( 'Non puoi modificare una delle versioni del componente: nessuna è stata cambiata.', 'lingua-bridge' ) );
			}
		}
		// Which language versions follow the original now: after the change they still do.
		$current = array();
		foreach ( $members as $id ) {
			$member_row = Storage::find( $id );
			$current[ $id ] = $member_row && $id !== $component_id && ! Translations::is_stale( $member_row );
		}
		$changed = 0;
		foreach ( $members as $id ) {
			$content = (string) get_post_field( 'post_content', $id, 'raw' );
			if ( self::has_switcher( $content ) ) {
				continue;
			}
			update_post_meta( $id, self::BACKUP_META, wp_slash( $content ) );
			$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => self::insert_block( $content ) ) ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			++$changed;
		}
		foreach ( $current as $id => $follows ) {
			if ( $follows ) {
				Translations::snapshot( $id );
			}
		}
		return $changed;
	}

	/** The block markup of `$content` with the switcher block added where put_in_header() says. */
	public static function insert_block( $content ) {
		$blocks = parse_blocks( (string) $content );
		$switcher = array(
			'blockName'    => Blocks::LANGUAGE_SWITCHER,
			'attrs'        => array( 'style' => 'dropdown', 'label' => 'code' ),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
		foreach ( array( 'nav', 'header' ) as $tag ) {
			if ( self::append_into( $blocks, $tag, $switcher ) ) {
				return serialize_blocks( $blocks );
			}
		}
		$blocks[] = $switcher;
		return serialize_blocks( $blocks );
	}

	/** Appends `$block` to the first Etch element with tag `$tag`, depth first. */
	private static function append_into( array &$blocks, $tag, array $block ) {
		foreach ( $blocks as &$parsed ) {
			if ( 'etch/element' === ( $parsed['blockName'] ?? '' ) && $tag === ( $parsed['attrs']['tag'] ?? '' ) ) {
				$parsed['innerBlocks'][] = $block;
				$parsed['innerContent'][] = null;
				$parsed['innerContent'][] = "\n";
				return true;
			}
			if ( ! empty( $parsed['innerBlocks'] ) && self::append_into( $parsed['innerBlocks'], $tag, $block ) ) {
				return true;
			}
		}
		return false;
	}

	/** «Ripristina»: the component and its versions get back the content kept before the switcher went in. */
	public static function restore( $component_id ) {
		$component_id = absint( $component_id );
		$row = Storage::find( $component_id );
		$members = array( $component_id );
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$members[] = absint( $member['object_id'] );
		}
		$restored = 0;
		foreach ( array_unique( $members ) as $id ) {
			$backup = (string) get_post_meta( $id, self::BACKUP_META, true );
			if ( '' === $backup || 'wp_block' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$result = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $backup ) ), true );
			if ( ! is_wp_error( $result ) ) {
				delete_post_meta( $id, self::BACKUP_META );
				++$restored;
			}
		}
		return $restored;
	}

	public static function handle_header() {
		self::handle( 'lingua_bridge_switcher_header', array( __CLASS__, 'put_in_header' ), __( 'Selettore messo nella testata: %d componenti cambiati. Le copie di prima si ripristinano da qui.', 'lingua-bridge' ) );
	}

	public static function handle_restore() {
		self::handle( 'lingua_bridge_switcher_restore', array( __CLASS__, 'restore' ), __( 'Testata ripristinata: %d componenti come prima.', 'lingua-bridge' ) );
	}

	private static function handle( $action, $callback, $done ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per modificare le impostazioni.', 'lingua-bridge' ) );
		}
		check_admin_referer( $action );
		$result = call_user_func( $callback, isset( $_POST['component'] ) ? absint( $_POST['component'] ) : 0 );
		$args = array( 'page' => 'lingua-bridge-settings', 'tab' => 'switcher' );
		$args += is_wp_error( $result ) ? array( 'lb_error' => Admin::stash_error( $result->get_error_message() ) ) : array( 'lb_message' => Admin::stash_message( sprintf( $done, (int) $result ) ) );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------------------------------------------- the tab

	/** The links of the preview: each language's home, the original's first and current. */
	private static function preview_links() {
		$links = array();
		foreach ( Languages::all() as $code => $label ) {
			$home = MenuSwitcher::language_home( $code );
			$links[] = array( 'code' => $code, 'label' => Languages::label( $code ), 'url' => '' !== $home ? $home : home_url( '/' ), 'current' => Languages::default_code() === $code );
		}
		return $links;
	}

	/** The «Selettore» tab, inside the settings form: where it is seen, the floating one, the menu, the visitor. */
	public static function render_section() {
		$settings = Languages::settings();
		$options = self::options();
		echo '<h2>' . esc_html__( 'Dove si vede oggi', 'lingua-bridge' ) . '</h2>';
		$names = array(
			'switcher' => __( 'shortcode, blocco o componente Etch', 'lingua-bridge' ),
			'floating' => __( 'selettore fluttuante', 'lingua-bridge' ),
			'nav-block' => __( 'navigazione del tema', 'lingua-bridge' ),
			'histeon'  => __( 'Histeon (data.lingua)', 'lingua-bridge' ),
		);
		$seen = self::seen_pages();
		if ( empty( $seen ) ) {
			echo '<p>' . esc_html__( 'Nessuna pagina del sito ha ancora mostrato un selettore. Il selettore fluttuante compare da solo dove non ce n’è un altro.', 'lingua-bridge' ) . '</p>';
		} else {
			echo '<ul class="lb-switcher-seen">';
			foreach ( $seen as $form => $where ) {
				/* translators: 1: form of the switcher, 2: address of the page, 3: date. */
				echo '<li>' . esc_html( sprintf( __( '%1$s, per esempio su %2$s (%3$s)', 'lingua-bridge' ), $names[ $form ] ?? sprintf( /* translators: %s: menu location. */ __( 'menu «%s»', 'lingua-bridge' ), $form ), $where['path'] ?? '/', wp_date( get_option( 'date_format' ), (int) ( $where['time'] ?? 0 ) ) ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<h2>' . esc_html__( 'Selettore fluttuante', 'lingua-bridge' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Quando', 'lingua-bridge' ) . '</th><td><input type="hidden" name="floating_shown" value="1"><label for="lb-floating"><input id="lb-floating" type="checkbox" name="floating_switcher" value="auto" ' . checked( $options['enabled'], true, false ) . '> ' . esc_html__( 'Mostralo nelle pagine dove non c’è un altro selettore.', 'lingua-bridge' ) . '</label><p class="description">' . esc_html__( 'È HTML stampato dal sito: funziona senza JavaScript, con la tastiera e nelle copie statiche. Sparisce da solo sulle pagine che hanno già il selettore nel menu, il blocco, lo shortcode o il componente Etch.', 'lingua-bridge' ) . '</p></td></tr>';
		$select = static function ( $name, $value, $choices ) {
			$out = '<select id="lb-' . esc_attr( str_replace( '_', '-', $name ) ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( $choices as $key => $label ) {
				$out .= '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			return $out . '</select>';
		};
		echo '<tr><th scope="row"><label for="lb-floating-position">' . esc_html__( 'Posizione', 'lingua-bridge' ) . '</label></th><td>' . $select( 'floating_position', $options['position'], array( 'bottom-right' => __( 'In basso a destra', 'lingua-bridge' ), 'bottom-left' => __( 'In basso a sinistra', 'lingua-bridge' ), 'top-right' => __( 'In alto a destra', 'lingua-bridge' ), 'top-left' => __( 'In alto a sinistra', 'lingua-bridge' ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $select.
		echo '<tr><th scope="row"><label for="lb-floating-style">' . esc_html__( 'Aspetto', 'lingua-bridge' ) . '</label></th><td>' . $select( 'floating_style', $options['style'], array( 'dropdown' => __( 'Tendina', 'lingua-bridge' ), 'list' => __( 'Elenco', 'lingua-bridge' ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $select.
		echo '<tr><th scope="row"><label for="lb-floating-label">' . esc_html__( 'Etichetta', 'lingua-bridge' ) . '</label></th><td>' . $select( 'floating_label', $options['label'], array( 'name' => __( 'Nome della lingua', 'lingua-bridge' ), 'code' => __( 'Codice (IT, EN)', 'lingua-bridge' ), 'both' => __( 'Codice e nome', 'lingua-bridge' ) ) ) . '<p class="description">' . esc_html__( 'Niente bandiere: una lingua non è un paese. I colori vengono dal tema.', 'lingua-bridge' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $select.
		echo '<tr><th scope="row">' . esc_html__( 'Anteprima', 'lingua-bridge' ) . '</th><td><div class="lb-switcher-preview">' . Frontend::switcher_markup( self::preview_links(), array( 'style' => $options['style'], 'label' => $options['label'], 'hide_current' => false, 'show_missing' => false, 'floating' => '' ) ) . '</div><p class="description">' . esc_html__( 'Lo stesso codice del sito, con le home delle lingue. Salva per vedere le scelte nuove.', 'lingua-bridge' ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by switcher_markup().
		echo '</table>';

		echo '<h2>' . esc_html__( 'Nel menu del tema', 'lingua-bridge' ) . '</h2><table class="form-table" role="presentation">';
		$where = MenuSwitcher::where();
		echo '<tr><th scope="row"><label for="lb-switcher-menu">' . esc_html__( 'Selettore di lingua nel menu', 'lingua-bridge' ) . '</label></th><td><select id="lb-switcher-menu" name="switcher_menu"><option value="">' . esc_html__( 'Nessuno', 'lingua-bridge' ) . '</option>';
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			echo '<option value="nav-block" ' . selected( $where, 'nav-block', false ) . '>' . esc_html__( 'Navigazione del tema (blocchi)', 'lingua-bridge' ) . '</option>';
		}
		foreach ( get_registered_nav_menus() as $location => $description ) {
			/* translators: %s: menu location name. */
			echo '<option value="' . esc_attr( $location ) . '" ' . selected( $where, $location, false ) . '>' . esc_html( sprintf( __( 'Menu: %s', 'lingua-bridge' ), $description ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html( self::etch_active() ? __( 'Su Etch il menu è un componente, non un menu di WordPress: usa «Metti il selettore nella testata» qui sotto.', 'lingua-bridge' ) : __( 'Aggiunge in fondo al menu i codici delle lingue (IT · EN): sulle pagine tradotte portano alle loro versioni, altrove alle home delle lingue.', 'lingua-bridge' ) ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'La lingua del visitatore', 'lingua-bridge' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'All’ingresso nel sito', 'lingua-bridge' ) . '</th><td><fieldset>';
		$visitor = Languages::visitor_language();
		foreach ( array(
			'off'      => array( __( 'Niente', 'lingua-bridge' ), __( 'Ognuno vede la pagina che ha chiesto.', 'lingua-bridge' ) ),
			'suggest'  => array( __( 'Proponi la sua lingua', 'lingua-bridge' ), __( 'Una piccola barra con il nome della lingua del browser, se la pagina ce l’ha. Chiusa una volta, non torna.', 'lingua-bridge' ) ),
			'redirect' => array( __( 'Portalo nella sua lingua', 'lingua-bridge' ), __( 'Alla prima pagina della visita, se è nell’originale e c’è la versione nella lingua del browser, ci va. Mai per i motori di ricerca, mai se arriva da un link a una lingua, e la scelta fatta col selettore vince sempre (resta nel browser, senza cookie).', 'lingua-bridge' ) ),
		) as $value => $texts ) {
			echo '<p><label><input type="radio" name="visitor_language" value="' . esc_attr( $value ) . '" ' . checked( $visitor, $value, false ) . '> <strong>' . esc_html( $texts[0] ) . '</strong></label><br><span class="description">' . esc_html( $texts[1] ) . '</span></p>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Tutto avviene nel browser leggendo gli hreflang della pagina: funziona con le cache e nelle copie statiche, e nessuno vede pagine diverse allo stesso indirizzo.', 'lingua-bridge' ) . '</p></td></tr></table>';
	}

	/** After the settings form: Etch's header, and the shortcode with its options. */
	public static function render_tools() {
		if ( self::etch_active() ) {
			echo '<h2>' . esc_html__( 'Nella testata di Etch', 'lingua-bridge' ) . '</h2>';
			$headers = self::etch_headers();
			if ( empty( $headers ) ) {
				echo '<p>' . esc_html__( 'Non ho trovato il componente della testata: mettilo a mano con il blocco «Selettore lingua — Lingua Bridge» o lo shortcode qui sotto.', 'lingua-bridge' ) . '</p>';
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lingua_bridge_switcher_header">';
				wp_nonce_field( 'lingua_bridge_switcher_header' );
				echo '<p><label for="lb-switcher-component">' . esc_html__( 'Componente della testata', 'lingua-bridge' ) . '</label> <select id="lb-switcher-component" name="component">';
				foreach ( $headers as $header ) {
					echo '<option value="' . esc_attr( (string) $header['id'] ) . '">' . esc_html( $header['title'] . ( $header['has_switcher'] ? ' — ' . __( 'ha già il selettore', 'lingua-bridge' ) : '' ) ) . '</option>';
				}
				echo '</select> <button class="button button-primary" type="submit">' . esc_html__( 'Metti il selettore nella testata', 'lingua-bridge' ) . '</button></p><p class="description">' . esc_html__( 'Aggiunge il blocco del selettore (tendina con i codici) in fondo alla navigazione della testata, anche nelle sue versioni nelle altre lingue. Prima fa una copia di ogni componente, che si ripristina da qui. Poi lo sposti o lo modifichi in Etch come ogni altro elemento.', 'lingua-bridge' ) . '</p></form>';
				foreach ( $headers as $header ) {
					if ( $header['backup'] ) {
						echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lingua_bridge_switcher_restore"><input type="hidden" name="component" value="' . esc_attr( (string) $header['id'] ) . '">';
						wp_nonce_field( 'lingua_bridge_switcher_restore' );
						/* translators: %s: component name. */
						echo '<p><button class="button" type="submit">' . esc_html( sprintf( __( 'Ripristina «%s» com’era prima del selettore', 'lingua-bridge' ), $header['title'] ) ) . '</button></p></form>';
					}
				}
			}
		}
		echo '<h2>' . esc_html__( 'Blocco e shortcode', 'lingua-bridge' ) . '</h2>';
		echo '<p>' . esc_html__( 'Nell’editor a blocchi: il blocco «Selettore lingua — Lingua Bridge», con le stesse scelte nella barra laterale. Altrove (widget, Etch, temi classici) lo shortcode:', 'lingua-bridge' ) . '</p>';
		echo '<div class="lb-shortcode-builder" data-lb-shortcode-builder><p><label>' . esc_html__( 'Aspetto', 'lingua-bridge' ) . ' <select data-lb-attr="style"><option value="">' . esc_html__( 'Elenco', 'lingua-bridge' ) . '</option><option value="dropdown">' . esc_html__( 'Tendina', 'lingua-bridge' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'Etichetta', 'lingua-bridge' ) . ' <select data-lb-attr="label"><option value="">' . esc_html__( 'Nome', 'lingua-bridge' ) . '</option><option value="code">' . esc_html__( 'Codice', 'lingua-bridge' ) . '</option><option value="both">' . esc_html__( 'Codice e nome', 'lingua-bridge' ) . '</option></select></label> ';
		echo '<label><input type="checkbox" data-lb-attr="hide_current" value="yes"> ' . esc_html__( 'Nascondi la lingua della pagina', 'lingua-bridge' ) . '</label> ';
		echo '<label><input type="checkbox" data-lb-attr="show_missing" value="yes"> ' . esc_html__( 'Anche le lingue senza questa pagina (portano alla loro home)', 'lingua-bridge' ) . '</label></p>';
		echo '<p><label for="lb-shortcode">' . esc_html__( 'Shortcode da copiare', 'lingua-bridge' ) . '</label><br><input id="lb-shortcode" class="large-text code" type="text" readonly value="[lingua_bridge_switcher]" data-lb-shortcode></p></div>';
		echo '<ul class="ul-disc"><li><code>style="dropdown"</code> ' . esc_html__( 'una tendina che si apre senza JavaScript; senza: un elenco in riga.', 'lingua-bridge' ) . '</li><li><code>label="code"</code> / <code>label="both"</code> ' . esc_html__( 'IT, EN oppure «IT · Italiano»; senza: il nome della lingua.', 'lingua-bridge' ) . '</li><li><code>hide_current="yes"</code> ' . esc_html__( 'toglie la lingua della pagina.', 'lingua-bridge' ) . '</li><li><code>show_missing="yes"</code> ' . esc_html__( 'mostra anche le lingue senza questa pagina, verso la loro home.', 'lingua-bridge' ) . '</li></ul>';
		$script = 'document.addEventListener("DOMContentLoaded",function(){var b=document.querySelector("[data-lb-shortcode-builder]");if(!b)return;var out=b.querySelector("[data-lb-shortcode]");function w(){var s="[lingua_bridge_switcher";b.querySelectorAll("[data-lb-attr]").forEach(function(f){var v=f.type==="checkbox"?(f.checked?f.value:""):f.value;if(v){s+=" "+f.getAttribute("data-lb-attr")+"=\""+v+"\"";}});out.value=s+"]";}b.addEventListener("change",w);out.addEventListener("focus",function(){out.select();});w();});';
		wp_print_inline_script_tag( $script );
	}
}
