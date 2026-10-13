<?php
/**
 * WP-CLI: wp webto vejledning status|liste.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Webto Vejledning: dækning og liste.
 */
class Webto_Vejledning_CLI {

	/**
	 * Viser, hvad der mangler en vejledning, og hvilke vejledninger der er forældede (samme svar som ability
	 * webto-vejledning/status). Afslutter med fejlkode 1, når noget mangler, så den kan bruges i en slutkontrol.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : tekst eller json.
	 * ---
	 * default: tekst
	 * options:
	 *   - tekst
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp webto vejledning status
	 *     wp webto vejledning status --format=json
	 *
	 * @param array<int, string>    $args       Argumenter.
	 * @param array<string, string> $assoc_args Valg.
	 */
	public function status( $args, $assoc_args ) {
		$s = webto_vejledning_status();
		if ( 'json' === ( $assoc_args['format'] ?? 'tekst' ) ) {
			WP_CLI::line( (string) wp_json_encode( $s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		} else {
			foreach ( $s['manglende'] as $m ) {
				WP_CLI::line( sprintf( 'MANGLER   %-12s %-28s %-32s -> %s%s', $m['type'], $m['navn'], $m['noegle'], $m['forslag'], '' !== $m['udvider'] ? ' (udvider: ' . $m['udvider'] . ')' : '' ) );
			}
			foreach ( $s['foraeldede'] as $o ) {
				WP_CLI::line( sprintf( 'FORÆLDET  %-40s %s: %s', $o['titel'], $o['fil'], implode( ', ', array_map( static fn( $k ) => $k['kilde'] . ' (' . $k['grund'] . ')', $o['kilder'] ) ) ) );
			}
			if ( $s['antal'] ) {
				WP_CLI::line( '' );
				WP_CLI::line( $s['instruktion'] );
			}
		}
		if ( $s['ok'] ) {
			WP_CLI::success( 'Alt har en vejledning, og ingen er forældet.' );
		} else {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Lister alle vejledninger (id, kategori, kilde, titel).
	 *
	 * ## EXAMPLES
	 *
	 *     wp webto vejledning liste
	 *
	 * @param array<int, string>    $args       Argumenter.
	 * @param array<string, string> $assoc_args Valg.
	 */
	public function liste( $args, $assoc_args ) {
		$rows = array();
		foreach ( webto_vejledning_alle() as $g ) {
			$rows[] = array(
				'id'       => $g['id'],
				'kategori' => $g['kategori'],
				'kilde'    => $g['kilde'] . ( $g['rettet'] ? ' (rettet)' : '' ),
				'titel'    => $g['titel'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'kategori', 'kilde', 'titel' ) );
	}
}

WP_CLI::add_command( 'webto vejledning', 'Webto_Vejledning_CLI' );
