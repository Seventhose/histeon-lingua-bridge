<?php
/**
 * Plugin Name: Lingua Bridge
 * Description: Collega contenuti WordPress per lingua e rende visibile lo stato delle traduzioni nell'amministrazione.
 * Version: 1.14.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: lingua-bridge
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/Seventhose/histeon-lingua-bridge
 */

namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LINGUA_BRIDGE_VERSION', '1.14.0' );
define( 'LINGUA_BRIDGE_FILE', __FILE__ );
define( 'LINGUA_BRIDGE_DIR', __DIR__ . '/' );

require_once LINGUA_BRIDGE_DIR . 'includes/class-i18n.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-languages.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-storage.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-fields.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-translations.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-abilities.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-connector.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-importer.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-admin.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-frontend.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-routing.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-blocks.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-router.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-histos.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-archives.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-strings.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-menus.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-locale.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-editor.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-woocommerce.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-currencies.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-graphql.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-xliff.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-string-import.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-memory.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-updates.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-menu-switcher.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-machine-translation.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-release-updater.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-links.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-string-scan.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-queue.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-terms.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-rest.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-cli.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-media.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-translators.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-switcher.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-seo.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-sync.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-template-parts.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-acf.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-requests.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-fluent-forms.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-fluent-crm.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-auto.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-purge.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-po.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-assignments.php';
require_once LINGUA_BRIDGE_DIR . 'includes/class-static-search.php';

register_activation_hook(
	__FILE__,
	static function () {
		Storage::install();
		Routing::activate();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		Routing::deactivate();
	}
);

I18n::init();

add_action(
	'plugins_loaded',
	static function () {
		Storage::maybe_upgrade();
		add_action( 'before_delete_post', array( Translations::class, 'on_post_deleted' ) );
		add_action(
			'save_post',
			static function ( $post_id ) {
				Translations::forget_hash( $post_id );
				Storage::content_changed();
			}
		);
		Translations::init_protection();
		Admin::init();
		Importer::init();
		Abilities::init();
		Connector::init();
		Frontend::init();
		Routing::init();
		Blocks::init();
		Histos::init();
		Archives::init();
		Strings::init();
		Menus::init();
		Locale::init();
		Editor::init();
		WooCommerce::init();
		Currencies::init();
		GraphQL::init();
		Xliff::init();
		StringImport::init();
		Memory::init();
		MenuSwitcher::init();
		MachineTranslation::init();
		ReleaseUpdater::init();
		Links::init();
		Queue::init();
		Switcher::init();
		Seo::init();
		Sync::init();
		TemplateParts::init();
		Acf::init();
		Requests::init();
		FluentForms::init();
		FluentCrm::init();
		Auto::init();
		Purge::init();
		Po::init();
		Assignments::init();
		StaticSearch::init();
		Terms::init();
		Rest::init();
		Cli::init();
		Media::init();
		Translators::init();
	}
);
