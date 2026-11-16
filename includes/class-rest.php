<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The language in WordPress's REST API (N5 of the 1.5.10 verification), for headless themes, apps and agents:
 * every translatable type gets a `lingua` field (its language, its group and its versions), and its list takes
 * `?lingua=fr` (one language) or `?lingua=originals`. Read only: the versions named are the ones the reader may
 * see (published, or editable by them).
 */
final class Rest {
	const FIELD = 'lingua';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		foreach ( Translations::translatable_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || empty( $object->show_in_rest ) ) {
				continue;
			}
			register_rest_field(
				$type,
				self::FIELD,
				array(
					'get_callback' => array( __CLASS__, 'field' ),
					'schema'       => array(
						'description' => __( 'La lingua del contenuto e le sue versioni (Lingua Bridge).', 'lingua-bridge' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit', 'embed' ),
						'readonly'    => true,
						'properties'  => array(
							'code'     => array( 'type' => 'string' ),
							'original' => array( 'type' => 'boolean' ),
							'group'    => array( 'type' => 'string' ),
							'versions' => array( 'type' => 'object' ),
						),
					),
				)
			);
			add_filter( 'rest_' . $type . '_query', array( __CLASS__, 'query' ), 10, 2 );
			add_filter( 'rest_' . $type . '_collection_params', array( __CLASS__, 'collection_params' ) );
		}
		add_filter( 'posts_clauses', array( __CLASS__, 'clauses' ), 10, 2 );
	}

	/**
	 * The field: {code, original, group, versions: {code: {id, link}}}. A version the reader can neither see
	 * published nor edit is not named.
	 */
	public static function field( $post ) {
		$id = absint( is_array( $post ) ? ( $post['id'] ?? 0 ) : 0 );
		$row = $id ? Storage::find( $id ) : null;
		$code = $row ? (string) $row['language'] : Languages::default_code();
		$versions = array();
		foreach ( $row ? Storage::group_rows( $row['group_uuid'] ) : array() as $member ) {
			$other = get_post( absint( $member['object_id'] ) );
			if ( $other && ( Frontend::online( $other ) || current_user_can( 'edit_post', $other->ID ) ) ) {
				$versions[ (string) $member['language'] ] = array( 'id' => absint( $other->ID ), 'link' => (string) get_permalink( $other ) );
			}
		}
		return array(
			'code'     => $code,
			'original' => ! $row || absint( $row['object_id'] ) === absint( $row['source_id'] ),
			'group'    => $row ? (string) $row['group_uuid'] : '',
			'versions' => (object) $versions,
		);
	}

	public static function collection_params( $params ) {
		$params[ self::FIELD ] = array(
			'description' => __( 'Solo i contenuti di una lingua (il suo codice), o solo gli originali (originals).', 'lingua-bridge' ),
			'type'        => 'string',
		);
		return $params;
	}

	/** ?lingua=… of a REST list becomes a query var the clauses read. */
	public static function query( $args, $request ) {
		$language = $request instanceof \WP_REST_Request ? sanitize_key( (string) $request->get_param( self::FIELD ) ) : '';
		if ( 'originals' === $language || isset( Languages::all()[ $language ] ) ) {
			$args['lb_rest_language'] = $language;
		}
		return $args;
	}

	/** The clause of ?lingua=…, through the indexed relations table (content never linked is in the original's). */
	public static function clauses( $clauses, $query ) {
		global $wpdb;
		$language = is_object( $query ) && method_exists( $query, 'get' ) ? (string) $query->get( 'lb_rest_language' ) : '';
		if ( '' === $language || ! Storage::is_available() ) {
			return $clauses;
		}
		$table = Storage::table();
		$clauses['join'] .= " LEFT JOIN $table AS lb_rest ON lb_rest.object_id = {$wpdb->posts}.ID ";
		if ( 'originals' === $language ) {
			$clauses['where'] .= ' AND ( lb_rest.object_id IS NULL OR lb_rest.object_id = lb_rest.source_id ) ';
		} else {
			$clauses['where'] .= Languages::default_code() === $language ? $wpdb->prepare( ' AND ( lb_rest.object_id IS NULL OR lb_rest.language = %s ) ', $language ) : $wpdb->prepare( ' AND lb_rest.language = %s ', $language );
		}
		return $clauses;
	}
}
