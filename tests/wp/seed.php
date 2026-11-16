<?php
/**
 * Test content for the integration suite (run by dev/test.sh with wp eval-file). Idempotent: removes what an
 * earlier run made (meta _lb_test) and builds it again. Prints the ids and paths as JSON for the HTTP tests.
 */
use LinguaBridge\Languages;
use LinguaBridge\Storage;

$old = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_lb_test', 'fields' => 'ids' ) );
foreach ( $old as $id ) {
	Storage::delete( $id );
	wp_delete_post( $id, true );
}

update_option(
	Languages::OPTION,
	array_merge(
		Languages::defaults(),
		array(
			'default_language'     => 'it',
			'prefix_language_urls' => true,
			'languages'            => array( 'it' => 'Italiano', 'en' => 'English' ),
			'switcher_menu'        => 'nav-block',
		)
	)
);
$news = term_exists( 'news', 'category' ) ?: wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) );
$news = (int) ( is_array( $news ) ? $news['term_id'] : $news );

$make = static function ( array $args ) use ( $news ): int {
	$id = wp_insert_post(
		array_merge(
			array( 'post_status' => 'publish', 'post_type' => 'page', 'post_author' => 1, 'meta_input' => array( '_lb_test' => 1 ) ),
			$args
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	if ( 'post' === get_post_type( $id ) ) {
		wp_set_post_categories( $id, array( $news ) );
	}
	return (int) $id;
};
$link = static function ( array $by_language ): void {
	$group  = wp_generate_uuid4();
	$source = $by_language['it'];
	foreach ( $by_language as $language => $id ) {
		Storage::insert(
			array(
				'object_id'    => $id,
				'group_uuid'   => $group,
				'language'     => $language,
				'source_id'    => $source,
				'source_hash'  => '',
				'review_state' => 'it' === $language ? 'source' : 'reviewed',
			)
		);
	}
};

$ids = array(
	'home_it'   => $make( array( 'post_title' => 'Casa', 'post_name' => 'casa', 'post_content' => '<!-- wp:paragraph --><p>LB-HOME-IT</p><!-- /wp:paragraph -->' ) ),
	'home_en'   => $make( array( 'post_title' => 'Home', 'post_name' => 'home-en', 'post_content' => '<!-- wp:paragraph --><p>LB-HOME-EN</p><!-- /wp:paragraph -->' ) ),
	'about_it'  => $make( array( 'post_title' => 'Chi siamo', 'post_name' => 'chi-siamo', 'post_content' => '<!-- wp:paragraph --><p>LB-ABOUT-IT</p><!-- /wp:paragraph --><!-- wp:shortcode -->[lingua_bridge_switcher]<!-- /wp:shortcode --><!-- wp:shortcode -->[lingua_bridge_switcher]<!-- /wp:shortcode -->' ) ),
	'about_en'  => $make( array( 'post_title' => 'About us', 'post_name' => 'about', 'post_content' => '<!-- wp:paragraph --><p>LB-ABOUT-EN</p><!-- /wp:paragraph --><!-- wp:shortcode -->[lingua_bridge_switcher]<!-- /wp:shortcode --><!-- wp:shortcode -->[lingua_bridge_switcher]<!-- /wp:shortcode -->' ) ),
	'post_it'   => $make( array( 'post_type' => 'post', 'post_title' => 'LB articolo italiano', 'post_name' => 'articolo-italiano' ) ),
	'post_en'   => $make( array( 'post_type' => 'post', 'post_title' => 'LB English article', 'post_name' => 'english-article' ) ),
	'only_it'   => $make( array( 'post_type' => 'post', 'post_title' => 'LB solo italiano', 'post_name' => 'solo-italiano' ) ),
	'event_it'  => $make( array( 'post_type' => 'evento', 'post_title' => 'LB evento', 'post_name' => 'evento-lb' ) ),
	'event_en'  => $make( array( 'post_type' => 'evento', 'post_title' => 'LB event', 'post_name' => 'event-lb' ) ),
	'cart_it'   => $make( array( 'post_title' => 'Carrello', 'post_name' => 'carrello' ) ),
	'cart_en'   => $make( array( 'post_title' => 'Cart', 'post_name' => 'cart' ) ),
);
$link( array( 'it' => $ids['home_it'], 'en' => $ids['home_en'] ) );
$link( array( 'it' => $ids['about_it'], 'en' => $ids['about_en'] ) );
$link( array( 'it' => $ids['post_it'], 'en' => $ids['post_en'] ) );
$link( array( 'it' => $ids['event_it'], 'en' => $ids['event_en'] ) );
$link( array( 'it' => $ids['cart_it'], 'en' => $ids['cart_en'] ) );
// C3: a tag that only the Italian list has (no English version of its list, so no hreflang).
wp_set_post_terms( $ids['only_it'], array( 'solo-it' ), 'post_tag' );
Storage::insert( array( 'object_id' => $ids['only_it'], 'group_uuid' => wp_generate_uuid4(), 'language' => 'it', 'source_id' => $ids['only_it'], 'source_hash' => '', 'review_state' => 'source' ) );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $ids['home_it'] );
flush_rewrite_rules( false );

// Strings outside contents: a classic menu, a navigation link in the English page, published translations.
// The WordPress locale of English pages, set in the settings (en_GB: no language pack needed to see the switch).
update_option( Languages::OPTION, array_merge( Languages::settings(), array( 'locales' => array( 'en' => 'en_GB' ) ) ) );
$menu = wp_get_nav_menu_object( 'LB test menu' );
if ( $menu ) {
	wp_delete_nav_menu( $menu->term_id );
}
$menu_id = wp_create_nav_menu( 'LB test menu' );
wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-object' => 'page', 'menu-item-object-id' => $ids['about_it'], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => 'Contatti', 'menu-item-url' => home_url( '/contatti/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => 'Casa', 'menu-item-url' => home_url( '/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
$nav_link = '<!-- wp:navigation-link {"label":"Chi siamo","type":"page","id":' . $ids['about_it'] . ',"url":"' . esc_url_raw( get_permalink( $ids['about_it'] ) ) . '","kind":"post-type"} /-->';
foreach ( array( 'about_it', 'about_en' ) as $key ) {
	$post = get_post( $ids[ $key ] );
	wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $post->post_content . '<!-- wp:shortcode -->[lb_test_menu]<!-- /wp:shortcode --><!-- wp:shortcode -->[lb_test_cart]<!-- /wp:shortcode -->' . $nav_link ) ) );
}
global $wpdb;
$wpdb->query( 'DELETE FROM ' . \LinguaBridge\Strings::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
update_option( 'blogname', 'Lingua Bridge Dev' );
$string = static function ( $context, $source, $translation ) use ( $wpdb ) {
	\LinguaBridge\Strings::register( $context, $source );
	$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . \LinguaBridge\Strings::table() . " WHERE source_hash = %s AND language = 'en'", \LinguaBridge\Strings::key( $context, $source ) ) );
	\LinguaBridge\Strings::save( $id, $translation, 'published' );
};
$string( 'html', 'Testo fisso del tema', 'Fixed theme text' );
$string( 'html', 'Logo del sito', 'Site logo' );
$string( 'option', 'Lingua Bridge Dev', 'Lingua Bridge "Dev" (EN)' );
$string( 'menu', 'Contatti', 'Contacts' );

$paths = array();
foreach ( $ids as $key => $id ) {
	$paths[ $key ] = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
}
echo wp_json_encode( array( 'ids' => $ids, 'paths' => $paths ) );
