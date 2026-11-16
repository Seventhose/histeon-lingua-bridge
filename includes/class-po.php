<?php
namespace LinguaBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C31 of the competition list (1.11.0): the strings of the site in a PO file, one language at a time, for the
 * programs and the people that work with gettext. The context of a string is its `msgctxt`; what comes back from a
 * PO becomes drafts, approved as the ones written by an AI. A published translation is never replaced by a file.
 */
final class Po {
	/** At most so many rows in a file, and so many bytes read back. */
	const MAX_ROWS = 5000;
	const MAX_BYTES = 5242880;

	public static function init() {
		add_action( 'admin_post_lingua_bridge_strings_po', array( __CLASS__, 'handle' ) );
	}

	/** On the strings screen: «Scarica PO» and «Aggiungi da PO» for the language shown. */
	public static function render_forms( $language ) {
		echo '<form method="post" enctype="multipart/form-data" class="lb-toolbar lb-strings__po" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="lingua_bridge_strings_po"><input type="hidden" name="language" value="' . esc_attr( (string) $language ) . '">';
		wp_nonce_field( 'lingua_bridge_strings_po' );
		echo '<button class="button" name="op" value="export">' . esc_html__( 'Scarica PO', 'lingua-bridge' ) . '</button> <label class="screen-reader-text" for="lb-po-file">' . esc_html__( 'File PO', 'lingua-bridge' ) . '</label><input id="lb-po-file" type="file" name="po" accept=".po,text/x-gettext-translation"> <button class="button" name="op" value="import">' . esc_html__( 'Aggiungi da PO', 'lingua-bridge' ) . '</button><span class="description"> ' . esc_html__( 'Le traduzioni del file arrivano come bozze da approvare; quelle già pubblicate restano.', 'lingua-bridge' ) . '</span></form>';
	}

	public static function handle() {
		check_admin_referer( 'lingua_bridge_strings_po' );
		// As the strings screen: the site's administrators.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Non hai i permessi necessari.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		$language = isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '';
		if ( ! isset( Languages::all()[ $language ] ) || Languages::default_code() === $language ) {
			wp_die( esc_html__( 'Lingua non disponibile.', 'lingua-bridge' ), '', array( 'response' => 403 ) );
		}
		$back = add_query_arg( array( 'page' => 'lingua-bridge-strings', 'language' => $language ), admin_url( 'admin.php' ) );
		if ( 'export' === ( isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '' ) ) {
			nocache_headers();
			header( 'Content-Type: text/x-gettext-translation; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( 'stringhe-' . $language . '.po' ) . '"' );
			echo self::export( $language ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a PO file, quoted.
			exit;
		}
		$file = isset( $_FILES['po']['tmp_name'] ) && is_uploaded_file( $_FILES['po']['tmp_name'] ) ? (string) file_get_contents( $_FILES['po']['tmp_name'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an uploaded file, read as text and parsed.
		$result = self::import( $file, $language );
		/* translators: 1: drafts, 2: already published, 3: unknown. */
		$args = is_wp_error( $result ) ? array( 'lb_error' => Admin::stash_error( $result->get_error_message() ) ) : array( 'lb_message' => Admin::stash_message( sprintf( __( 'Dal file PO: %1$d bozze da approvare, %2$d già pubblicate lasciate com’erano, %3$d testi che il sito non ha.', 'lingua-bridge' ), $result['drafts'], $result['published'], $result['unknown'] ) ) );
		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}

	/** The strings of `$language` as a PO file: every one, translated or not. */
	public static function export( $language ) {
		$lines = array(
			'msgid ""',
			'msgstr ""',
			self::quote( "Content-Type: text/plain; charset=UTF-8\n" ),
			self::quote( 'Language: ' . str_replace( '-', '_', $language ) . "\n" ),
			self::quote( "X-Generator: Lingua Bridge\n" ),
			'',
		);
		$offset = 0;
		do {
			$page = Strings::query( $language, array( 'limit' => 200, 'offset' => $offset ) );
			foreach ( $page['items'] as $row ) {
				if ( 'draft' === $row['status'] ) {
					$lines[] = '#, fuzzy';
				}
				$lines[] = 'msgctxt ' . self::quote( (string) $row['context'] );
				$lines[] = 'msgid ' . self::quote( (string) $row['source'] );
				$lines[] = 'msgstr ' . self::quote( (string) $row['translation'] );
				$lines[] = '';
			}
			$offset += 200;
		} while ( count( $page['items'] ) === 200 && $offset < self::MAX_ROWS );
		return implode( "\n", $lines );
	}

	/**
	 * The entries of a PO file: context, source and translation, the header and the plural forms left out.
	 *
	 * @return list<array{context: string, source: string, translation: string}>|\WP_Error
	 */
	public static function parse( $text ) {
		if ( ! is_string( $text ) || '' === $text || strlen( $text ) > self::MAX_BYTES || ! mb_check_encoding( $text, 'UTF-8' ) ) {
			return new \WP_Error( 'lingua_po_invalid', __( 'File PO vuoto, troppo grande o non in UTF-8.', 'lingua-bridge' ) );
		}
		$entries = array();
		$entry = array();
		$field = '';
		$flush = static function () use ( &$entry, &$entries ) {
			if ( isset( $entry['msgid'] ) && '' !== $entry['msgid'] && ! isset( $entry['msgid_plural'] ) ) {
				$entries[] = array( 'context' => (string) ( $entry['msgctxt'] ?? '' ), 'source' => $entry['msgid'], 'translation' => (string) ( $entry['msgstr'] ?? '' ) );
			}
			$entry = array();
		};
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				$flush();
				$field = '';
				continue;
			}
			if ( '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"$/s', $line, $m ) ) {
				if ( 'msgctxt' === $m[1] || ( 'msgid' === $m[1] && isset( $entry['msgid'] ) ) ) {
					if ( isset( $entry['msgid'] ) ) {
						$flush();
					}
				}
				$field = 'msgstr[0]' === $m[1] ? 'msgstr' : $m[1];
				$entry[ $field ] = ( $entry[ $field ] ?? '' ) . self::unquote( $m[2] );
			} elseif ( '' !== $field && preg_match( '/^"(.*)"$/s', $line, $m ) ) {
				$entry[ $field ] .= self::unquote( $m[1] );
			}
			if ( count( $entries ) >= self::MAX_ROWS ) {
				break;
			}
		}
		$flush();
		return $entries;
	}

	/**
	 * The translations of a PO file as drafts of `$language`: only for strings the site has, only where there is no
	 * published translation.
	 *
	 * @return array{drafts: int, published: int, unknown: int, empty: int}|\WP_Error
	 */
	public static function import( $text, $language ) {
		$entries = self::parse( $text );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		global $wpdb;
		$out = array( 'drafts' => 0, 'published' => 0, 'unknown' => 0, 'empty' => 0 );
		foreach ( $entries as $entry ) {
			if ( '' === trim( $entry['translation'] ) ) {
				++$out['empty'];
				continue;
			}
			$contexts = in_array( $entry['context'], Strings::CONTEXTS, true ) ? array( $entry['context'] ) : Strings::CONTEXTS;
			$row = null;
			foreach ( $contexts as $context ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, translation FROM ' . Strings::table() . ' WHERE source_hash = %s AND language = %s', Strings::key( $context, $entry['source'] ), $language ), ARRAY_A );
				if ( $row ) {
					break;
				}
			}
			if ( ! $row ) {
				++$out['unknown'];
			} elseif ( 'published' === $row['status'] ) {
				++$out['published'];
			} elseif ( ! is_wp_error( Strings::save( (int) $row['id'], $entry['translation'], 'draft' ) ) ) {
				++$out['drafts'];
			}
		}
		return $out;
	}

	private static function quote( $text ) {
		return '"' . addcslashes( (string) $text, "\\\"\n\r\t" ) . '"';
	}

	private static function unquote( $text ) {
		return stripcslashes( (string) $text );
	}
}
