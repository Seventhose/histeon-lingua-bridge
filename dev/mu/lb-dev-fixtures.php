<?php
/**
 * Dev and tests only (mounted by dev/compose.yml): a public custom post type, as sites have.
 */
add_action(
	'init',
	static function () {
		register_post_type(
			'evento',
			array(
				'label'        => 'Eventi',
				'public'       => true,
				'show_ui'      => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'eventi' ),
				'supports'     => array( 'title', 'editor' ),
				'show_in_rest' => true,
			)
		);
	}
);

// Texts outside contents, for the strings layer (tests/wp/strings): a fixed footer text, the same text in an
// alt attribute and inside a script (which must stay as it is), and the WordPress locale of the page.
add_action(
	'wp_footer',
	static function () {
		echo '<p class="lb-fixed">Testo fisso del tema</p><img class="lb-fixed-img" src="data:," alt="Logo del sito"><script type="text/plain" id="lb-fixed-script">Testo fisso del tema</script>';
	}
);
add_action(
	'wp_head',
	static function () {
		echo '<meta name="lb-locale" content="' . esc_attr( get_locale() ) . '">';
	}
);
// A classic menu where a content can show it: [lb_test_menu].
add_shortcode(
	'lb_test_menu',
	static function () {
		return wp_nav_menu( array( 'menu' => 'LB test menu', 'echo' => false, 'container' => 'nav', 'container_class' => 'lb-test-menu', 'fallback_cb' => false ) );
	}
);
// The cart address in the page's language, for the WooCommerce suite: [lb_test_cart].
add_shortcode(
	'lb_test_cart',
	static function () {
		return function_exists( 'wc_get_cart_url' ) ? '<a class="lb-test-cart" href="' . esc_url( wc_get_cart_url() ) . '">cart</a>' : '';
	}
);
// A fake translation service for the tests (dev only): OpenAI-compatible, answers "[en] text"; wants the key
// "lb-test-key". Reached at http://wordpress/… from both containers, which the filter below allows over http.
add_filter( 'lingua_bridge_mt_http_hosts', static fn( $hosts ) => array_merge( (array) $hosts, array( 'wordpress' ) ) );
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'lb-mock/v1',
			'/chat/completions',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( 'Bearer lb-test-key' !== $request->get_header( 'authorization' ) ) {
						return new WP_REST_Response( array( 'error' => array( 'message' => 'invalid key' ) ), 401 );
					}
					$messages = (array) $request->get_param( 'messages' );
					$input = json_decode( (string) ( end( $messages )['content'] ?? '' ), true );
					$texts = is_array( $input['texts'] ?? null ) ? $input['texts'] : array();
					$translations = array_map( static fn( $t ) => '[en] ' . $t, $texts );
					return array( 'choices' => array( array( 'message' => array( 'content' => wp_json_encode( array( 'translations' => $translations ) ) ) ) ) );
				},
			)
		);
	}
);
// Static Bridge (dev/test.sh with STATIC_BRIDGE_PLUGIN) exports by asking the site for its own pages. The site's
// address is localhost:<published port>, which inside the container is nobody: the request goes to Apache on port 80
// with the same URL and Host. Only in this test stack, only for requests to the site itself.
add_action(
	'http_api_curl',
	static function ( $handle, $args, $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$to = wp_parse_url( (string) $url );
		if ( ! defined( 'CURLOPT_CONNECT_TO' ) || ( $home['host'] ?? '' ) !== 'localhost' || ( $to['host'] ?? '' ) !== 'localhost' || ( $to['port'] ?? 80 ) !== ( $home['port'] ?? 80 ) ) {
			return;
		}
		curl_setopt( $handle, CURLOPT_CONNECT_TO, array( 'localhost:' . ( $home['port'] ?? 80 ) . ':127.0.0.1:80' ) );
	},
	10,
	3
);
// Fake Google Cloud Translation and DeepL for the tests (C27): «[g] text», «[d] text» (with the glossary used), and
// DeepL's glossaries, which answer with an id. Same key as the fake service above.
add_action(
	'rest_api_init',
	static function () {
		$texts = static fn( $list, $mark ) => array_map( static fn( $t ) => $mark . ' ' . $t, array_values( (array) $list ) );
		register_rest_route(
			'lb-mock/v1',
			'/google',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) use ( $texts ) {
					if ( 'lb-test-key' !== $request->get_header( 'x_goog_api_key' ) ) {
						return new WP_REST_Response( array( 'error' => array( 'message' => 'invalid key' ) ), 403 );
					}
					return array( 'data' => array( 'translations' => array_map( static fn( $t ) => array( 'translatedText' => $t ), $texts( $request->get_param( 'q' ), '[g ' . $request->get_param( 'target' ) . ']' ) ) ) );
				},
			)
		);
		register_rest_route(
			'lb-mock/v1',
			'/deepl/v2/translate',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) use ( $texts ) {
					if ( 'DeepL-Auth-Key lb-test-key' !== $request->get_header( 'authorization' ) ) {
						return new WP_REST_Response( array( 'message' => 'invalid key' ), 403 );
					}
					$mark = '[d' . ( $request->get_param( 'glossary_id' ) ? ' ' . $request->get_param( 'glossary_id' ) : '' ) . ( $request->get_param( 'formality' ) ? ' ' . $request->get_param( 'formality' ) : '' ) . ']';
					return array( 'translations' => array_map( static fn( $t ) => array( 'text' => $t ), $texts( $request->get_param( 'text' ), $mark ) ) );
				},
			)
		);
		register_rest_route(
			'lb-mock/v1',
			'/deepl/v3/glossaries',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					update_option( 'lb_mock_deepl_glossaries', (int) get_option( 'lb_mock_deepl_glossaries', 0 ) + 1, false );
					$dictionary = (array) ( $request->get_param( 'dictionaries' )[0] ?? array() );
					return array( 'glossary_id' => 'gl-' . substr( md5( (string) ( $dictionary['entries'] ?? '' ) ), 0, 6 ) );
				},
			)
		);
	}
);
