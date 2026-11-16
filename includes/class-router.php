<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The optional "always-on router" (docs/V1-RELEASE.md): a small must-use plugin that keeps language URLs
 * (/en/), translated links, the html lang and hreflang working while Lingua Bridge is deactivated. It reuses
 * the plugin's own routing files (no copy of the logic), does nothing while the plugin is active, and stops on
 * its own if the plugin is deleted. Installed and removed from the settings.
 */
final class Router {
	const FILE   = 'lingua-bridge-router.php';
	const MARKER = 'Lingua Bridge router (generated)';

	public static function path() {
		return trailingslashit( WPMU_PLUGIN_DIR ) . self::FILE;
	}

	public static function installed() {
		$file = self::path();
		return is_readable( $file ) && false !== strpos( (string) file_get_contents( $file ), self::MARKER );
	}

	/** The must-use file, pointing at this copy of the plugin's includes. */
	public static function source( $includes_dir ) {
		$dir = var_export( (string) $includes_dir, true );
		$marker = self::MARKER;
		return <<<PHP
<?php
/**
 * Plugin Name: Lingua Bridge — router sempre attivo
 * Description: Mantiene gli URL di lingua (/en/), i link tradotti e hreflang anche con Lingua Bridge disattivato. Si installa e si rimuove da Lingua Bridge → Impostazioni.
 * {$marker}
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'LinguaBridge\\\\Routing' ) ) {
			return; // Lingua Bridge is active: it routes.
		}
		\$dir = {$dir};
		foreach ( array( 'class-languages.php', 'class-storage.php', 'class-routing.php', 'class-frontend.php' ) as \$file ) {
			if ( ! is_readable( \$dir . \$file ) ) {
				return; // The plugin was deleted: nothing left to route.
			}
		}
		foreach ( array( 'class-languages.php', 'class-storage.php', 'class-routing.php', 'class-frontend.php' ) as \$file ) {
			require_once \$dir . \$file;
		}
		\$settings = \\LinguaBridge\\Languages::settings();
		if ( empty( \$settings['keep_routing'] ) || ! \\LinguaBridge\\Storage::is_available() ) {
			return;
		}
		\\LinguaBridge\\Routing::init();
		\\LinguaBridge\\Frontend::init();
		// Deactivating the plugin flushed its rules away: put them back once.
		add_action(
			'init',
			static function () {
				if ( \\LinguaBridge\\Routing::enabled() && ! \\LinguaBridge\\Routing::rules_present() ) {
					flush_rewrite_rules( false );
				}
			},
			99
		);
	},
	20
);

PHP;
	}

	/** DISALLOW_FILE_MODS (or the file_mod_allowed filter): the site writes no code files, not even this one. */
	public static function can_write() {
		return ! function_exists( 'wp_is_file_mod_allowed' ) || wp_is_file_mod_allowed( 'lingua_bridge_router' );
	}

	/** Installs or removes the must-use file to match the setting. Returns an error message, or ''. */
	public static function sync( $enabled ) {
		$file = self::path();
		$writable = self::can_write();
		if ( ! $enabled ) {
			if ( self::installed() && ! $writable ) {
				return __( 'Le modifiche ai file sono disattivate su questo sito (DISALLOW_FILE_MODS): elimina a mano wp-content/mu-plugins/lingua-bridge-router.php.', 'lingua-bridge' );
			}
			if ( self::installed() && ! @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return __( 'Non riesco a rimuovere il router sempre attivo: elimina a mano wp-content/mu-plugins/lingua-bridge-router.php.', 'lingua-bridge' );
			}
			return '';
		}
		if ( file_exists( $file ) && ! self::installed() ) {
			return __( 'In wp-content/mu-plugins c’è già un file lingua-bridge-router.php che non è di Lingua Bridge: non lo sovrascrivo.', 'lingua-bridge' );
		}
		if ( ! $writable ) {
			return self::installed() ? '' : __( 'Le modifiche ai file sono disattivate su questo sito (DISALLOW_FILE_MODS): il router sempre attivo non è stato installato. Per installarlo a mano copia il codice mostrato sotto l’opzione in wp-content/mu-plugins/lingua-bridge-router.php.', 'lingua-bridge' );
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return __( 'La cartella wp-content/mu-plugins non si può creare: crea il router a mano (vedi la documentazione) o chiedi al tuo hosting.', 'lingua-bridge' );
		}
		if ( false === @file_put_contents( $file, self::source( LINGUA_BRIDGE_DIR . 'includes/' ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return __( 'La cartella wp-content/mu-plugins non è scrivibile: il router sempre attivo non è stato installato.', 'lingua-bridge' );
		}
		return '';
	}
}
