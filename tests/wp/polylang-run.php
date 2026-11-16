<?php
/**
 * Import from Polylang (1.13.0): after the contents, the relations of the terms and the menus of each
 * language (dev/test.sh with Polylang copied into dev/.third-party, active only for this file).
 */

add_filter( 'locale', static function () { return 'it_IT'; } );
unload_textdomain( 'lingua-bridge', true );
use LinguaBridge\Importer;
use LinguaBridge\Languages;
use LinguaBridge\Terms;

$failures = 0;
$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	$failures += $ok ? 0 : 1;
};
wp_set_current_user( 1 );
$saved_settings = get_option( Languages::OPTION );
$saved_locations = get_theme_mod( 'nav_menu_locations' );
$terms = array();
$menus = array();
$languages = array();
try {
	$check( function_exists( 'PLL' ) && function_exists( 'pll_save_term_translations' ), 'Polylang is active' );
	foreach ( array( array( 'Italiano', 'it', 'it_IT', 0 ), array( 'English', 'en', 'en_US', 1 ) ) as $language ) {
		$added = PLL()->model->add_language( array( 'name' => $language[0], 'slug' => $language[1], 'locale' => $language[2], 'rtl' => false, 'term_group' => $language[3] ) );
		$check( ! is_wp_error( $added ), 'Polylang language ' . $language[1] );
	}
	PLL()->model->clean_languages_cache();
	foreach ( array( 'LB PL Chiostri' => 'it', 'LB PL Cloisters' => 'en', 'LB PL Solo italiano' => 'it' ) as $name => $code ) {
		$term = wp_insert_term( $name, 'category' );
		$terms[ $name ] = (int) $term['term_id'];
		pll_set_term_language( $terms[ $name ], $code );
	}
	pll_save_term_translations( array( 'it' => $terms['LB PL Chiostri'], 'en' => $terms['LB PL Cloisters'] ) );
	register_nav_menu( 'lb-primary', 'LB primary' );
	$menus['it'] = wp_create_nav_menu( 'LB PL menu it' );
	$menus['en'] = wp_create_nav_menu( 'LB PL menu en' );
	$options = get_option( 'polylang' );
	$options['nav_menus'][ get_stylesheet() ]['lb-primary'] = array( 'it' => $menus['it'], 'en' => $menus['en'] );
	update_option( 'polylang', $options );

	$result = Importer::import_terms_and_menus( 'polylang' );
	$check( $result['terms'] >= 1 && (int) Terms::translation( $terms['LB PL Chiostri'], 'en' ) === $terms['LB PL Cloisters'], 'C32: the Polylang pair of terms is a group of Lingua Bridge: ' . wp_json_encode( $result ) );
	$check( 'en' === Terms::language_of( $terms['LB PL Cloisters'] ) && ! Terms::translation( $terms['LB PL Solo italiano'], 'en' ), 'C32: the English term is English; a term alone stays alone' );
	$check( 'already' === Terms::link_group( array( 'it' => $terms['LB PL Chiostri'], 'en' => $terms['LB PL Cloisters'] ) ), 'C32: a second import changes nothing' );
	$check( 'conflicts' === Terms::link_group( array( 'it' => $terms['LB PL Solo italiano'], 'en' => $terms['LB PL Cloisters'] ) ), 'C32: a term linked elsewhere is a conflict' );
	$check( 1 === $result['menus'] && $menus['en'] === ( Languages::settings()['language_menus']['en']['lb-primary'] ?? 0 ), 'C32: the English menu of the place, as Polylang had it' );
	set_theme_mod( 'nav_menu_locations', array( 'lb-primary' => $menus['it'] ) );
	$current = new ReflectionProperty( \LinguaBridge\Frontend::class, 'current_language_cache' );
	$current->setAccessible( true );
	$current->setValue( null, 'en' );
	$check( $menus['en'] === ( get_theme_mod( 'nav_menu_locations' )['lb-primary'] ?? 0 ), 'C32: on an English page the place shows the English menu' );
	$current->setValue( null, 'it' );
	$check( $menus['it'] === ( get_theme_mod( 'nav_menu_locations' )['lb-primary'] ?? 0 ), 'C32: on an Italian page the original menu' );
	$current->setValue( null, null );
} finally {
	foreach ( $terms as $id ) {
		Terms::forget( $id );
		wp_delete_term( $id, 'category' );
	}
	foreach ( $menus as $id ) {
		wp_delete_nav_menu( $id );
	}
	foreach ( (array) PLL()->model->get_languages_list() as $language ) {
		PLL()->model->delete_language( $language->term_id );
	}
	delete_option( 'polylang' );
	update_option( Languages::OPTION, $saved_settings );
	set_theme_mod( 'nav_menu_locations', $saved_locations );
}

echo $failures ? "FAILURES: $failures\n" : "OK\n";
if ( $failures ) {
	exit( 1 );
}
