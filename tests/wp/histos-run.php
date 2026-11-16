<?php
/**
 * In-WordPress checks of the Histeon integration (dev/test.sh): the canvas render and the builder's language bar.
 */

// Lingua Bridge is written in Italian and these checks read its Italian texts: Italian here, whatever language the
// test site has (the site stays en_US for the routing tests; every other language gets English).
add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
$seed = json_decode( (string) getenv( 'LB_HISTOS_SEED' ), true );
$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$render = static function ( int $post_id ): string {
	$request = new WP_REST_Request( 'POST', '/xb/v1/render' );
	$request->set_body_params( array( 'post_id' => $post_id, 'content' => (string) get_post_field( 'post_content', $post_id, 'raw' ) ) );
	$response = rest_do_request( $request );
	$data = $response->get_data();
	return is_array( $data ) && isset( $data['html'] ) ? (string) $data['html'] : 'ERROR ' . wp_json_encode( $data );
};
$en = $render( $seed['ids']['page_en'] );
$check( false !== strpos( $en, 'LB-COMPONENT-HELLO' ), 'canvas of the English page: the English component' );
$check( false !== strpos( $en, 'LINGUA:en|2' ), 'canvas of the English page: data.lingua in English, two languages' );
$check( false !== strpos( $en, 'VOCE:LB English article' ) && false === strpos( $en, 'VOCE:LB articolo italiano' ), 'canvas of the English page: lists in English' );
$it = $render( $seed['ids']['page_it'] );
$check( false !== strpos( $it, 'LB-COMPONENTE-CIAO' ), 'canvas of the Italian page: the Italian component' );
$check( false !== strpos( $it, 'VOCE:LB articolo italiano' ) && false === strpos( $it, 'VOCE:LB English article' ), 'canvas of the Italian page: lists in Italian' );

$bar = \XB\Admin\BuilderScreen::languages( $seed['ids']['page_en'] );
$states = array_column( $bar['items'] ?? array(), 'state', 'code' );
$check( 'current' === ( $states['en'] ?? '' ) && 'publish' === ( $states['it'] ?? '' ), 'language bar: English here, Italian published' );
$it_item = array_values( array_filter( $bar['items'] ?? array(), static fn( $i ) => 'it' === $i['code'] ) )[0] ?? array();
$check( false !== strpos( $it_item['url'] ?? '', 'action=xb_builder&post=' . $seed['ids']['page_it'] ), 'language bar: the Italian page opens in the builder' );
$check( in_array( 'Questo testo resta da tradurre', $bar['source'] ?? array(), true ), 'the original texts reach the builder for the untranslated check' );
// A translation sent by Histeon Publish (xb_remote_language_link).
$remote_source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Da Publish', 'post_name' => 'da-publish' ) );
$remote_draft = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'From Publish', 'post_name' => 'da-publish-en' ) );
do_action( 'xb_remote_language_link', $remote_draft, $remote_source, 'en', 'follow' );
$remote_row = \LinguaBridge\Storage::find( $remote_draft );
$check( is_array( $remote_row ) && 'en' === $remote_row['language'] && absint( $remote_row['source_id'] ) === $remote_source, 'a translation from Publish is linked to its original' );
$check( 'draft' === get_post_status( $remote_draft ) && 'publish' === get_post_status( $remote_source ), 'the link publishes nothing' );
do_action( 'xb_remote_language_link', $remote_draft, $remote_source, 'en', 'independent' );
$check( 'independent' === get_post_meta( $remote_draft, \LinguaBridge\Translations::MODE_META, true ), 'a second send updates the mode, not the link' );
$remote_other = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Altro' ) );
do_action( 'xb_remote_language_link', $remote_other, $remote_source, 'xx', 'follow' );
$check( null === \LinguaBridge\Storage::find( $remote_other ) || ! \LinguaBridge\Storage::find( $remote_other ), 'a language this site does not have is left out' );

add_filter( 'lingua_bridge_settings_test', '__return_true' );
$s = get_option( 'lingua_bridge_settings' );
$s['languages']['de'] = 'Deutsch';
update_option( 'lingua_bridge_settings', $s );
$bar = \XB\Admin\BuilderScreen::languages( $seed['ids']['page_it'] );
$de = array_values( array_filter( $bar['items'] ?? array(), static fn( $i ) => 'de' === $i['code'] ) )[0] ?? array();
$check( 'missing' === ( $de['state'] ?? '' ) && false !== strpos( $de['url'] ?? '', 'lb_return=histos' ), 'a missing language links to "create and open in Histeon"' );
unset( $s['languages']['de'] );
update_option( 'lingua_bridge_settings', $s );

// Histeon → Panoramica: the translations to do, as one plain row.
$status = apply_filters( 'xb_site_status', array() );
$row = array_values( array_filter( is_array( $status ) ? $status : array(), static fn( $r ) => is_array( $r ) && 'Traduzioni' === ( $r['label'] ?? '' ) ) )[0] ?? array();
$check( 1 === preg_match( '/^\d+ da tradurre · \d+ da aggiornare · \d+ in bozza/', $row['value'] ?? '' ), 'site status: translations to do, counted' );
$check( in_array( $row['state'] ?? '', array( 'ok', 'warn' ), true ) && 0 === strpos( $row['url'] ?? '', admin_url() ), 'site status: a state and a link into this admin' );

echo $failures ? "FAILURES: $failures\n" : "OK\n";
// A failure fails dev/test.sh, not only its output.
if ( $failures ) {
	exit( 1 );
}
