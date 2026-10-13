<?php
/**
 * Opdateringer fra GitHub (planens "Opdatering ude hos kunderne", Lasse 2026-10-13).
 *
 * - Pluginnet spørger sit offentlige GitHub-repo (releases) via plugin-update-checker (MIT, vendor/). En ny version
 *   vises i WordPress som enhver anden pluginopdatering, og den installeres automatisk (auto_update_plugin).
 * - Udgivelser laves med plugins/udgiv.sh i skabelonens repo, og først efter en test på en kopi på homelab.
 *   Den forrige version ligger altid på GitHub, så den kan installeres igen.
 * - Kildekoden i skabelonen (markørfilen .udvikling) opdateres aldrig: dér tjekkes der slet ikke.
 * - GitHub-ejeren sættes i WEBTO_VEJLEDNING_GITHUB (eller filteret webto_vejledning_github). Er den tom, tjekkes der ikke.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

// Repoets adresse, fx https://github.com/<ejer>/webto-vejledning/ (tom = ingen opdateringer endnu).
if ( ! defined( 'WEBTO_VEJLEDNING_GITHUB' ) ) {
	define( 'WEBTO_VEJLEDNING_GITHUB', 'https://github.com/lmndk/webto-vejledning/' );
}

/**
 * Er det kildekoden i skabelonen (som aldrig må opdateres herfra)?
 */
function webto_vejledning_is_source(): bool {
	return file_exists( WEBTO_VEJLEDNING_DIR . '/.udvikling' );
}

add_action(
	'plugins_loaded',
	static function () {
		$repo = (string) apply_filters( 'webto_vejledning_github', WEBTO_VEJLEDNING_GITHUB );
		if ( '' === $repo || webto_vejledning_is_source() || ! is_readable( WEBTO_VEJLEDNING_DIR . '/vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
			return;
		}
		require_once WEBTO_VEJLEDNING_DIR . '/vendor/plugin-update-checker/plugin-update-checker.php';
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( $repo, WEBTO_VEJLEDNING_DIR . '/webto-vejledning.php', 'webto-vejledning' );
		// KUN den færdige zip-fil fra udgivelsen (bygget af udgiv.sh). GitHubs automatiske kildekode-zip indeholder
		// markøren .udvikling og ville stoppe alle senere opdateringer - mangler zip-filen, tilbydes udgivelsen ikke.
		$checker->getVcsApi()->enableReleaseAssets( '/webto-vejledning-[0-9.]+\.zip$/', \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS );
	}
);

// Automatisk opdatering er slået til for pluginnet (aldrig for kildekoden i skabelonen).
add_filter(
	'auto_update_plugin',
	static function ( $update, $item ) {
		if ( is_object( $item ) && isset( $item->plugin ) && 'webto-vejledning/webto-vejledning.php' === $item->plugin ) {
			return ! webto_vejledning_is_source();
		}
		return $update;
	},
	10,
	2
);
