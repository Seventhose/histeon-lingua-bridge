<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp lingua …`: Lingua Bridge from the command line (N6 of the 1.5.10 verification), for automation, tests and
 * large sites. Each command calls the plugin's own functions, with their checks: it runs as the user given with
 * WP-CLI's `--user`, never as nobody, and like every other way in it writes drafts only.
 *
 *     wp lingua status --user=<amministratore>
 *     wp lingua create 12 14 --to=fr,de --user=…
 *     wp lingua xliff export 12 14 --to=fr --file=fr.xlf --user=…
 *     wp lingua xliff import fr.xlf --user=…
 *     wp lingua link 31 12 --language=fr --user=…
 *     wp lingua strings fr [--status=missing] --user=…
 *     wp lingua strings-scan fr --user=…
 *     wp lingua memory rebuild --user=…
 *     wp lingua queue --user=…
 */
final class Cli {
	public static function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'lingua', __CLASS__ );
		}
	}

	/** Every command needs a user: the permissions are checked content by content, as for that user. */
	private static function user() {
		if ( ! get_current_user_id() ) {
			\WP_CLI::error( __( 'Indica con --user chi lavora (per esempio --user=admin): i permessi si controllano contenuto per contenuto.', 'lingua-bridge' ) );
		}
	}

	/** @return list<string> The language codes of --to (or --language), checked. */
	private static function languages( $assoc, $key = 'to' ) {
		$codes = array_filter( array_map( 'trim', explode( ',', (string) ( $assoc[ $key ] ?? '' ) ) ) );
		if ( empty( $codes ) ) {
			\WP_CLI::error( sprintf( /* translators: %s: option name. */ __( 'Indica le lingue con --%s=fr,de.', 'lingua-bridge' ), $key ) );
		}
		foreach ( $codes as $code ) {
			if ( ! isset( Languages::all()[ $code ] ) || Languages::default_code() === $code ) {
				\WP_CLI::error( sprintf( /* translators: %s: language code. */ __( 'Lingua non configurata: %s.', 'lingua-bridge' ), $code ) );
			}
		}
		return array_values( $codes );
	}

	/**
	 * The languages and, for each, how many contents miss a translation, have a draft, are published or outdated.
	 *
	 * @subcommand status
	 */
	public function status( $args, $assoc ) {
		self::user();
		$summary = Connector::work_summary();
		$rows = array();
		foreach ( Languages::all() as $code => $label ) {
			$totals = $summary['totals'][ $code ] ?? array();
			$rows[] = array(
				'lingua'      => $code,
				'nome'        => $label,
				'originale'   => Languages::default_code() === $code ? 'sì' : '',
				'da_tradurre' => (string) ( $totals['missing'] ?? '' ),
				'bozze'       => (string) ( $totals['draft'] ?? '' ),
				'da_aggiornare' => (string) ( $totals['stale'] ?? '' ),
				'pubblicate'  => (string) ( $summary['published'][ $code ] ?? '' ),
			);
		}
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'lingua', 'nome', 'originale', 'da_tradurre', 'bozze', 'da_aggiornare', 'pubblicate' ) );
	}

	/**
	 * Creates the drafts to translate of the given contents.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : The originals.
	 *
	 * --to=<languages>
	 * : Comma-separated language codes.
	 *
	 * @subcommand create
	 */
	public function create( $args, $assoc ) {
		self::user();
		$languages = self::languages( $assoc );
		$failed = 0;
		foreach ( array_map( 'absint', $args ) as $id ) {
			foreach ( $languages as $language ) {
				$draft = Translations::create_draft( $id, $language );
				if ( is_wp_error( $draft ) ) {
					++$failed;
					\WP_CLI::warning( sprintf( '%d %s: %s', $id, $language, $draft->get_error_message() ) );
				} else {
					\WP_CLI::log( sprintf( '%d %s → %d', $id, $language, $draft ) );
				}
			}
		}
		$failed ? \WP_CLI::error( sprintf( /* translators: %d: failed drafts. */ __( '%d bozze non create.', 'lingua-bridge' ), $failed ) ) : \WP_CLI::success( __( 'Bozze create.', 'lingua-bridge' ) );
	}

	/**
	 * XLIFF: `export <id>... --to=<language> [--file=<file>]` or `import <file>`.
	 *
	 * @subcommand xliff
	 */
	public function xliff( $args, $assoc ) {
		self::user();
		$action = (string) array_shift( $args );
		if ( 'export' === $action ) {
			$language = self::languages( $assoc )[0];
			$export = Xliff::export( array_map( 'absint', $args ), $language );
			if ( ! empty( $assoc['file'] ) ) {
				file_put_contents( (string) $assoc['file'], $export['xml'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				/* translators: 1: contents, 2: file. */
				\WP_CLI::success( sprintf( __( '%1$d contenuti in %2$s.', 'lingua-bridge' ), $export['count'], (string) $assoc['file'] ) );
				return;
			}
			\WP_CLI::line( $export['xml'] );
			return;
		}
		if ( 'import' === $action ) {
			$file = (string) ( $args[0] ?? '' );
			if ( '' === $file || ! is_readable( $file ) ) {
				\WP_CLI::error( __( 'Indica un file XLIFF leggibile.', 'lingua-bridge' ) );
			}
			$results = Xliff::import( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( is_wp_error( $results ) ) {
				\WP_CLI::error( $results->get_error_message() );
			}
			\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $results, array( 'post', 'language', 'ok', 'draft', 'untranslated', 'message' ) );
			return;
		}
		\WP_CLI::error( __( 'Usa: wp lingua xliff export <id>... --to=<lingua> [--file=…] oppure wp lingua xliff import <file>.', 'lingua-bridge' ) );
	}

	/**
	 * Links an existing content as the translation of an original: `link <translation> <original> --language=<code>`.
	 *
	 * @subcommand link
	 */
	public function link( $args, $assoc ) {
		self::user();
		$language = self::languages( $assoc, 'language' )[0];
		$result = Translations::link_existing( absint( $args[1] ?? 0 ), absint( $args[0] ?? 0 ), $language );
		is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( __( 'Collegato.', 'lingua-bridge' ) );
	}

	/**
	 * The strings of a language: `strings <code> [--status=missing|draft|published]`.
	 *
	 * @subcommand strings
	 */
	public function strings( $args, $assoc ) {
		self::user();
		if ( ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( __( 'Non hai i permessi necessari.', 'lingua-bridge' ) );
		}
		$language = (string) ( $args[0] ?? '' );
		$status = isset( $assoc['status'] ) ? array( (string) $assoc['status'] ) : array();
		$items = Strings::query( $language, array( 'status' => $status, 'limit' => 200 ) )['items'];
		\WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $items, array( 'id', 'context', 'status', 'source', 'translation' ) );
	}

	/**
	 * «Trova i testi da tradurre» for a language: `strings-scan <code>`.
	 *
	 * @subcommand strings-scan
	 */
	public function strings_scan( $args, $assoc ) {
		self::user();
		$result = StringScan::scan( (string) ( $args[0] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		/* translators: 1: pages, 2: texts found, 3: new strings. */
		\WP_CLI::success( sprintf( __( 'Pagine confrontate: %1$d. Testi ancora nella lingua originale: %2$d, di cui %3$d nuovi nell’elenco.', 'lingua-bridge' ), $result['pages'], $result['found'], $result['added'] ) );
	}

	/**
	 * The translation memory: `memory rebuild` learns again from every translation, all at once.
	 *
	 * @subcommand memory
	 */
	public function memory( $args, $assoc ) {
		self::user();
		if ( 'rebuild' !== ( $args[0] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( __( 'Usa: wp lingua memory rebuild (con un utente che può ricostruire la memoria).', 'lingua-bridge' ) );
		}
		Memory::clear();
		do {
			Memory::build_step();
		} while ( 'done' !== get_option( Memory::BUILT_OPTION ) );
		\WP_CLI::success( sprintf( /* translators: %s: pairs per language. */ __( 'Memoria ricostruita: %s.', 'lingua-bridge' ), wp_json_encode( Memory::counts() ) ) );
	}

	/**
	 * Works the queue of the given user now, until nothing waits.
	 *
	 * @subcommand queue
	 */
	public function queue( $args, $assoc ) {
		self::user();
		while ( Queue::step( get_current_user_id() ) > 0 ) {
			\WP_CLI::log( wp_json_encode( Queue::summary() ) );
		}
		$summary = Queue::summary();
		/* translators: 1: waiting, 2: done, 3: failed. */
		\WP_CLI::success( sprintf( __( 'In attesa: %1$d · fatti: %2$d · non riusciti: %3$d', 'lingua-bridge' ), $summary['queued'], $summary['done'], $summary['failed'] ) );
	}
}
