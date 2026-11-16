<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C33 of the competition list (1.13.0): the language in WPGraphQL, as in the REST API (Rest, 1.6.2).
 *
 * - Every translatable type shown in GraphQL has the field `lingua`: `code`, `original`, `group` and `versions` (a list
 *   of `code`, `databaseId` and `link`), the same versions the REST field names: the ones the reader may see.
 * - The connections on posts (`posts`, `pages`, `contentNodes`, the custom types) take `where: { lingua: "en" }` (one
 *   language) or `where: { lingua: "originals" }`, through the same clause as `?lingua=` of the REST API.
 * Only with WPGraphQL active: without it nothing is hooked.
 */
final class GraphQL {
	const ARG = 'lingua';

	public static function init() {
		add_action( 'graphql_register_types', array( __CLASS__, 'register' ) );
		add_filter( 'graphql_input_fields', array( __CLASS__, 'input_fields' ), 10, 3 );
		add_filter( 'graphql_post_object_connection_query_args', array( __CLASS__, 'query_args' ), 10, 3 );
	}

	/** The types of the field, and the field on each translatable type shown in GraphQL. */
	public static function register() {
		if ( ! function_exists( 'register_graphql_object_type' ) ) {
			return;
		}
		register_graphql_object_type(
			'LinguaBridgeVersion',
			array(
				'description' => __( 'Una versione del contenuto in un\'altra lingua (Lingua Bridge).', 'lingua-bridge' ),
				'fields'      => array(
					'code'       => array( 'type' => 'String' ),
					'databaseId' => array( 'type' => 'Int' ),
					'link'       => array( 'type' => 'String' ),
				),
			)
		);
		register_graphql_object_type(
			'LinguaBridgeLanguage',
			array(
				'description' => __( 'La lingua del contenuto e le sue versioni (Lingua Bridge).', 'lingua-bridge' ),
				'fields'      => array(
					'code'     => array( 'type' => 'String' ),
					'original' => array( 'type' => 'Boolean' ),
					'group'    => array( 'type' => 'String' ),
					'versions' => array( 'type' => array( 'list_of' => 'LinguaBridgeVersion' ) ),
				),
			)
		);
		foreach ( Translations::translatable_types() as $type ) {
			$object = get_post_type_object( $type );
			if ( ! $object || empty( $object->show_in_graphql ) || empty( $object->graphql_single_name ) ) {
				continue;
			}
			register_graphql_field(
				ucfirst( (string) $object->graphql_single_name ),
				self::ARG,
				array(
					'type'        => 'LinguaBridgeLanguage',
					'description' => __( 'La lingua del contenuto e le sue versioni (Lingua Bridge).', 'lingua-bridge' ),
					'resolve'     => array( __CLASS__, 'resolve' ),
				)
			);
		}
	}

	/** The value of `lingua` for a post of GraphQL: the REST field, with the versions as a list. */
	public static function resolve( $post ) {
		// WPGraphQL's Post model reads its fields through __get: databaseId, or ID for a WP_Post.
		$id = is_object( $post ) ? absint( $post instanceof \WP_Post ? $post->ID : $post->databaseId ) : 0;
		$field = Rest::field( array( 'id' => $id ) );
		$versions = array();
		foreach ( (array) $field['versions'] as $code => $version ) {
			$versions[] = array( 'code' => (string) $code, 'databaseId' => (int) $version['id'], 'link' => (string) $version['link'] );
		}
		$field['versions'] = $versions;
		return $field;
	}

	/** `lingua` among the `where` arguments of every connection on posts (the ones queried with WP_Query). */
	public static function input_fields( $fields, $type_name, $config ) {
		if ( is_array( $fields ) && is_string( $type_name ) && 'WhereArgs' === substr( $type_name, -9 ) && 'WP_Query' === ( $config['queryClass'] ?? '' ) && ! isset( $fields[ self::ARG ] ) ) {
			$fields[ self::ARG ] = array(
				'type'        => 'String',
				'description' => __( 'Solo i contenuti di una lingua (il suo codice), o solo gli originali (originals).', 'lingua-bridge' ),
			);
		}
		return $fields;
	}

	/** `where: { lingua }` becomes the query var the clause of the REST API reads. */
	public static function query_args( $query_args, $source, $args ) {
		$language = sanitize_key( (string) ( $args['where'][ self::ARG ] ?? '' ) );
		if ( 'originals' === $language || isset( Languages::all()[ $language ] ) ) {
			$query_args['lb_rest_language'] = $language;
			if ( ! has_filter( 'posts_clauses', array( Rest::class, 'clauses' ) ) ) {
				add_filter( 'posts_clauses', array( Rest::class, 'clauses' ), 10, 2 );
			}
		}
		return $query_args;
	}
}
