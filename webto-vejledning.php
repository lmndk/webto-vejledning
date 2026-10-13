<?php
/**
 * Plugin Name:       Webto Vejledning
 * Description:       Vejledning til dit website: sitets egne funktioner og blokke, WordPress, plugins og Kadence. Kun for brugere, der er logget ind.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Webto
 * Author URI:        https://webto.dk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://webto.dk/plugins/webto-vejledning
 * Text Domain:       webto-vejledning
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

define( 'WEBTO_VEJLEDNING_VERSION', '1.0.0' );
define( 'WEBTO_VEJLEDNING_DIR', __DIR__ );
define( 'WEBTO_VEJLEDNING_URL', plugin_dir_url( __FILE__ ) );

/*
 * Opbygning (se README.md):
 *   markdown.php  Markdown + frontmatter -> HTML (ingen biblioteker).
 *   kilder.php    Samler alle vejledninger i ét indeks: faste (indhold/), automatiske (blokke, indholdstyper, menuer),
 *                 sitets egne filer (temaets vejledning/ og blokkenes vejledning.md) og rettelser/egne i databasen.
 *   pladsholdere.php [[farver]], [[skriftstoerrelser]], [[afstande]] fra theme.json (Kadence-vejledningen).
 *   status.php    Dækning og forældede vejledninger + instruktionen til AI'en.
 *   admin.php     Menuen "Vejledning" i wp-admin (oversigt, søgning, visning, ret/ny, dækning).
 *   adminbar.php  Knappen "Vejledning" i adminbaren og panelet "På denne side" (kun for brugere, der er logget ind).
 *   rest.php      Søgeindekset til panelet.
 *   abilities.php webto-vejledning/status, /hent og /gem (Abilities API, synlige for AI'en via Novamira).
 *   cli.php       wp webto vejledning status|liste.
 *   opdatering.php Opdateringer fra GitHub (automatisk; aldrig for kildekoden i skabelonen).
 *   dashboard.php Kortet "Vejledning" i Webto Dashboards kontrolpanel (via hooket webto_dashboard_tiles).
 */
foreach ( array( 'markdown', 'kilder', 'pladsholdere', 'status', 'admin', 'adminbar', 'rest', 'abilities', 'cli', 'dashboard', 'opdatering' ) as $webto_vejledning_file ) {
	require_once WEBTO_VEJLEDNING_DIR . '/includes/' . $webto_vejledning_file . '.php';
}
unset( $webto_vejledning_file );

/**
 * Versionsnummer ud fra filens ændringstid, så browseren altid får den nyeste fil (som temaet).
 *
 * @param string $relative Sti relativt til pluginnet.
 */
function webto_vejledning_asset_version( string $relative ): string {
	$file = WEBTO_VEJLEDNING_DIR . '/' . ltrim( $relative, '/' );
	return file_exists( $file ) ? (string) filemtime( $file ) : WEBTO_VEJLEDNING_VERSION;
}

/**
 * Må brugeren læse vejledningen? (Alle, der kan skrive indhold.)
 */
function webto_vejledning_can_read(): bool {
	return is_user_logged_in() && current_user_can( (string) apply_filters( 'webto_vejledning_cap_read', 'edit_posts' ) );
}

/**
 * Må brugeren rette og skrive vejledninger i wp-admin? (Redaktører og administratorer.)
 */
function webto_vejledning_can_edit(): bool {
	return is_user_logged_in() && current_user_can( (string) apply_filters( 'webto_vejledning_cap_edit', 'edit_others_pages' ) );
}

/**
 * Er brugeren fra Webto? Kun Webto ser værktøjerne til at vedligeholde vejledningen (Dækning og nøgler), ikke kunden -
 * heller ikke en kunde, der er administrator. Webto = en bruger med en @webto.dk-mail, eller alle på et
 * udviklingssite (homelab: *.ts.net, homelab, localhost). Konstanten WEBTO_VEJLEDNING_WEBTO vinder altid.
 */
function webto_vejledning_is_webto(): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( defined( 'WEBTO_VEJLEDNING_WEBTO' ) ) {
		return (bool) WEBTO_VEJLEDNING_WEBTO;
	}
	$email   = strtolower( (string) wp_get_current_user()->user_email );
	$domains = (array) apply_filters( 'webto_vejledning_webto_domaener', array( 'webto.dk' ) );
	foreach ( $domains as $domain ) {
		if ( str_ends_with( $email, '@' . strtolower( (string) $domain ) ) ) {
			return true;
		}
	}
	return webto_vejledning_is_dev_site();
}

/**
 * Kører sitet på et udviklingssted (homelab via Tailscale, localhost)? Samme regel som temaets mail-spærre.
 */
function webto_vejledning_is_dev_site(): bool {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$dev  = str_ends_with( $host, '.ts.net' ) || in_array( $host, array( 'homelab', 'localhost', '::1', '[::1]' ), true ) || str_starts_with( $host, '127.' );
	return (bool) apply_filters( 'webto_vejledning_udvikling', $dev );
}

// Rettelser og egne vejledninger fra wp-admin gemmes som en skjult indholdstype (ingen egen skærm, ingen adresse).
add_action(
	'init',
	static function () {
		register_post_type(
			'webto_vejledning',
			array(
				'label'               => __( 'Vejledninger', 'webto-vejledning' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'supports'            => array( 'title', 'editor', 'revisions' ),
			)
		);
	}
);
