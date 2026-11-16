<?php
/**
 * Histeon + Lingua Bridge (dev/test.sh when Histeon is mounted): an Italian component and its English
 * translation, two linked pages that use it, a Histeon loop and data.lingua. Prints ids and paths as JSON.
 */
use LinguaBridge\Storage;

wp_set_current_user( 1 );
$old = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_lb_histos', 'fields' => 'ids' ) );
foreach ( $old as $id ) {
	Storage::delete( $id );
	wp_delete_post( $id, true );
}
$n = 0;
$uid = static function () use ( &$n ) {
	return 'h' . substr( md5( (string) ++$n . microtime() ), 0, 9 );
};
$text = static fn( $c ) => array( 'blockName' => 'xb/text', 'attrs' => array( 'uid' => $uid(), 'content' => $c ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() );
$el = static function ( $tag, $attributes, $children ) use ( $uid ) {
	return array( 'blockName' => 'xb/element', 'attrs' => array( 'uid' => $uid(), 'tag' => $tag, 'attributes' => (object) $attributes ), 'innerBlocks' => $children, 'innerHTML' => '', 'innerContent' => array_fill( 0, count( $children ), null ) );
};
$markup = static fn( $blocks ) => serialize_blocks( $blocks );
$make = static function ( $type, $title, $slug, $content, $meta = array() ) {
	$id = wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content, 'meta_input' => $meta + array( '_lb_histos' => 1 ) ) ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return (int) $id;
};
$link = static function ( $it, $en ) {
	$group = wp_generate_uuid4();
	Storage::insert( array( 'object_id' => $it, 'group_uuid' => $group, 'language' => 'it', 'source_id' => $it, 'source_hash' => '', 'review_state' => 'source' ) );
	Storage::insert( array( 'object_id' => $en, 'group_uuid' => $group, 'language' => 'en', 'source_id' => $it, 'source_hash' => '', 'review_state' => 'reviewed' ) );
};

$key = \XB\Render\ComponentRegistry::META_KEY;
$comp_it = $make( 'wp_block', 'Saluto', 'saluto', $markup( array( $el( 'p', array( 'class' => 'saluto' ), array( $text( 'LB-COMPONENTE-CIAO' ) ) ) ) ), array( $key => 'saluto' ) );
$comp_en = $make( 'wp_block', 'Greeting', 'greeting', $markup( array( $el( 'p', array( 'class' => 'saluto' ), array( $text( 'LB-COMPONENT-HELLO' ) ) ) ) ), array( $key => 'saluto' ) );
$link( $comp_it, $comp_en );

$page_blocks = static function () use ( $el, $text, $uid ) {
	$loop = array( 'blockName' => 'xb/loop', 'attrs' => array( 'uid' => $uid(), 'source' => array( 'kind' => 'preset', 'id' => 'posts', 'args' => array( 'limit' => 20 ) ), 'as' => 'item' ), 'innerBlocks' => array( $el( 'p', array( 'class' => 'voce' ), array( $text( 'VOCE:{item.title}' ) ) ) ), 'innerHTML' => '', 'innerContent' => array( null ) );
	return array(
		array( 'blockName' => 'xb/component', 'attrs' => array( 'uid' => $uid(), 'ref' => 'saluto' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ),
		$el( 'p', array( 'class' => 'lingua' ), array( $text( 'LINGUA:{data.lingua.code}|{data.lingua.alternates.length()}' ) ) ),
		$el( 'p', array( 'class' => 'testo' ), array( $text( 'Questo testo resta da tradurre' ) ) ),
		$loop,
	);
};
$page_it = $make( 'page', 'Pagina Histeon', 'pagina-histos', serialize_blocks( $page_blocks() ) );
$page_en = $make( 'page', 'Histeon page', 'histos-page', serialize_blocks( $page_blocks() ) );
$link( $page_it, $page_en );
flush_rewrite_rules( false );

echo wp_json_encode(
	array(
		'ids'   => compact( 'comp_it', 'comp_en', 'page_it', 'page_en' ),
		'paths' => array( 'page_it' => wp_parse_url( get_permalink( $page_it ), PHP_URL_PATH ), 'page_en' => wp_parse_url( get_permalink( $page_en ), PHP_URL_PATH ) ),
	)
);
