<?php
/**
 * WooCommerce on a real site (dev/test.sh with WOOCOMMERCE_PLUGIN): a translated product sells the same thing.
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Storage;
use LinguaBridge\Translations;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
$created = array();
wp_set_current_user( 1 );
try {
	$check( class_exists( 'WooCommerce' ), 'WooCommerce is active' );

	// A simple product.
	$simple = new WC_Product_Simple();
	$simple->set_name( 'LB maglia' );
	$simple->set_regular_price( '10' );
	$simple->set_sku( 'LB-' . wp_generate_password( 6, false ) );
	$simple->set_manage_stock( true );
	$simple->set_stock_quantity( 5 );
	$simple->set_weight( '0.3' );
	$simple->set_status( 'publish' );
	$it = $simple->save();
	$created[] = $it;
	// Audit of 3 November, note 33: Histeon's per-post markers (_xb_) stay on the original.
	update_post_meta( $it, '_xb_page_profile_id', 'lb-profile' );
	update_post_meta( $it, '_xb_script', 'console.log(1)' );
	$en = Translations::create_draft( $it, 'en' );
	$created[] = $en;
	$check( ! metadata_exists( 'post', $en, '_xb_page_profile_id' ) && ! metadata_exists( 'post', $en, '_xb_script' ), 'Histeon\'s _xb_ meta are not copied to the product translation' );
	$en_product = wc_get_product( $en );
	$check( $en_product && 'simple' === $en_product->get_type() && '10' === $en_product->get_regular_price() && 5 === $en_product->get_stock_quantity() && '0.3' === $en_product->get_weight(), 'the translation is the same product: type, price, stock, weight' );
	$check( '' === $en_product->get_sku(), 'the SKU stays on the original (unique)' );

	$simple = wc_get_product( $it );
	$simple->set_regular_price( '12' );
	$simple->save();
	$check( '12' === wc_get_product( $en )->get_regular_price(), 'a price saved in Italian reaches English' );
	// Whoever edits only a translation never changes what everyone buys: the translation takes the original's.
	$en_product = wc_get_product( $en );
	$en_product->set_regular_price( '0.01' );
	$en_product->save();
	$check( '12' === wc_get_product( $en )->get_regular_price() && '12' === wc_get_product( $it )->get_regular_price(), 'a price saved in a translation goes back to the original\'s' );
	wc_update_product_stock( wc_get_product( $en ), 1, 'decrease' );
	$check( 5 === wc_get_product( $it )->get_stock_quantity() && 5 === wc_get_product( $en )->get_stock_quantity(), 'a stock change on a draft translation takes the original\'s' );
	wp_publish_post( $en );
	wc_update_product_stock( wc_get_product( $en ), 2, 'decrease' );
	global $wpdb;
	$stock_of = static fn( $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_stock'", $id ) );
	$check( 3 === $stock_of( $it ) && 3 === $stock_of( $en ), 'a sale of the published English product: 3 left in both languages (read from the database)' );

	// A variable product with two variations.
	$variable = new WC_Product_Variable();
	$variable->set_name( 'LB felpa' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Taglia' );
	$attribute->set_options( array( 'S', 'M' ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$variable->set_attributes( array( $attribute ) );
	$variable->set_status( 'publish' );
	$vit = $variable->save();
	$created[] = $vit;
	$variation_ids = array();
	foreach ( array( 'S' => 20, 'M' => 22 ) as $size => $price ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $vit );
		$variation->set_attributes( array( 'taglia' => $size ) );
		$variation->set_regular_price( (string) $price );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 4 );
		$variation_ids[] = $variation->save();
	}
	$created = array_merge( $created, $variation_ids );
	WC_Product_Variable::sync( $vit );
	$ven = Translations::create_draft( $vit, 'en' );
	$created[] = $ven;
	$en_variations = wc_get_product( $ven )->get_children();
	$created = array_merge( $created, $en_variations );
	$check( 2 === count( $en_variations ), 'the variations come along' );
	$first_en = wc_get_product( $en_variations[0] );
	$check( $first_en && '20' === $first_en->get_regular_price() && 4 === $first_en->get_stock_quantity(), 'each variation keeps price and stock' );
	$check( 'private' === get_post_status( $en_variations[0] ), 'variations stay private while the translated product is a draft' );
	wp_publish_post( $ven );
	$check( 'publish' === get_post_status( $en_variations[0] ), 'they go online with it' );
	$first_en = wc_get_product( $en_variations[0] );
	$row = Storage::find( $en_variations[0] );
	$check( $row && 'en' === $row['language'] && Storage::find( $variation_ids[0] )['group_uuid'] === $row['group_uuid'], 'each variation is linked to the original one' );
	wc_update_product_stock( $first_en, 1, 'decrease' );
	$check( 3 === wc_get_product( $variation_ids[0] )->get_stock_quantity(), 'a variation sold in English lowers the Italian one' );
	// C20: a global attribute is a term in every language; a translated product's variations name the translated term.
	$attribute = wc_create_attribute( array( 'name' => 'LB colore', 'slug' => 'lbcolore' ) );
	register_taxonomy( 'pa_lbcolore', array( 'product' ), array( 'hierarchical' => false, 'public' => false, 'show_ui' => true ) );
	$check( in_array( 'pa_lbcolore', \LinguaBridge\Terms::taxonomies(), true ), 'C20: a global attribute is among the taxonomies Lingua Bridge translates' );
	$rosso = wp_insert_term( 'Rosso', 'pa_lbcolore', array( 'slug' => 'rosso-lb' ) );
	$red = \LinguaBridge\Terms::create( $rosso['term_id'], 'en', 'Red', 'red-lb' );
	$check( is_int( $red ) && 'red-lb' === get_term( $red )->slug, 'C20: the attribute\'s term gets its English version' );
	$attr = new WC_Product_Attribute();
	$attr->set_id( (int) $attribute );
	$attr->set_name( 'pa_lbcolore' );
	$attr->set_options( array( $rosso['term_id'] ) );
	$attr->set_variation( true );
	$colored = new WC_Product_Variable();
	$colored->set_name( 'LB maglia colorata' );
	$colored->set_attributes( array( $attr ) );
	$colored->set_default_attributes( array( 'pa_lbcolore' => 'rosso-lb' ) );
	$colored->set_status( 'publish' );
	$cit = $colored->save();
	$created[] = $cit;
	$cvar = new WC_Product_Variation();
	$cvar->set_parent_id( $cit );
	$cvar->set_attributes( array( 'pa_lbcolore' => 'rosso-lb' ) );
	$cvar->set_regular_price( '15' );
	$created[] = $cvar->save();
	$cen = Translations::create_draft( $cit, 'en' );
	$created[] = $cen;
	$cen_variations = get_posts( array( 'post_type' => 'product_variation', 'post_parent' => $cen, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 5 ) );
	$created = array_merge( $created, $cen_variations );
	$check( in_array( $red, wp_get_object_terms( $cen, 'pa_lbcolore', array( 'fields' => 'ids' ) ), true ), 'C20: the English product has the English term' );
	$check( $cen_variations && 'red-lb' === get_post_meta( $cen_variations[0], 'attribute_pa_lbcolore', true ), 'C20: its variation names the English term: ' . ( $cen_variations ? get_post_meta( $cen_variations[0], 'attribute_pa_lbcolore', true ) : '' ) );
	$check( array( 'pa_lbcolore' => 'red-lb' ) === get_post_meta( $cen, '_default_attributes', true ), 'C20: and so do its default attributes' );
	wp_delete_term( $red, 'pa_lbcolore' );
	wp_delete_term( $rosso['term_id'], 'pa_lbcolore' );
	wc_delete_attribute( (int) $attribute );

	// C19: an order's emails in the order's language, also when the shop sends them later from the administration.
	$sent_locales = array();
	$catch = static function ( $subject ) use ( &$sent_locales ) {
		// This file forces it_IT on the locale filter: the switched locale is the switcher's.
		$sent_locales[] = $GLOBALS['wp_locale_switcher']->get_switched_locale() ?: 'none';
		return $subject;
	};
	add_filter( 'woocommerce_email_subject_customer_completed_order', $catch );
	// WordPress switches only to an installed language, listed once when its switcher is made: as if the en_GB
	// pack were there (a site has it).
	$available = new ReflectionProperty( $GLOBALS['wp_locale_switcher'], 'available_languages' );
	$available->setAccessible( true );
	$available_before = $available->getValue( $GLOBALS['wp_locale_switcher'] );
	$available->setValue( $GLOBALS['wp_locale_switcher'], array_merge( (array) $available_before, array( 'en_GB' ) ) );
	add_filter( 'pre_wp_mail', '__return_true' );
	$order = wc_create_order();
	$order->set_billing_email( 'cliente@example.test' );
	$order->update_meta_data( \LinguaBridge\WooCommerce::ORDER_META, 'en' );
	$order->save();
	$check( 'en' === \LinguaBridge\WooCommerce::language_of_order( $order->get_id() ), 'C19: the order keeps the language it was made in' );
	// At checkout the order takes the language of the visitor's cart and checkout pages, kept in their session.
	WC()->session = new WC_Session_Handler();
	WC()->session->init();
	WC()->session->set( \LinguaBridge\WooCommerce::SESSION_KEY, 'en' );
	$checkout = wc_create_order();
	do_action( 'woocommerce_checkout_create_order', $checkout, array() );
	$check( 'en' === $checkout->get_meta( \LinguaBridge\WooCommerce::ORDER_META ), 'C19: an order made from the English checkout is English' );
	$checkout->delete( true );
	do_action( 'woocommerce_order_status_completed_notification', $order->get_id(), $order );
	$check( array( 'en_GB' ) === $sent_locales, 'C19: the «completed» email is written in the order\'s locale: ' . implode( ',', $sent_locales ) );
	$check( false === $GLOBALS['wp_locale_switcher']->get_switched_locale(), 'C19: and the locale is the one of before, after it' );
	$it_order = wc_create_order();
	$it_order->set_billing_email( 'cliente@example.test' );
	$it_order->save();
	$sent_locales = array();
	do_action( 'woocommerce_order_status_completed_notification', $it_order->get_id(), $it_order );
	$check( 1 === count( $sent_locales ) && 'en_GB' !== $sent_locales[0], 'C19: an order of the original\'s language changes nothing: ' . implode( ',', $sent_locales ) );
	$customer = wp_insert_user( array( 'user_login' => 'lb_c19_customer', 'user_pass' => wp_generate_password(), 'role' => 'customer' ) );
	update_user_meta( $customer, 'locale', 'en_GB' );
	$it_order->set_customer_id( $customer );
	$it_order->save();
	$check( 'en' === \LinguaBridge\WooCommerce::language_of_order( $it_order ), 'C19: without a language saved, the customer\'s profile language of the site' );
	remove_filter( 'woocommerce_email_subject_customer_completed_order', $catch );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$available->setValue( $GLOBALS['wp_locale_switcher'], $available_before );
	$order->delete( true );
	$it_order->delete( true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $customer );

	// The cart page for the HTTP suite (tests/wp/woocommerce.test.mjs): the seed's «Carrello», translated.
	$cart = get_posts( array( 'post_type' => 'page', 'name' => 'carrello', 'numberposts' => 1 ) )[0] ?? null;
	update_option( 'woocommerce_cart_page_id', $cart ? $cart->ID : 0 );
} finally {
	foreach ( array_reverse( $created ) as $id ) {
		Storage::delete( $id );
		wp_delete_post( $id, true );
	}
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
