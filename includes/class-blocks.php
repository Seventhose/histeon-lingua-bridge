<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Blocks {
	const LANGUAGE_SWITCHER = 'lingua-bridge/language-switcher';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_language_switcher' ) );
	}

	public static function register_language_switcher() {
		wp_register_script(
			'lingua-bridge-language-switcher-block',
			plugins_url( 'assets/language-switcher-block.js', LINGUA_BRIDGE_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-components' ),
			LINGUA_BRIDGE_VERSION,
			true
		);

		register_block_type(
			self::LANGUAGE_SWITCHER,
			array(
				'api_version'     => 3,
				'title'           => __( 'Selettore lingua — Lingua Bridge', 'lingua-bridge' ),
				'category'        => 'widgets',
				'icon'             => 'translation',
				'description'     => __( 'Mostra i collegamenti alle versioni pubblicate del contenuto corrente.', 'lingua-bridge' ),
				'editor_script'   => 'lingua-bridge-language-switcher-block',
				'render_callback' => array( __CLASS__, 'render_language_switcher' ),
				'supports'        => array( 'html' => false ),
				// F4: the same options as the shortcode's (Frontend::switcher_options()).
				'attributes'      => array(
					'style'       => array( 'type' => 'string', 'default' => 'list' ),
					'label'       => array( 'type' => 'string', 'default' => 'name' ),
					'hideCurrent' => array( 'type' => 'boolean', 'default' => false ),
					'showMissing' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			I18n::script( 'lingua-bridge-language-switcher-block' );
		}
	}

	public static function render_language_switcher( $attributes = array(), $content = '', $block = null ) {
		return Frontend::render_switcher( is_array( $attributes ) ? $attributes : array() );
	}
}
