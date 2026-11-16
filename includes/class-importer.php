<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Importer {
	const BATCH_SIZE = 25;
	const TRANSLATEPRESS_BATCH_SIZE = 5;

	public static function init() {
		add_action( 'wp_ajax_lingua_bridge_import_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( $hook, 'lingua-bridge-import' ) ) {
			return;
		}
		wp_enqueue_script( 'lingua-bridge-import', plugins_url( '../assets/importer.js', __FILE__ ), array( 'wp-i18n' ), LINGUA_BRIDGE_VERSION, true );
		I18n::script( 'lingua-bridge-import' );
		wp_localize_script(
			'lingua-bridge-import',
			'LinguaBridgeImport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lingua_bridge_import' ),
				'labels'  => array(
					'working' => __( 'Importazione in corso…', 'lingua-bridge' ),
					'paused'  => __( 'Importazione interrotta. Puoi riprenderla: gli elementi già importati verranno riconosciuti.', 'lingua-bridge' ),
					'error'   => __( 'Si è verificato un errore durante l’importazione.', 'lingua-bridge' ),
				),
			)
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non hai i permessi per importare traduzioni.', 'lingua-bridge' ) );
		}
		$sources = self::sources();
		echo '<div class="wrap lb-wrap lb-import">';
		Admin::page_header( 'lingua-bridge-import', __( 'Importa traduzioni', 'lingua-bridge' ), __( 'Da Polylang, WPML o TranslatePress: contenuti, relazioni e stringhe.', 'lingua-bridge' ) );
		echo '<p>' . esc_html__( 'Scegli il plugin sorgente. Lingua Bridge legge i contenuti esistenti e non cancella né modifica i dati del plugin sorgente.', 'lingua-bridge' ) . '</p>';
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Prima di iniziare', 'lingua-bridge' ) . '</strong> ' . esc_html__( 'Configura in Lingua Bridge tutte le lingue che vuoi importare. L’importazione procede a piccoli gruppi e può essere interrotta e ripresa.', 'lingua-bridge' ) . '</p></div>';
		echo '<table class="widefat striped lb-table"><thead><tr><th>' . esc_html__( 'Sorgente', 'lingua-bridge' ) . '</th><th>' . esc_html__( 'Stato', 'lingua-bridge' ) . '</th><th>' . esc_html__( 'Cosa verrà importato', 'lingua-bridge' ) . '</th></tr></thead><tbody>';
		echo '<tr><td><strong>Polylang</strong></td><td>' . ( $sources['polylang']['available'] ? '<span class="lb-state lb-state-published">' . esc_html__( 'Rilevato', 'lingua-bridge' ) . '</span>' : '<span>' . esc_html__( 'Non attivo', 'lingua-bridge' ) . '</span>' ) . '</td><td>' . esc_html__( 'Collega i contenuti già esistenti per lingua. Non copia o modifica i post.', 'lingua-bridge' ) . '</td></tr>';
		echo '<tr><td><strong>WPML</strong></td><td>' . ( $sources['wpml']['available'] ? '<span class="lb-state lb-state-published">' . esc_html__( 'Rilevato', 'lingua-bridge' ) . '</span>' : '<span>' . esc_html__( 'Non attivo', 'lingua-bridge' ) . '</span>' ) . '</td><td>' . esc_html__( 'Collega i contenuti già esistenti per lingua. Non copia o modifica i post.', 'lingua-bridge' ) . '</td></tr>';
		echo '<tr><td><strong>TranslatePress</strong></td><td>' . ( $sources['translatepress']['available'] ? '<span class="lb-state lb-state-published">' . esc_html__( 'Rilevato', 'lingua-bridge' ) . '</span>' : '<span>' . esc_html__( 'Non attivo', 'lingua-bridge' ) . '</span>' ) . '</td><td>' . esc_html__( 'Crea bozze WordPress autonome con le traduzioni esatte trovate nei titoli, negli estratti e nei blocchi. Le bozze vanno revisionate; campi speciali e stringhe parziali non corrispondenti restano da completare.', 'lingua-bridge' ) . '</td></tr></tbody></table>';
		echo '<div class="lb-import-panel"><h2>' . esc_html__( 'Avvia importazione', 'lingua-bridge' ) . '</h2><p><label for="lb-import-source"><strong>' . esc_html__( 'Plugin sorgente', 'lingua-bridge' ) . '</strong></label></p>';
		echo '<select id="lb-import-source">';
		$first_available = $sources['polylang']['available'] ? 'polylang' : ( $sources['wpml']['available'] ? 'wpml' : ( $sources['translatepress']['available'] ? 'translatepress' : '' ) );
		foreach ( array( 'polylang' => 'Polylang', 'wpml' => 'WPML', 'translatepress' => 'TranslatePress' ) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $first_available, $key, false ) . ' ' . disabled( ! $sources[ $key ]['available'], true, false ) . '>' . esc_html( $label . ( $sources[ $key ]['available'] ? '' : ' — ' . __( 'non attivo', 'lingua-bridge' ) ) ) . '</option>';
		}
		echo '</select> <button type="button" class="button button-primary" id="lb-import-start" ' . disabled( '' === $first_available, true, false ) . '>' . esc_html__( 'Avvia', 'lingua-bridge' ) . '</button> <button type="button" class="button" id="lb-import-stop" hidden>' . esc_html__( 'Interrompi', 'lingua-bridge' ) . '</button>';
		echo '<div id="lb-import-status" class="lb-import-status" role="status" aria-live="polite"></div><progress id="lb-import-progress" hidden></progress>';
		echo '<p class="description">' . esc_html__( 'Polylang e WPML mantengono i post e i loro stati attuali. TranslatePress crea bozze indipendenti, così puoi rivedere testo, immagini e struttura nell’editor WordPress o Etch prima di pubblicarle.', 'lingua-bridge' ) . '</p></div>';
		self::render_strings_panel();
		echo '</div>';
	}

	/** The strings of the other plugin (site title, widgets, theme texts): found in its data, even if inactive. */
	private static function render_strings_panel() {
		$found = StringImport::detect();
		echo '<div class="lb-import-panel"><h2>' . esc_html__( 'Stringhe: titolo del sito, widget, testi del tema', 'lingua-bridge' ) . '</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows the last result of this user.
		if ( isset( $_GET['lb_strings'] ) ) {
			$message = StringImport::last_message();
			if ( '' !== $message ) {
				echo '<div class="notice notice-success inline"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
		echo '<p>' . esc_html__( 'Le traduzioni fatte fuori dai contenuti diventano stringhe di Lingua Bridge (Stringhe). Si leggono dai dati dell’altro plugin, anche se è già disattivato; i suoi dati non cambiano. Quelle fatte da una persona sono pubblicate, quelle automatiche o incomplete restano bozze da approvare.', 'lingua-bridge' ) . '</p>';
		$labels = array( 'polylang' => 'Polylang', 'wpml' => 'WPML', 'translatepress' => 'TranslatePress' );
		$any = false;
		foreach ( $labels as $key => $label ) {
			if ( empty( $found[ $key ] ) ) {
				continue;
			}
			$any = true;
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lb-import-strings"><input type="hidden" name="action" value="lingua_bridge_import_strings"><input type="hidden" name="source" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'lingua_bridge_import_strings' );
			/* translators: %s: plugin name. */
			echo '<button class="button">' . esc_html( sprintf( __( 'Importa le stringhe di %s', 'lingua-bridge' ), $label ) ) . '</button></form> ';
		}
		if ( ! $any ) {
			echo '<p class="description">' . esc_html__( 'Nessun dato di Polylang, WPML o TranslatePress trovato nel database.', 'lingua-bridge' ) . '</p>';
		}
		echo '</div>';
	}

	public static function ajax_batch() {
		check_ajax_referer( 'lingua_bridge_import', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Non hai i permessi per importare traduzioni.', 'lingua-bridge' ) ), 403 );
		}
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$cursor = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		if ( ! isset( self::sources()[ $source ] ) || ! self::sources()[ $source ]['available'] ) {
			wp_send_json_error( array( 'message' => __( 'Il plugin sorgente selezionato non è attivo.', 'lingua-bridge' ) ), 400 );
		}

		if ( 'polylang' === $source ) {
			$result = self::import_polylang_batch( $cursor );
		} elseif ( 'wpml' === $source ) {
			$result = self::import_wpml_batch( $cursor );
		} else {
			$result = self::import_translatepress_batch( $cursor );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		// C32: the contents done, the terms and the menus of the same plugin.
		if ( ! empty( $result['done'] ) && in_array( $source, array( 'polylang', 'wpml' ), true ) ) {
			$result['counts'] = array_merge( $result['counts'], self::import_terms_and_menus( $source ) );
		}
		wp_send_json_success( $result );
	}

	private static function sources() {
		return array(
			'polylang'      => array( 'available' => function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_default_language' ) ),
			'wpml'          => array( 'available' => defined( 'ICL_SITEPRESS_VERSION' ) ),
			'translatepress' => array( 'available' => class_exists( '\\TRP_Translate_Press' ) ),
		);
	}

	private static function import_polylang_batch( $cursor ) {
		$source_default = function_exists( 'pll_default_language' ) ? \pll_default_language( 'slug' ) : '';
		$default_language = self::map_language( $source_default );
		if ( ! $default_language || Languages::default_code() !== $default_language ) {
			return new \WP_Error( 'lingua_import_default', __( 'La lingua predefinita di Polylang deve corrispondere alla lingua predefinita configurata in Lingua Bridge.', 'lingua-bridge' ) );
		}
		$ids = self::posts_after( $cursor, self::BATCH_SIZE );
		$counts = self::empty_counts();
		foreach ( $ids as $post_id ) {
			$translations = \pll_get_post_translations( $post_id );
			if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
				continue;
			}
			$translated_ids = array_values( array_filter( array_map( 'absint', $translations ) ) );
			if ( empty( $translated_ids ) || absint( $post_id ) !== min( $translated_ids ) ) {
				continue;
			}
			++$counts['groups_seen'];
			$mapping = array();
			foreach ( $translations as $language_code => $translated_id ) {
				$target_language = self::map_language( $language_code );
				$translated_id = absint( $translated_id );
				if ( ! $target_language || ! $translated_id || ! Translations::supports_post( $translated_id ) ) {
					continue;
				}
				if ( isset( $mapping[ $target_language ] ) && $mapping[ $target_language ] !== $translated_id ) {
					$mapping[ $target_language ] = 0;
				} else {
					$mapping[ $target_language ] = $translated_id;
				}
			}
			$result = self::save_group( $mapping );
			++$counts[ $result ];
		}
		return array(
			'cursor'  => empty( $ids ) ? $cursor : max( array_map( 'absint', $ids ) ),
			'done'    => count( $ids ) < self::BATCH_SIZE,
			'counts'  => $counts,
			'checked' => count( $ids ),
		);
	}

	private static function import_wpml_batch( $cursor ) {
		$source_default = apply_filters( 'wpml_default_language', null );
		$default_language = self::map_language( $source_default );
		if ( ! $default_language || Languages::default_code() !== $default_language ) {
			return new \WP_Error( 'lingua_import_default', __( 'La lingua predefinita di WPML deve corrispondere alla lingua predefinita configurata in Lingua Bridge.', 'lingua-bridge' ) );
		}
		$ids = self::posts_after( $cursor, self::BATCH_SIZE );
		$counts = self::empty_counts();
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$element_type = 'post_' . $post->post_type;
			$trid = apply_filters( 'wpml_element_trid', null, absint( $post_id ), $element_type );
			if ( ! $trid ) {
				continue;
			}
			$translations = apply_filters( 'wpml_get_element_translations', null, absint( $trid ), $element_type );
			if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
				continue;
			}
			$translated_ids = array();
			foreach ( $translations as $translation ) {
				if ( is_object( $translation ) && isset( $translation->element_id ) ) {
					$translated_ids[] = absint( $translation->element_id );
				}
			}
			$translated_ids = array_values( array_filter( $translated_ids ) );
			if ( empty( $translated_ids ) || absint( $post_id ) !== min( $translated_ids ) ) {
				continue;
			}
			++$counts['groups_seen'];
			$mapping = array();
			foreach ( $translations as $translation ) {
				if ( ! is_object( $translation ) || empty( $translation->language_code ) || empty( $translation->element_id ) ) {
					continue;
				}
				$target_language = self::map_language( $translation->language_code );
				$translated_id = absint( $translation->element_id );
				if ( ! $target_language || ! Translations::supports_post( $translated_id ) ) {
					continue;
				}
				if ( isset( $mapping[ $target_language ] ) && $mapping[ $target_language ] !== $translated_id ) {
					$mapping[ $target_language ] = 0;
				} else {
					$mapping[ $target_language ] = $translated_id;
				}
			}
			$result = self::save_group( $mapping );
			++$counts[ $result ];
		}
		return array(
			'cursor'  => empty( $ids ) ? $cursor : max( array_map( 'absint', $ids ) ),
			'done'    => count( $ids ) < self::BATCH_SIZE,
			'counts'  => $counts,
			'checked' => count( $ids ),
		);
	}

	private static function save_group( $mapping ) {
		$default_language = Languages::default_code();
		if ( ! isset( $mapping[ $default_language ] ) || ! $mapping[ $default_language ] || count( $mapping ) < 2 || in_array( 0, $mapping, true ) ) {
			return 'skipped';
		}
		$default_id = absint( $mapping[ $default_language ] );
		$source = get_post( $default_id );
		if ( ! $source || ! current_user_can( 'edit_post', $default_id ) ) {
			return 'skipped';
		}
		foreach ( $mapping as $language => $post_id ) {
			$post = get_post( absint( $post_id ) );
			if ( ! $post || $post->post_type !== $source->post_type ) {
				return 'skipped';
			}
		}

		$group_uuid = '';
		$has_existing = false;
		foreach ( $mapping as $language => $post_id ) {
			$row = Storage::find( $post_id );
			if ( ! $row ) {
				continue;
			}
			$has_existing = true;
			if ( $row['language'] !== $language || ( '' !== $group_uuid && $row['group_uuid'] !== $group_uuid ) ) {
				return 'conflicts';
			}
			$group_uuid = $row['group_uuid'];
		}
		if ( $has_existing ) {
			foreach ( $mapping as $language => $post_id ) {
				$row = Storage::find( $post_id );
				if ( ! $row || $row['language'] !== $language || $row['group_uuid'] !== $group_uuid ) {
					return 'conflicts';
				}
			}
			foreach ( $mapping as $post_id ) {
				if ( ! absint( get_post_meta( absint( $post_id ), '_lingua_bridge_source_id', true ) ) ) {
					update_post_meta( absint( $post_id ), '_lingua_bridge_source_id', $default_id );
				}
			}
			return 'already';
		}

		$group_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'lingua-', true );
		$source_hash = Translations::core_hash( $default_id );
		$rows = array();
		foreach ( $mapping as $language => $post_id ) {
			$rows[] = array(
				'object_id'    => $post_id,
				'group_uuid'   => $group_uuid,
				'language'     => $language,
				'source_id'    => $default_id,
				'source_hash'  => $source_hash,
				'review_state' => $default_language === $language ? 'source' : 'needs_review',
			);
		}
		if ( ! Storage::insert_group( $rows ) ) {
			return 'conflicts';
		}
		foreach ( $mapping as $post_id ) {
			update_post_meta( absint( $post_id ), '_lingua_bridge_source_id', $default_id );
		}
		return 'imported';
	}

	private static function import_translatepress_batch( $cursor ) {
		$settings = self::translatepress_settings();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$source_default = isset( $settings['default-language'] ) ? $settings['default-language'] : '';
		if ( self::map_language( $source_default ) !== Languages::default_code() ) {
			return new \WP_Error( 'lingua_import_default', __( 'La lingua predefinita di TranslatePress deve corrispondere alla lingua predefinita configurata in Lingua Bridge.', 'lingua-bridge' ) );
		}
		$targets = self::translatepress_targets( $settings, $source_default );
		if ( empty( $targets ) ) {
			return new \WP_Error( 'lingua_import_languages', __( 'Nessuna lingua attiva di TranslatePress corrisponde alle lingue configurate in Lingua Bridge.', 'lingua-bridge' ) );
		}

		$ids = self::posts_after( $cursor, self::TRANSLATEPRESS_BATCH_SIZE );
		$counts = self::empty_counts();
		foreach ( $ids as $post_id ) {
			++$counts['posts_seen'];
			$post = get_post( $post_id );
			$source_row = Storage::find( $post_id );
			if ( ! $post || ! Translations::supports_post( $post ) || ( $source_row && Languages::default_code() !== $source_row['language'] ) || ! current_user_can( 'edit_post', $post_id ) ) {
				++$counts['skipped'];
				continue;
			}
			$blocks = function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ? parse_blocks( $post->post_content ) : null;
			if ( is_array( $blocks ) && empty( $blocks ) && '' !== $post->post_content ) {
				$blocks = null;
			}
			$candidates = array();
			self::collect_candidates( $post->post_title, $candidates );
			self::collect_candidates( $post->post_excerpt, $candidates );
			self::collect_candidates( null === $blocks ? $post->post_content : $blocks, $candidates );
			$candidates = array_values( array_unique( $candidates ) );
			$resolved_for_post = false;
			foreach ( $targets as $locale => $language ) {
				$dictionary = self::translatepress_dictionary( $source_default, $locale, $candidates );
				if ( is_wp_error( $dictionary ) ) {
					return $dictionary;
				}
				if ( empty( $dictionary ) ) {
					continue;
				}
				$changed = false;
				$title = self::translate_value( $post->post_title, $dictionary, $changed );
				$excerpt = self::translate_value( $post->post_excerpt, $dictionary, $changed );
				$content = null === $blocks ? self::translate_value( $post->post_content, $dictionary, $changed ) : self::translate_node( $blocks, $dictionary, $changed );
				if ( null !== $blocks ) {
					$content = serialize_blocks( $content );
				}
				if ( ! $changed ) {
					continue;
				}

				// The dictionary is other people's input: no active markup the original does not have.
				$title = wp_strip_all_tags( $title );
				$excerpt = wp_strip_all_tags( $excerpt );
				if ( is_wp_error( Translations::check_active_markup( array( 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt ), array( 'title' => $title, 'content' => $content, 'excerpt' => $excerpt ) ) ) ) {
					++$counts['skipped'];
					continue;
				}
				$translation_id = Translations::create_draft( $post_id, $language );
				if ( is_wp_error( $translation_id ) ) {
					if ( 'lingua_exists' === $translation_id->get_error_code() ) {
						++$counts['already'];
						$resolved_for_post = true;
					} else {
						++$counts['skipped'];
					}
					continue;
				}
				$updated = wp_update_post(
					wp_slash(
						array(
							'ID'           => absint( $translation_id ),
							'post_title'   => $title,
							'post_content' => $content,
							'post_excerpt' => $excerpt,
						)
					),
					true
				);
				if ( is_wp_error( $updated ) ) {
					Storage::delete( $translation_id );
					wp_delete_post( $translation_id, true );
					++$counts['skipped'];
					continue;
				}
				++$counts['drafts'];
				$resolved_for_post = true;
			}
			if ( ! $resolved_for_post ) {
				++$counts['skipped'];
			}
		}
		return array(
			'cursor'  => empty( $ids ) ? $cursor : max( array_map( 'absint', $ids ) ),
			'done'    => count( $ids ) < self::TRANSLATEPRESS_BATCH_SIZE,
			'counts'  => $counts,
			'checked' => count( $ids ),
		);
	}

	private static function translatepress_settings() {
		if ( ! class_exists( '\\TRP_Translate_Press' ) || ! is_callable( array( '\\TRP_Translate_Press', 'get_trp_instance' ) ) ) {
			return new \WP_Error( 'lingua_translatepress_unavailable', __( 'TranslatePress non è attivo o non espone le impostazioni necessarie.', 'lingua-bridge' ) );
		}
		$instance = \TRP_Translate_Press::get_trp_instance();
		if ( ! is_object( $instance ) || ! is_callable( array( $instance, 'get_component' ) ) ) {
			return new \WP_Error( 'lingua_translatepress_settings', __( 'Non è stato possibile leggere le impostazioni di TranslatePress.', 'lingua-bridge' ) );
		}
		$component = $instance->get_component( 'settings' );
		if ( ! is_object( $component ) || ! is_callable( array( $component, 'get_settings' ) ) ) {
			return new \WP_Error( 'lingua_translatepress_settings', __( 'Non è stato possibile leggere le lingue di TranslatePress.', 'lingua-bridge' ) );
		}
		$settings = $component->get_settings();
		if ( ! is_array( $settings ) || empty( $settings['default-language'] ) || empty( $settings['publish-languages'] ) || ! is_array( $settings['publish-languages'] ) ) {
			return new \WP_Error( 'lingua_translatepress_languages', __( 'TranslatePress non ha lingue pubblicate configurate.', 'lingua-bridge' ) );
		}
		return $settings;
	}

	private static function translatepress_targets( $settings, $source_default ) {
		$targets = array();
		$ambiguous = array();
		foreach ( $settings['publish-languages'] as $locale ) {
			$locale = sanitize_text_field( $locale );
			if ( strtolower( $locale ) === strtolower( $source_default ) ) {
				continue;
			}
			$language = self::map_language( $locale );
			if ( ! $language || $language === Languages::default_code() ) {
				continue;
			}
			if ( isset( $targets[ $language ] ) ) {
				$ambiguous[ $language ] = true;
			}
			$targets[ $language ] = $locale;
		}
		foreach ( $ambiguous as $language => $unused ) {
			unset( $targets[ $language ] );
		}
		$by_locale = array();
		foreach ( $targets as $language => $locale ) {
			$by_locale[ $locale ] = $language;
		}
		return $by_locale;
	}

	private static function translatepress_dictionary( $source_locale, $target_locale, $candidates ) {
		global $wpdb;
		if ( empty( $candidates ) ) {
			return array();
		}
		$source_locale = strtolower( str_replace( '-', '_', $source_locale ) );
		$target_locale = strtolower( str_replace( '-', '_', $target_locale ) );
		$table = $wpdb->prefix . 'trp_dictionary_' . $source_locale . '_' . $target_locale;
		if ( ! preg_match( '/\\A' . preg_quote( $wpdb->prefix, '/' ) . 'trp_dictionary_[a-z0-9_]+\\z/i', $table ) ) {
			return new \WP_Error( 'lingua_translatepress_table', __( 'Il nome della tabella TranslatePress non è valido.', 'lingua-bridge' ) );
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $exists ) {
			return array();
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM `$table`", 0 );
		if ( ! is_array( $columns ) || ! in_array( 'original', $columns, true ) || ! in_array( 'translated', $columns, true ) ) {
			return new \WP_Error( 'lingua_translatepress_schema', __( 'La tabella di traduzione TranslatePress non ha una struttura riconosciuta.', 'lingua-bridge' ) );
		}
		$has_status = in_array( 'status', $columns, true );
		$translations = array();
		$ambiguous = array();
		foreach ( array_chunk( $candidates, 100 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$query = "SELECT original, translated" . ( $has_status ? ', status' : '' ) . " FROM `$table` WHERE original IN ($placeholders) AND translated IS NOT NULL AND translated <> ''";
			if ( $has_status ) {
				$query .= ' AND status > 0';
			}
			$rows = $wpdb->get_results( $wpdb->prepare( $query, $chunk ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$original = (string) $row['original'];
				$translated = (string) $row['translated'];
				if ( '' === $original || '' === $translated || $original === $translated || isset( $ambiguous[ $original ] ) ) {
					continue;
				}
				if ( isset( $translations[ $original ] ) && $translations[ $original ] !== $translated ) {
					unset( $translations[ $original ] );
					$ambiguous[ $original ] = true;
					continue;
				}
				$translations[ $original ] = $translated;
			}
		}
		return $translations;
	}

	private static function collect_candidates( $value, &$candidates ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				self::collect_candidates( $child, $candidates );
			}
			return;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return;
		}
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( strlen( $decoded ) <= 10000 ) {
			$candidates[] = $decoded;
			$trimmed = trim( $decoded );
			if ( '' !== $trimmed ) {
				$candidates[] = $trimmed;
			}
		}
		if ( preg_match_all( '/(^|>)([^<>]+)(?=<|$)/u', $value, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = $match[2];
				$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( '' !== trim( $text ) && strlen( $text ) <= 10000 ) {
					$candidates[] = $text;
					$candidates[] = trim( $text );
				}
			}
		}
	}

	private static function translate_node( $value, $dictionary, &$changed ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$value[ $key ] = self::translate_node( $child, $dictionary, $changed );
			}
			return $value;
		}
		return is_string( $value ) ? self::translate_value( $value, $dictionary, $changed ) : $value;
	}

	private static function translate_value( $value, $dictionary, &$changed ) {
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$translation = self::lookup_translation( $decoded, $dictionary );
		if ( null !== $translation ) {
			$changed = true;
			// A text replaced whole stays text: markup only where the original had it (checked again before saving).
			return false === strpos( $decoded, '<' ) ? str_replace( array( '<', '>' ), '', wp_strip_all_tags( $translation ) ) : $translation;
		}
		if ( false === strpos( $value, '<' ) ) {
			return $value;
		}
		$value = preg_replace_callback(
			'/\\b(alt|title|aria-label|placeholder|value)(\\s*=\\s*)(["\\\'])(.*?)\\3/isu',
			static function ( $match ) use ( $dictionary, &$changed ) {
				$decoded_value = html_entity_decode( $match[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$replacement = self::lookup_translation( $decoded_value, $dictionary );
				if ( null === $replacement ) {
					return $match[0];
				}
				$changed = true;
				return $match[1] . $match[2] . $match[3] . htmlspecialchars( $replacement, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) . $match[3];
			},
			$value
		);
		$value = preg_replace_callback(
			'/(^|>)([^<>]+)(?=<|$)/u',
			static function ( $match ) use ( $dictionary, &$changed ) {
				$text = html_entity_decode( $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$replacement = self::lookup_translation( $text, $dictionary );
				if ( null === $replacement ) {
					return $match[0];
				}
				$leading = '';
				$trailing = '';
				if ( preg_match( '/^\\s*/u', $text, $space ) ) {
					$leading = $space[0];
				}
				if ( preg_match( '/\\s*$/u', $text, $space ) ) {
					$trailing = $space[0];
				}
				$changed = true;
				return $match[1] . $leading . htmlspecialchars( $replacement, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' ) . $trailing;
			},
			$value
		);
		return $value;
	}

	private static function lookup_translation( $text, $dictionary ) {
		if ( isset( $dictionary[ $text ] ) ) {
			return $dictionary[ $text ];
		}
		$trimmed = trim( $text );
		if ( $trimmed !== $text && isset( $dictionary[ $trimmed ] ) ) {
			$leading = '';
			$trailing = '';
			if ( preg_match( '/^\\s*/u', $text, $space ) ) {
				$leading = $space[0];
			}
			if ( preg_match( '/\\s*$/u', $text, $space ) ) {
				$trailing = $space[0];
			}
			return $leading . $dictionary[ $trimmed ] . $trailing;
		}
		return null;
	}

	private static function posts_after( $cursor, $limit ) {
		global $wpdb;
		$types = get_post_types( array( 'show_ui' => true ), 'names' );
		$types = array_values(
			array_filter(
				$types,
				static function ( $type ) {
					return ! in_array( $type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) && post_type_supports( $type, 'editor' );
				}
			)
		);
		$types = apply_filters( 'lingua_bridge_post_types', $types );
		$types = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $types ),
				static function ( $type ) {
					return post_type_exists( $type ) && post_type_supports( $type, 'editor' );
				}
			)
		);
		if ( empty( $types ) ) {
			return array();
		}
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$type_placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$query = "SELECT ID FROM `$wpdb->posts` WHERE ID > %d AND post_type IN ($type_placeholders) AND post_status IN ($status_placeholders) ORDER BY ID ASC LIMIT %d";
		$values = array_merge( array( absint( $cursor ) ), $types, $statuses, array( absint( $limit ) ) );
		return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $query, $values ) ) );
	}

	private static function map_language( $source_code ) {
		$source_code = strtolower( str_replace( '-', '_', sanitize_text_field( (string) $source_code ) ) );
		if ( '' === $source_code ) {
			return '';
		}
		$languages = Languages::all();
		$exact = array();
		foreach ( $languages as $code => $label ) {
			if ( strtolower( str_replace( '-', '_', $code ) ) === $source_code ) {
				$exact[] = $code;
			}
		}
		if ( 1 === count( $exact ) ) {
			return $exact[0];
		}
		$primary = strtok( $source_code, '_' );
		$matches = array();
		foreach ( $languages as $code => $label ) {
			if ( strtok( strtolower( str_replace( '-', '_', $code ) ), '_' ) === $primary ) {
				$matches[] = $code;
			}
		}
		return 1 === count( $matches ) ? $matches[0] : '';
	}

	/**
	 * C32: after the contents, the relations of the terms (from Polylang or WPML into the table of the terms) and the
	 * menus each language had in each place of the theme. At most 2000 terms per taxonomy.
	 *
	 * @return array{terms: int, menus: int}
	 */
	public static function import_terms_and_menus( $source ) {
		$out = array( 'terms' => 0, 'menus' => 0 );
		foreach ( Terms::taxonomies() as $taxonomy ) {
			$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 2000, 'lang' => '' ) );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$mapping = 'polylang' === $source ? self::polylang_term_group( $term ) : self::wpml_term_group( $term );
				if ( $mapping && 'imported' === Terms::link_group( $mapping ) ) {
					++$out['terms'];
				}
			}
		}
		$menus = 'polylang' === $source ? self::polylang_menus() : self::wpml_menus();
		if ( $menus ) {
			$settings = Languages::settings();
			$settings['language_menus'] = $menus;
			update_option( Languages::OPTION, $settings );
			$out['menus'] = array_sum( array_map( 'count', $menus ) );
		}
		return $out;
	}

	/** The group of a term in Polylang, once (from its smallest id): language => term id. */
	private static function polylang_term_group( $term ) {
		if ( ! function_exists( 'pll_get_term_translations' ) ) {
			return array();
		}
		$translations = array_map( 'absint', (array) \pll_get_term_translations( $term->term_id ) );
		if ( count( $translations ) < 2 || absint( $term->term_id ) !== min( $translations ) ) {
			return array();
		}
		$mapping = array();
		foreach ( $translations as $code => $term_id ) {
			$language = self::map_language( $code );
			if ( $language ) {
				$mapping[ $language ] = $term_id;
			}
		}
		return $mapping;
	}

	/** The group of a term in WPML (its elements are term_taxonomy ids, `tax_<taxonomy>`), once. */
	private static function wpml_term_group( $term, $once = true ) {
		$type = 'tax_' . $term->taxonomy;
		$trid = apply_filters( 'wpml_element_trid', null, absint( $term->term_taxonomy_id ), $type );
		$translations = $trid ? apply_filters( 'wpml_get_element_translations', null, absint( $trid ), $type ) : null;
		if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
			return array();
		}
		$mapping = array();
		foreach ( $translations as $translation ) {
			$language = is_object( $translation ) ? self::map_language( $translation->language_code ?? '' ) : '';
			$found = $language ? get_term_by( 'term_taxonomy_id', absint( $translation->element_id ?? 0 ), $term->taxonomy ) : null;
			if ( $found instanceof \WP_Term ) {
				$mapping[ $language ] = absint( $found->term_id );
			}
		}
		return $mapping && ( ! $once || absint( $term->term_id ) === min( $mapping ) ) ? $mapping : array();
	}

	/** Polylang's menus per place of the theme and language: language => place => menu, without the original's. */
	private static function polylang_menus() {
		$options = get_option( 'polylang' );
		$places = is_array( $options ) ? (array) ( $options['nav_menus'][ get_stylesheet() ] ?? array() ) : array();
		$out = array();
		foreach ( $places as $place => $per_language ) {
			foreach ( (array) $per_language as $code => $menu ) {
				$language = self::map_language( $code );
				if ( $language && Languages::default_code() !== $language && is_nav_menu( absint( $menu ) ) ) {
					$out[ $language ][ sanitize_key( (string) $place ) ] = absint( $menu );
				}
			}
		}
		return $out;
	}

	/** WPML's: the translations of the menu of each place (menus are terms of `nav_menu`). */
	private static function wpml_menus() {
		$out = array();
		foreach ( (array) get_nav_menu_locations() as $place => $menu ) {
			$term = $menu ? get_term( absint( $menu ), 'nav_menu' ) : null;
			$group = $term instanceof \WP_Term ? self::wpml_term_group( $term, false ) : array();
			foreach ( $group as $language => $menu_id ) {
				if ( Languages::default_code() !== $language ) {
					$out[ $language ][ sanitize_key( (string) $place ) ] = absint( $menu_id );
				}
			}
		}
		return $out;
	}

	private static function empty_counts() {
		return array(
			'terms'       => 0,
			'menus'       => 0,
			'groups_seen' => 0,
			'posts_seen'  => 0,
			'imported'    => 0,
			'drafts'      => 0,
			'already'     => 0,
			'skipped'     => 0,
			'conflicts'   => 0,
		);
	}
}
