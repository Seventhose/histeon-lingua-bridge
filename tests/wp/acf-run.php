<?php
/**
 * C14: ACF in full, with Secure Custom Fields (dev/test.sh when dev/.third-party has it): the texts inside repeaters,
 * flexible contents and groups are translated one by one into the same rows; options pages have a version per
 * language, kept by ACF itself, and a language without one reads the original's.
 */
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Acf;
use LinguaBridge\Fields;
use LinguaBridge\Storage;
use LinguaBridge\Translations;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$check( function_exists( 'acf_add_local_field_group' ), 'Secure Custom Fields is active' );
acf_add_local_field_group(
	array(
		'key'      => 'group_lb_c14',
		'title'    => 'LB c14',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
		'fields'   => array(
			array( 'key' => 'field_lb_rows', 'name' => 'lb_rows', 'label' => 'Righe', 'type' => 'repeater', 'sub_fields' => array(
				array( 'key' => 'field_lb_row_title', 'name' => 'title', 'label' => 'Titolo', 'type' => 'text' ),
				array( 'key' => 'field_lb_row_image', 'name' => 'image', 'label' => 'Immagine', 'type' => 'number' ),
			) ),
			array( 'key' => 'field_lb_box', 'name' => 'lb_box', 'label' => 'Riquadro', 'type' => 'group', 'sub_fields' => array(
				array( 'key' => 'field_lb_box_text', 'name' => 'text', 'label' => 'Testo', 'type' => 'textarea' ),
			) ),
			array( 'key' => 'field_lb_flex', 'name' => 'lb_flex', 'label' => 'Sezioni', 'type' => 'flexible_content', 'layouts' => array(
				array( 'key' => 'layout_lb_quote', 'name' => 'quote', 'label' => 'Citazione', 'sub_fields' => array( array( 'key' => 'field_lb_quote_text', 'name' => 'quote', 'label' => 'Citazione', 'type' => 'text' ) ) ),
			) ),
		),
	)
);
$it = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'LB c14', 'post_content' => '<p>C14</p>' ) );
update_field( 'field_lb_rows', array( array( 'field_lb_row_title' => 'Prima riga', 'field_lb_row_image' => 5 ), array( 'field_lb_row_title' => 'Seconda riga', 'field_lb_row_image' => 6 ) ), $it );
update_field( 'field_lb_box', array( 'field_lb_box_text' => 'Testo del riquadro' ), $it );
update_field( 'field_lb_flex', array( array( 'acf_fc_layout' => 'quote', 'field_lb_quote_text' => 'Una citazione' ) ), $it );
$en = Translations::create_draft( $it, 'en' );
try {
	$subs = wp_list_pluck( Fields::translatable_values( $en ), 'value', 'key' );
	$check( 'Seconda riga' === ( $subs['field_lb_rows|1|field_lb_row_title'] ?? '' ) && 'Testo del riquadro' === ( $subs['field_lb_box|field_lb_box_text'] ?? '' ) && 'Una citazione' === ( $subs['field_lb_flex|0|field_lb_quote_text'] ?? '' ), 'the texts inside a repeater, a group and a flexible content go to translation one by one: ' . implode( ', ', array_keys( $subs ) ) );
	$check( ! isset( $subs['field_lb_rows|0|field_lb_row_image'] ), 'a number is not a text: it stays copied' );
	Fields::save_translatable_values(
		$en,
		array(
			array( 'provider' => 'acf_sub', 'key' => 'field_lb_rows|1|field_lb_row_title', 'value' => 'Second row' ),
			array( 'provider' => 'acf_sub', 'key' => 'field_lb_box|field_lb_box_text', 'value' => 'Box text' ),
			array( 'provider' => 'acf_sub', 'key' => 'field_lb_flex|0|field_lb_quote_text', 'value' => 'A quote' ),
			array( 'provider' => 'acf_sub', 'key' => 'field_lb_rows|0|field_lb_row_image', 'value' => '99' ),
		)
	);
	$rows = get_field( 'lb_rows', $en );
	$check( 'Prima riga' === $rows[0]['title'] && 'Second row' === $rows[1]['title'] && 6 === (int) $rows[1]['image'], 'the translated text lands in its row, the rest of the rows as it was' );
	$check( 5 === (int) $rows[0]['image'], 'a sub field that is no text is never written' );
	$check( 'Box text' === get_field( 'lb_box', $en )['text'] && 'A quote' === get_field( 'lb_flex', $en )[0]['quote'], 'groups and flexible contents too' );
	$check( 'Seconda riga' === get_field( 'lb_rows', $it )[1]['title'], 'the original is untouched' );

	// Options pages: a version per language, through ACF's own setting.
	acf_add_local_field_group( array( 'key' => 'group_lb_c14_options', 'title' => 'LB opzioni', 'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'lb-opzioni' ) ) ), 'fields' => array( array( 'key' => 'field_lb_footer', 'name' => 'lb_footer', 'label' => 'Piè', 'type' => 'text' ) ) ) );
	update_field( 'field_lb_footer', 'Piè italiano', 'options' );
	$language = new ReflectionProperty( \LinguaBridge\Frontend::class, 'current_language_cache' );
	$language->setAccessible( true );
	$language->setValue( null, 'en' );
	$check( 'options_en' === acf_get_valid_post_id( 'options' ), 'on an English page the options are ACF\'s «options_en»' );
	$check( 'Piè italiano' === get_field( 'lb_footer', 'options' ), 'without an English version, the original\'s' );
	update_field( 'field_lb_footer', 'English footer', 'options' );
	$check( 'English footer' === get_field( 'lb_footer', 'options' ), 'saved in English, read in English' );
	$language->setValue( null, null );
	$check( 'Piè italiano' === get_field( 'lb_footer', 'options' ), 'the Italian ones untouched' );
} finally {
	delete_option( 'options_lb_footer' );
	delete_option( '_options_lb_footer' );
	delete_option( 'options_en_lb_footer' );
	delete_option( '_options_en_lb_footer' );
	foreach ( array( $en, $it ) as $gone ) {
		Storage::delete( $gone );
		wp_delete_post( $gone, true );
	}
}
echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
