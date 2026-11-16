<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Categories, tags and the terms of other public taxonomies in every language (N1 of the 1.5.10 verification).
 * As for contents, a translated term is a normal WordPress term of the same taxonomy, with its own name, slug and
 * description: the plugin only adds the relation, in its own table (term ids are not post ids). Turned off, the
 * terms stay as they are.
 *
 * - A person creates a term's translation on the term's screen (terms have no draft: no machine writes them).
 * - The translations of the contents in that language get the translated term instead of the original's, at once
 *   and for every translation made later.
 * - The archive of a translated term lives under the language prefix (/fr/category/<slug-francese>/); the original
 *   term under a prefix leads to its translation; the switchers and hreflang lead to the other versions.
 */
final class Terms {
	const TABLE = 'lingua_bridge_term_translations';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** The relations table of the terms (Storage::install() calls it with the others). */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE $table (
			term_id bigint(20) unsigned NOT NULL,
			group_uuid char(36) NOT NULL,
			language varchar(35) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			taxonomy varchar(32) NOT NULL,
			PRIMARY KEY  (term_id),
			UNIQUE KEY group_language (group_uuid,language),
			KEY source_id (source_id)
		) {$wpdb->get_charset_collate()};"
		);
	}

	public static function available() {
		static $available = null;
		if ( null === $available ) {
			global $wpdb;
			$available = self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
		}
		return $available;
	}

	public static function init() {
		add_action( 'lingua_bridge_translation_created', array( __CLASS__, 'map_post_terms' ), 20, 1 );
		add_action( 'delete_term', array( __CLASS__, 'forget' ), 10, 1 );
		add_filter( 'term_link', array( __CLASS__, 'term_link' ), 20, 2 );
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'admin_hooks' ) );
			add_action( 'admin_post_lingua_bridge_term_proposals', array( __CLASS__, 'handle_proposals' ) );
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'redirect' ), 2 );
		add_action( 'wp_head', array( __CLASS__, 'alternate_links' ), 5 );
	}

	/**
	 * The taxonomies whose terms are translated: public ones with a screen, used by a translatable type. Never
	 * menus, link categories or post formats.
	 *
	 * @return list<string>
	 */
	public static function taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' ) as $name => $taxonomy ) {
			if ( in_array( $name, array( 'nav_menu', 'link_category', 'post_format' ), true ) ) {
				continue;
			}
			if ( array_intersect( (array) $taxonomy->object_type, Translations::translatable_types() ) ) {
				$out[] = $name;
			}
		}
		// C20: WooCommerce's global attributes (pa_colore) are terms too, even when not public: a layered filter in
		// French shows «Rouge».
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			foreach ( wc_get_attribute_taxonomy_names() as $name ) {
				if ( taxonomy_exists( $name ) && ! in_array( $name, $out, true ) ) {
					$out[] = $name;
				}
			}
		}
		return (array) apply_filters( 'lingua_bridge_term_taxonomies', $out );
	}

	/** @return array{term_id: string, group_uuid: string, language: string, source_id: string, taxonomy: string}|null */
	public static function find( $term_id ) {
		global $wpdb;
		$term_id = absint( $term_id );
		if ( ! $term_id || ! self::available() ) {
			return null;
		}
		$cached = wp_cache_get( 'term_' . $term_id, 'lingua_bridge' );
		if ( false !== $cached ) {
			return $cached;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE term_id = %d', $term_id ), ARRAY_A );
		wp_cache_set( 'term_' . $term_id, $row, 'lingua_bridge', 300 );
		return $row;
	}

	/** @return list<array> The members of a group, by language. */
	public static function group( $group_uuid ) {
		global $wpdb;
		if ( '' === (string) $group_uuid || ! self::available() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE group_uuid = %s ORDER BY language ASC', $group_uuid ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** The language of a term: its row's, or the original's when it has none. */
	public static function language_of( $term_id ) {
		$row = self::find( $term_id );
		return $row ? (string) $row['language'] : Languages::default_code();
	}

	/** The term in `$language` of the group of `$term_id` (itself when it is of that language), or 0. */
	public static function translation( $term_id, $language ) {
		$term_id = absint( $term_id );
		$row = self::find( $term_id );
		if ( ! $row ) {
			return Languages::default_code() === $language ? $term_id : 0;
		}
		if ( $language === $row['language'] ) {
			return $term_id;
		}
		foreach ( self::group( $row['group_uuid'] ) as $member ) {
			if ( $language === $member['language'] ) {
				return absint( $member['term_id'] );
			}
		}
		return 0;
	}

	/**
	 * Creates the translation of a term: a new term of the same taxonomy, with its parent's translation as parent
	 * when there is one, linked in the group of the original. The translations of contents in that language get it
	 * in place of the original term. Only for whoever may edit the term and manage the taxonomy.
	 *
	 * @return int|\WP_Error The new term's id.
	 */
	public static function create( $term_id, $language, $name, $slug = '', $description = '' ) {
		global $wpdb;
		$term = get_term( absint( $term_id ) );
		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::taxonomies(), true ) ) {
			return new \WP_Error( 'lingua_term_missing', __( 'Termine non trovato o non traducibile.', 'lingua-bridge' ) );
		}
		$taxonomy = get_taxonomy( $term->taxonomy );
		if ( ! current_user_can( 'edit_term', $term->term_id ) || ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
			return new \WP_Error( 'lingua_forbidden', __( 'Non hai i permessi per tradurre questo termine.', 'lingua-bridge' ) );
		}
		$language = sanitize_key( (string) $language );
		if ( ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			return new \WP_Error( 'lingua_invalid_language', __( 'La lingua selezionata non è configurata.', 'lingua-bridge' ) );
		}
		if ( ! self::available() ) {
			return new \WP_Error( 'lingua_storage_error', __( 'Relazione della lingua non disponibile.', 'lingua-bridge' ) );
		}
		$row = self::find( $term->term_id );
		if ( $row && Languages::default_code() !== $row['language'] ) {
			return new \WP_Error( 'lingua_term_not_original', __( 'Si traduce il termine originale, non una sua traduzione.', 'lingua-bridge' ) );
		}
		if ( self::translation( $term->term_id, $language ) ) {
			return new \WP_Error( 'lingua_exists', __( 'Esiste già una traduzione in questa lingua.', 'lingua-bridge' ) );
		}
		$name = sanitize_text_field( (string) $name );
		if ( '' === trim( $name ) ) {
			return new \WP_Error( 'lingua_invalid_translation', __( 'Scrivi il nome del termine tradotto.', 'lingua-bridge' ) );
		}
		$parent = $term->parent ? self::translation( $term->parent, $language ) : 0;
		$created = wp_insert_term(
			$name,
			$term->taxonomy,
			array(
				'slug'        => sanitize_title( '' !== trim( (string) $slug ) ? (string) $slug : $name ),
				'description' => wp_kses_post( (string) $description ),
				'parent'      => $parent,
			)
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$new_id = absint( $created['term_id'] );
		$group = $row ? $row['group_uuid'] : wp_generate_uuid4();
		if ( ! $row ) {
			$wpdb->insert( self::table(), array( 'term_id' => $term->term_id, 'group_uuid' => $group, 'language' => Languages::default_code(), 'source_id' => $term->term_id, 'taxonomy' => $term->taxonomy ), array( '%d', '%s', '%s', '%d', '%s' ) );
		}
		$inserted = $wpdb->insert( self::table(), array( 'term_id' => $new_id, 'group_uuid' => $group, 'language' => $language, 'source_id' => $term->term_id, 'taxonomy' => $term->taxonomy ), array( '%d', '%s', '%s', '%d', '%s' ) );
		wp_cache_delete( 'term_' . $term->term_id, 'lingua_bridge' );
		wp_cache_delete( 'term_' . $new_id, 'lingua_bridge' );
		if ( ! $inserted ) {
			wp_delete_term( $new_id, $term->taxonomy );
			return new \WP_Error( 'lingua_relation_error', __( 'Il collegamento non è stato salvato.', 'lingua-bridge' ) );
		}
		self::reassign( $term, $new_id, $language );
		Storage::content_changed();
		return $new_id;
	}

	/**
	 * The translations of contents in `$language` that have the original term get its translation instead, in
	 * two statements (a site has thousands of them), then the counts and caches of the two terms.
	 */
	private static function reassign( \WP_Term $source, $target_id, $language ) {
		global $wpdb;
		$target = get_term( absint( $target_id ), $source->taxonomy );
		if ( ! $target instanceof \WP_Term || ! Storage::is_available() ) {
			return;
		}
		$relations = Storage::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$objects = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN $relations t ON t.object_id = tr.object_id WHERE tr.term_taxonomy_id = %d AND t.language = %s AND t.object_id <> t.source_id", $source->term_taxonomy_id, $language ) ) );
		if ( $objects ) {
			$in = implode( ',', $objects );
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) SELECT object_id, %d, term_order FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id IN ($in)", $target->term_taxonomy_id, $source->term_taxonomy_id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d AND object_id IN ($in)", $source->term_taxonomy_id ) );
		}
		// phpcs:enable
		wp_update_term_count_now( array( $source->term_taxonomy_id, $target->term_taxonomy_id ), $source->taxonomy );
		clean_term_cache( array( $source->term_id, $target->term_id ), $source->taxonomy );
		foreach ( $objects as $object ) {
			clean_object_term_cache( $object, get_post_type( $object ) );
		}
	}

	/** A translation just created: its terms (copied from the original) become their translations in its language. */
	public static function map_post_terms( $post_id ) {
		$row = Storage::find( absint( $post_id ) );
		if ( ! $row || Languages::default_code() === $row['language'] || ! self::available() ) {
			return;
		}
		foreach ( array_intersect( get_object_taxonomies( get_post_type( $post_id ) ), self::taxonomies() ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $ids ) || ! $ids ) {
				continue;
			}
			$mapped = array();
			foreach ( $ids as $id ) {
				$translated = self::translation( $id, $row['language'] );
				$mapped[] = $translated ? $translated : absint( $id );
			}
			if ( $mapped !== array_map( 'absint', $ids ) ) {
				wp_set_object_terms( $post_id, array_values( array_unique( $mapped ) ), $taxonomy, false );
			}
		}
	}

	/** A deleted term leaves its group; when the original goes, the group goes. */
	public static function forget( $term_id ) {
		global $wpdb;
		$row = self::find( $term_id );
		if ( ! $row ) {
			return;
		}
		if ( absint( $row['source_id'] ) === absint( $term_id ) ) {
			$wpdb->delete( self::table(), array( 'group_uuid' => $row['group_uuid'] ), array( '%s' ) );
		} else {
			$wpdb->delete( self::table(), array( 'term_id' => absint( $term_id ) ), array( '%d' ) );
		}
		wp_cache_delete( 'term_' . absint( $term_id ), 'lingua_bridge' );
		Storage::content_changed();
	}

	/** The address of a translated term is under its language's prefix. */
	public static function term_link( $url, $term ) {
		if ( ! $term instanceof \WP_Term || ! Routing::enabled() ) {
			return $url;
		}
		$language = self::language_of( $term->term_id );
		return Languages::default_code() === $language ? $url : Routing::language_url( $url, $language );
	}

	/**
	 * A term archive in the wrong place goes where it is: the original term under a prefix to its translation (when
	 * there is one), a translated term without its prefix to its address (301).
	 */
	public static function redirect() {
		if ( ! Routing::enabled() || ! ( is_category() || is_tag() || is_tax() ) ) {
			return;
		}
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || ! in_array( $term->taxonomy, self::taxonomies(), true ) ) {
			return;
		}
		$requested = Routing::request_language();
		$own = self::language_of( $term->term_id );
		$target = 0;
		if ( '' !== $requested && $requested !== $own ) {
			$target = self::translation( $term->term_id, $requested );
		} elseif ( '' === $requested && Languages::default_code() !== $own ) {
			$target = $term->term_id;
		}
		if ( $target ) {
			$link = get_term_link( $target, $term->taxonomy );
			if ( is_string( $link ) && untrailingslashit( $link ) !== untrailingslashit( home_url( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ) ) ) {
				wp_safe_redirect( $link, 301, 'Lingua Bridge' );
				exit;
			}
		}
	}

	/**
	 * The versions of a term archive in each language (switchers, hreflang): language => address.
	 *
	 * @return array<string, string>
	 */
	public static function versions( $term ) {
		if ( ! $term instanceof \WP_Term || ! Routing::enabled() ) {
			return array();
		}
		$row = self::find( $term->term_id );
		if ( ! $row ) {
			return array();
		}
		$out = array();
		foreach ( self::group( $row['group_uuid'] ) as $member ) {
			$link = get_term_link( absint( $member['term_id'] ), $term->taxonomy );
			if ( is_string( $link ) ) {
				$out[ $member['language'] ] = $link;
			}
		}
		return count( $out ) > 1 ? $out : array();
	}

	/**
	 * C3: the versions of a translated term's archive for this page (hreflang, switchers), in the order of the
	 * settings: each language's own term, else the original's list under its prefix when that list has content.
	 *
	 * @return array<string, string>
	 */
	public static function page_versions( $term ) {
		$versions = self::versions( $term );
		$row = $versions ? self::find( $term->term_id ) : null;
		$source = $row ? get_term( absint( $row['source_id'] ), $term->taxonomy ) : null;
		if ( ! $source instanceof \WP_Term ) {
			return $versions;
		}
		$link = get_term_link( $source );
		$query = array( 'tax_query' => array( array( 'taxonomy' => $source->taxonomy, 'field' => 'term_id', 'terms' => array( $source->term_id ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$ordered = array();
		foreach ( array_keys( Languages::all() ) as $language ) {
			if ( isset( $versions[ $language ] ) ) {
				$ordered[ $language ] = $versions[ $language ];
			} elseif ( is_string( $link ) && Routing::list_has_content( $language, $query ) ) {
				$ordered[ $language ] = Routing::language_url( $link, $language );
			}
		}
		return $ordered;
	}

	public static function alternate_links() {
		$term = get_queried_object();
		$versions = self::page_versions( $term );
		foreach ( $versions as $language => $link ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $language ) . '" href="' . esc_url( $link ) . '" />' . "\n";
		}
		// C3: as for contents, x-default is the original term's archive.
		if ( '' !== Languages::x_default( $versions ) ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( Languages::x_default( $versions ) ) . '" />' . "\n";
		}
	}

	// ------------------------------------------------------------------------------------------------- admin

	/**
	 * C32: links terms that already exist (language => term id, the original's language among them) as one group,
	 * as an importer finds them. 'imported' when linked, 'already' when they are linked so already, 'conflicts' when
	 * one of them is linked elsewhere, 'skipped' when the mapping is not a group (another taxonomy, no original).
	 */
	public static function link_group( $mapping ) {
		global $wpdb;
		$default = Languages::default_code();
		$mapping = array_map( 'absint', (array) $mapping );
		if ( ! self::available() || empty( $mapping[ $default ] ) || count( $mapping ) < 2 || in_array( 0, $mapping, true ) ) {
			return 'skipped';
		}
		$source = get_term( $mapping[ $default ] );
		if ( ! $source instanceof \WP_Term || ! in_array( $source->taxonomy, self::taxonomies(), true ) ) {
			return 'skipped';
		}
		$group = '';
		$found = 0;
		foreach ( $mapping as $language => $term_id ) {
			$term = get_term( $term_id );
			if ( ! $term instanceof \WP_Term || $term->taxonomy !== $source->taxonomy ) {
				return 'skipped';
			}
			$row = self::find( $term_id );
			if ( $row ) {
				++$found;
				if ( $row['language'] !== $language || ( '' !== $group && $row['group_uuid'] !== $group ) ) {
					return 'conflicts';
				}
				$group = $row['group_uuid'];
			}
		}
		if ( $found ) {
			return count( $mapping ) === $found ? 'already' : 'conflicts';
		}
		$group = wp_generate_uuid4();
		foreach ( $mapping as $language => $term_id ) {
			$wpdb->insert( self::table(), array( 'term_id' => $term_id, 'group_uuid' => $group, 'language' => $language, 'source_id' => $source->term_id, 'taxonomy' => $source->taxonomy ), array( '%d', '%s', '%s', '%d', '%s' ) );
		}
		foreach ( $mapping as $term_id ) {
			wp_cache_delete( 'term_' . $term_id, 'lingua_bridge' );
		}
		Storage::content_changed();
		return 'imported';
	}

	/** C29: term id => (language => array{name, slug, from}): what a machine proposes, never a term by itself. */
	const PROPOSALS_META = '_lingua_bridge_proposals';

	/**
	 * C29: proposals of name and slug for the terms of `$taxonomy` that have no version in `$language` yet: from the
	 * memory first, then from the automatic service. A proposal is no term: a person accepts it (accept()), so the
	 * rule «machines write only drafts» holds for terms too. At most 100 terms. Returns how many were proposed.
	 */
	public static function propose( $taxonomy, $language ) {
		if ( ! in_array( $taxonomy, self::taxonomies(), true ) || ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language || ! current_user_can( get_taxonomy( $taxonomy )->cap->manage_terms ) ) {
			return 0;
		}
		$todo = array();
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 300 ) ) as $term ) {
			if ( Languages::default_code() === self::language_of( $term->term_id ) && ! self::translation( $term->term_id, $language ) ) {
				$todo[ $term->term_id ] = $term->name;
			}
			if ( count( $todo ) >= 100 ) {
				break;
			}
		}
		if ( empty( $todo ) ) {
			return 0;
		}
		$known = Memory::lookup( array_values( $todo ), $language );
		$names = array();
		$from = array();
		$known_of = static fn( $name ) => $known[ Strings::normalize( $name ) ] ?? null;
		$ask = array_values( array_unique( array_filter( $todo, static fn( $name ) => null === $known_of( $name ) ) ) );
		$service = $ask && MachineTranslation::configured() && MachineTranslation::can_use() ? MachineTranslation::translate_texts( $ask, Languages::default_code(), $language ) : array();
		$service = is_wp_error( $service ) ? array() : array_combine( array_slice( $ask, 0, count( $service ) ), $service );
		foreach ( $todo as $term_id => $name ) {
			if ( null !== $known_of( $name ) ) {
				$names[ $term_id ] = (string) $known_of( $name );
				$from[ $term_id ] = 'memory';
			} elseif ( isset( $service[ $name ] ) && '' !== trim( (string) $service[ $name ] ) ) {
				$names[ $term_id ] = (string) $service[ $name ];
				$from[ $term_id ] = 'service';
			}
		}
		foreach ( $names as $term_id => $name ) {
			self::save_proposal( $term_id, $language, $name, $from[ $term_id ] );
		}
		return count( $names );
	}

	/** One proposal of name (and slug from it) for a term: replaces the earlier one of that language. */
	private static function save_proposal( $term_id, $language, $name, $from ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			return false;
		}
		$proposals = get_term_meta( $term_id, self::PROPOSALS_META, true );
		$proposals = is_array( $proposals ) ? $proposals : array();
		$proposals[ $language ] = array( 'name' => $name, 'slug' => sanitize_title( $name ), 'from' => (string) $from );
		return (bool) update_term_meta( $term_id, self::PROPOSALS_META, $proposals );
	}

	/**
	 * C29: the terms of `$taxonomy` without a version in `$language`, as an XLIFF 1.2 file (one unit `term:<id>` each)
	 * for a translator; what comes back becomes proposals (import_xliff()), never terms.
	 */
	public static function export_xliff( $taxonomy, $language ) {
		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		$xliff = $doc->createElementNS( Xliff::NS, 'xliff' );
		$xliff->setAttribute( 'version', '1.2' );
		$doc->appendChild( $xliff );
		$file = $doc->createElementNS( Xliff::NS, 'file' );
		$file->setAttribute( 'original', 'lingua-bridge:terms:' . sanitize_key( $taxonomy ) );
		$file->setAttribute( 'source-language', Languages::default_code() );
		$file->setAttribute( 'target-language', $language );
		$file->setAttribute( 'datatype', 'plaintext' );
		$body = $doc->createElementNS( Xliff::NS, 'body' );
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1000 ) ) as $term ) {
			if ( Languages::default_code() !== self::language_of( $term->term_id ) || self::translation( $term->term_id, $language ) ) {
				continue;
			}
			$tu = $doc->createElementNS( Xliff::NS, 'trans-unit' );
			$tu->setAttribute( 'id', 'term:' . $term->term_id );
			$tu->setAttribute( 'resname', 'term:' . $taxonomy );
			$source = $doc->createElementNS( Xliff::NS, 'source' );
			$source->appendChild( $doc->createTextNode( $term->name ) );
			$tu->appendChild( $source );
			$body->appendChild( $tu );
		}
		$file->appendChild( $body );
		$xliff->appendChild( $file );
		return $doc->saveXML();
	}

	/** The `<file original="lingua-bridge:terms:…">` of an XLIFF file: its targets become proposals. */
	public static function import_xliff( \DOMElement $file ) {
		$result = array( 'post' => 0, 'language' => '', 'ok' => false, 'message' => '', 'draft' => 0, 'untranslated' => 0, 'same' => 0 );
		preg_match( '/\Alingua-bridge:terms:([a-z0-9_-]+)\z/', $file->getAttribute( 'original' ), $m );
		$taxonomy = (string) ( $m[1] ?? '' );
		$language = sanitize_key( $file->getAttribute( 'target-language' ) );
		$result['language'] = $language;
		$object = in_array( $taxonomy, self::taxonomies(), true ) ? get_taxonomy( $taxonomy ) : null;
		if ( ! $object || ! current_user_can( $object->cap->manage_terms ) || ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			$result['message'] = __( 'Termini di una tassonomia o di una lingua che non puoi tradurre.', 'lingua-bridge' );
			return $result;
		}
		$count = 0;
		foreach ( $file->getElementsByTagNameNS( Xliff::NS, 'trans-unit' ) as $tu ) {
			$target = $tu->getElementsByTagNameNS( Xliff::NS, 'target' )->item( 0 );
			$term_id = preg_match( '/\Aterm:(\d+)\z/', $tu->getAttribute( 'id' ), $id ) ? absint( $id[1] ) : 0;
			$term = $term_id ? get_term( $term_id, $taxonomy ) : null;
			if ( $target && $term instanceof \WP_Term && ! self::translation( $term_id, $language ) && self::save_proposal( $term_id, $language, wp_strip_all_tags( (string) $target->textContent ), 'xliff' ) ) {
				++$count;
			}
		}
		$result['ok'] = true;
		$result['untranslated'] = 0;
		/* translators: %d: terms. */
		$result['message'] = sprintf( __( 'Proposte di termini dal file: %d. Accettale nella schermata dei termini.', 'lingua-bridge' ), $count );
		return $result;
	}

	/** C29: «Accetta le proposte»: the proposed terms of `$language` become terms, as if a person wrote them. */
	public static function accept( $taxonomy, $language ) {
		$made = 0;
		foreach ( get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 300, 'meta_key' => self::PROPOSALS_META ) ) as $term ) { // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$proposals = get_term_meta( $term->term_id, self::PROPOSALS_META, true );
			$proposal = is_array( $proposals ) ? ( $proposals[ $language ] ?? null ) : null;
			if ( ! is_array( $proposal ) ) {
				continue;
			}
			$created = self::translation( $term->term_id, $language ) ? 0 : self::create( $term->term_id, $language, (string) $proposal['name'], (string) $proposal['slug'] );
			if ( ! is_wp_error( $created ) ) {
				unset( $proposals[ $language ] );
				$proposals ? update_term_meta( $term->term_id, self::PROPOSALS_META, $proposals ) : delete_term_meta( $term->term_id, self::PROPOSALS_META );
				$made += $created ? 1 : 0;
			}
		}
		return $made;
	}

	/** Under the list of the terms: propose with the service, or accept, for one language. */
	public static function render_proposals( $taxonomy ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="lb-term-proposals"><input type="hidden" name="action" value="lingua_bridge_term_proposals"><input type="hidden" name="taxonomy" value="' . esc_attr( (string) $taxonomy ) . '">';
		wp_nonce_field( 'lingua_bridge_term_proposals' );
		echo '<h2>' . esc_html__( 'Lingue', 'lingua-bridge' ) . '</h2><p><label for="lb-proposals-language">' . esc_html__( 'Lingua', 'lingua-bridge' ) . '</label> <select id="lb-proposals-language" name="language">';
		foreach ( Languages::all() as $code => $label ) {
			if ( Languages::default_code() !== $code ) {
				echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
			}
		}
		echo '</select> <button class="button" name="op" value="propose">' . esc_html__( 'Proponi le traduzioni', 'lingua-bridge' ) . '</button> <button class="button" name="op" value="xliff">' . esc_html__( 'Scarica XLIFF', 'lingua-bridge' ) . '</button> <button class="button button-primary" name="op" value="accept">' . esc_html__( 'Accetta le proposte', 'lingua-bridge' ) . '</button></p><p class="description">' . esc_html__( 'Le proposte vengono dalla memoria e dal servizio automatico, e compaiono nella schermata di ogni termine. Diventano termini solo quando le accetti.', 'lingua-bridge' ) . '</p></form>';
	}

	/** On the list of the terms: the outcome of «Proponi» or «Accetta». */
	public static function proposals_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'edit-tags' === $screen->base && in_array( $screen->taxonomy, self::taxonomies(), true ) ) {
			Admin::flash_notices();
		}
	}

	public static function handle_proposals() {
		check_admin_referer( 'lingua_bridge_term_proposals' );
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		$object = taxonomy_exists( $taxonomy ) ? get_taxonomy( $taxonomy ) : null;
		if ( ! $object || ! current_user_can( $object->cap->manage_terms ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		if ( 'xliff' === $op && isset( Languages::all()[ $language ] ) ) {
			nocache_headers();
			header( 'Content-Type: application/xliff+xml; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'termini-' . $taxonomy . '-' . $language . '.xlf' ) . '"' );
			echo self::export_xliff( $taxonomy, $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an XML file built with DOMDocument.
			exit;
		}
		$accept = 'accept' === $op;
		$count = $accept ? self::accept( $taxonomy, $language ) : self::propose( $taxonomy, $language );
		/* translators: %d: terms. */
		$message = sprintf( $accept ? __( 'Termini creati dalle proposte: %d.', 'lingua-bridge' ) : __( 'Proposte nuove: %d. Le trovi nella schermata di ogni termine.', 'lingua-bridge' ), $count );
		wp_safe_redirect( add_query_arg( array( 'taxonomy' => $taxonomy, 'lb_message' => Admin::stash_message( $message ) ), admin_url( 'edit-tags.php' ) ) );
		exit;
	}

	public static function admin_hooks() {
		add_action( 'admin_notices', array( __CLASS__, 'proposals_notice' ) );
		foreach ( self::taxonomies() as $taxonomy ) {
			add_action( 'after-' . $taxonomy . '-table', array( __CLASS__, 'render_proposals' ) );
			add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'render_fields' ), 20, 2 );
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'save_fields' ), 20, 1 );
			add_filter( 'manage_edit-' . $taxonomy . '_columns', array( __CLASS__, 'add_column' ) );
			add_filter( 'manage_' . $taxonomy . '_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		}
	}

	/** On the term's screen: its language, and for the original one row per language (a link, or name and slug). */
	public static function render_fields( $term ) {
		if ( ! $term instanceof \WP_Term ) {
			return;
		}
		$own = self::language_of( $term->term_id );
		echo '<tr class="form-field lb-term-languages"><th scope="row">' . esc_html__( 'Lingue', 'lingua-bridge' ) . '</th><td>';
		if ( Languages::default_code() !== $own ) {
			$row = self::find( $term->term_id );
			$source = $row ? get_term( absint( $row['source_id'] ) ) : null;
			/* translators: 1: language name, 2: the original term's name. */
			echo '<p>' . esc_html( sprintf( __( 'Questo termine è la versione %1$s di «%2$s».', 'lingua-bridge' ), Languages::label( $own ), $source instanceof \WP_Term ? $source->name : '' ) ) . '</p></td></tr>';
			return;
		}
		wp_nonce_field( 'lingua_bridge_term_' . $term->term_id, 'lingua_bridge_term_nonce' );
		foreach ( Languages::all() as $code => $label ) {
			if ( Languages::default_code() === $code ) {
				continue;
			}
			$translation = self::translation( $term->term_id, $code );
			$other = $translation ? get_term( $translation ) : null;
			echo '<p><strong>' . esc_html( $label ) . '</strong> ';
			if ( $other instanceof \WP_Term ) {
				echo '<a href="' . esc_url( (string) get_edit_term_link( $other->term_id, $other->taxonomy ) ) . '">' . esc_html( $other->name ) . '</a> <code>' . esc_html( $other->slug ) . '</code>';
			} else {
				$id = 'lb-term-' . sanitize_html_class( $code );
				// C29: a proposal of a machine fills the fields, for a person to check and save.
				$proposals = get_term_meta( $term->term_id, self::PROPOSALS_META, true );
				$proposal = is_array( $proposals ) && isset( $proposals[ $code ] ) ? $proposals[ $code ] : array();
				echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( sprintf( /* translators: %s: language name. */ __( 'Nome in %s', 'lingua-bridge' ), $label ) ) . '</label><input id="' . esc_attr( $id ) . '" type="text" name="lingua_bridge_term[' . esc_attr( $code ) . '][name]" placeholder="' . esc_attr__( 'Nome tradotto', 'lingua-bridge' ) . '" value="' . esc_attr( (string) ( $proposal['name'] ?? '' ) ) . '" style="width:auto"> ';
				echo '<label class="screen-reader-text" for="' . esc_attr( $id . '-slug' ) . '">' . esc_html__( 'Slug', 'lingua-bridge' ) . '</label><input id="' . esc_attr( $id . '-slug' ) . '" type="text" name="lingua_bridge_term[' . esc_attr( $code ) . '][slug]" placeholder="' . esc_attr__( 'slug (facoltativo)', 'lingua-bridge' ) . '" value="' . esc_attr( (string) ( $proposal['slug'] ?? '' ) ) . '" style="width:auto">' . ( $proposal ? ' <span class="description">' . esc_html__( '(proposta: controllala e salva)', 'lingua-bridge' ) . '</span>' : '' );
			}
			echo '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Il termine tradotto è un termine normale, con nome e slug suoi. Le traduzioni dei contenuti in quella lingua lo ricevono al posto di questo; il suo archivio è nella cartella della lingua.', 'lingua-bridge' ) . '</p></td></tr>';
	}

	/** The term's screen saved: a translation for each language given a name. */
	public static function save_fields( $term_id ) {
		if ( ! isset( $_POST['lingua_bridge_term_nonce'], $_POST['lingua_bridge_term'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lingua_bridge_term_nonce'] ) ), 'lingua_bridge_term_' . absint( $term_id ) ) || ! is_array( $_POST['lingua_bridge_term'] ) ) {
			return;
		}
		foreach ( wp_unslash( $_POST['lingua_bridge_term'] ) as $code => $values ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value goes through create().
			if ( is_array( $values ) && isset( $values['name'] ) && '' !== trim( (string) $values['name'] ) ) {
				self::create( $term_id, sanitize_key( (string) $code ), (string) $values['name'], (string) ( $values['slug'] ?? '' ) );
			}
		}
	}

	public static function add_column( $columns ) {
		$columns['lingua_bridge'] = __( 'Lingue', 'lingua-bridge' );
		return $columns;
	}

	/** The term list: the term's language, or for an original the codes of the languages it has. */
	public static function render_column( $content, $column, $term_id ) {
		if ( 'lingua_bridge' !== $column ) {
			return $content;
		}
		$own = self::language_of( $term_id );
		if ( Languages::default_code() !== $own ) {
			return esc_html( strtoupper( $own ) );
		}
		$codes = array( strtoupper( $own ) );
		foreach ( array_keys( Languages::all() ) as $code ) {
			if ( Languages::default_code() !== $code && self::translation( $term_id, $code ) ) {
				$codes[] = strtoupper( $code );
			}
		}
		return esc_html( implode( ' · ', $codes ) );
	}
}
