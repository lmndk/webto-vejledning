<?php
/**
 * Knappen "Vejledning" i adminbaren (alle sider, kun for brugere, der må læse vejledningen) og panelet "På denne side"
 * på sitet: vejledningerne til sidens blokke, indholdstype, side og funktioner, med søgning. Besøgende får intet.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blokkene i et stykke indhold (også inde i andre blokke og i genbrugte mønstre), i rækkefølge.
 *
 * @param array<int, array<string, mixed>> $blocks Fra parse_blocks().
 * @param int                              $depth  Beskytter mod mønstre, der henviser til sig selv.
 * @return string[]
 */
function webto_vejledning_block_names( array $blocks, int $depth = 0 ): array {
	$names = array();
	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			continue;
		}
		$names[] = $block['blockName'];
		if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) && $depth < 3 ) {
			$ref = get_post( (int) $block['attrs']['ref'] );
			if ( $ref && 'wp_block' === $ref->post_type ) {
				$names = array_merge( $names, webto_vejledning_block_names( parse_blocks( $ref->post_content ), $depth + 1 ) );
			}
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$names = array_merge( $names, webto_vejledning_block_names( $block['innerBlocks'], $depth ) );
		}
	}
	return array_values( array_unique( $names ) );
}

/**
 * Hvilket plugin en blok kommer fra (blokkens navnerum -> pluginnets mappe).
 *
 * @return array<string, string>
 */
function webto_vejledning_block_plugins(): array {
	return (array) apply_filters(
		'webto_vejledning_blok_plugins',
		array(
			'kadence'    => 'kadence-blocks',
			'fluentfom'  => 'fluentform',
			'fluentform' => 'fluentform',
		)
	);
}

/**
 * Nøglerne for et indlæg/en side: blokke, indholdstype, side-id og plugins bag blokkene.
 *
 * @param WP_Post $post Indlægget.
 * @return string[]
 */
function webto_vejledning_post_keys( WP_Post $post ): array {
	$keys    = array();
	$plugins = webto_vejledning_block_plugins();
	foreach ( webto_vejledning_block_names( parse_blocks( $post->post_content ) ) as $name ) {
		$keys[] = 'blok:' . $name;
		$ns     = strtok( $name, '/' );
		if ( isset( $plugins[ $ns ] ) ) {
			$keys[] = 'plugin:' . $plugins[ $ns ];
		}
	}
	if ( has_shortcode( $post->post_content, 'fluentform' ) ) {
		$keys[] = 'plugin:fluentform';
	}
	$keys[] = 'side:' . $post->ID;
	$keys[] = 'posttype:' . $post->post_type;
	return $keys;
}

/**
 * Nøglerne for den side, man står på (sitet).
 *
 * @return string[]
 */
function webto_vejledning_page_keys(): array {
	$keys = array();
	$post = is_singular() ? get_queried_object() : null;
	if ( $post instanceof WP_Post ) {
		$keys = webto_vejledning_post_keys( $post );
	} elseif ( is_home() || is_category() || is_tag() || is_author() || is_date() ) {
		$keys[] = 'posttype:post';
	} elseif ( is_post_type_archive() ) {
		$keys[] = 'posttype:' . (string) get_query_var( 'post_type' );
	}
	// Funktioner, der er på alle sider (fx headeren). Temaet kan melde flere.
	$features = function_exists( 'webto_hb_render' ) ? array( 'header-builder' ) : array();
	foreach ( (array) apply_filters( 'webto_vejledning_side_funktioner', $features, $post ) as $feature ) {
		$keys[] = 'funktion:' . $feature;
	}
	return array_values( array_unique( (array) apply_filters( 'webto_vejledning_side_noegler', $keys, $post ) ) );
}

/**
 * Nøglerne for den skærm, man står på i wp-admin.
 *
 * @return string[]
 */
function webto_vejledning_screen_keys(): array {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return array();
	}
	$keys = array();
	$post = 'post' === $screen->base && isset( $_GET['post'] ) ? get_post( absint( $_GET['post'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( $post ) {
		$keys = webto_vejledning_post_keys( $post );
	} elseif ( $screen->post_type ) {
		$keys[] = 'posttype:' . $screen->post_type;
	}
	if ( 'nav-menus' === $screen->base ) {
		$keys = array_merge( $keys, array_map( static fn( $slug ) => 'menu:' . $slug, array_keys( get_registered_nav_menus() ) ) );
	}
	return (array) apply_filters( 'webto_vejledning_skaerm_noegler', $keys, $screen );
}

add_action(
	'admin_bar_menu',
	static function ( WP_Admin_Bar $bar ) {
		if ( ! webto_vejledning_can_read() ) {
			return;
		}
		$keys = is_admin() ? webto_vejledning_screen_keys() : webto_vejledning_page_keys();
		$href = add_query_arg( array( 'page' => 'webto-vejledning', 'til' => rawurlencode( implode( ',', $keys ) ) ), admin_url( 'admin.php' ) );
		$bar->add_node(
			array(
				'id'    => 'webto-vejledning',
				'title' => '<span class="ab-icon dashicons dashicons-book-alt" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Vejledning', 'webto-vejledning' ) . '</span>',
				'href'  => $href,
				'meta'  => array( 'title' => __( 'Vejledning til denne side', 'webto-vejledning' ) ),
			)
		);
	},
	85
);

// Ikonet i adminbaren, også på mobil (hvor WordPress ellers skjuler de fleste punkter). Kun for dem, der ser knappen.
$webto_vejledning_ab_css = static function () {
	if ( is_admin_bar_showing() && webto_vejledning_can_read() ) {
		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar #wp-admin-bar-webto-vejledning .ab-icon{top:2px}'
			. '@media screen and (max-width:782px){#wpadminbar li#wp-admin-bar-webto-vejledning{display:block}'
			. '#wpadminbar #wp-admin-bar-webto-vejledning>.ab-item{width:52px;padding:0;text-align:center}'
			. '#wpadminbar #wp-admin-bar-webto-vejledning .ab-icon{top:0;margin:0;padding:0;width:52px}'
			. '#wpadminbar #wp-admin-bar-webto-vejledning .ab-icon:before{font-size:32px;width:52px;text-align:center}'
			. '#wpadminbar #wp-admin-bar-webto-vejledning .ab-label{display:none}}'
		);
	}
};
add_action( 'wp_enqueue_scripts', $webto_vejledning_ab_css );
add_action( 'admin_enqueue_scripts', $webto_vejledning_ab_css );
unset( $webto_vejledning_ab_css );

/**
 * Skal panelet med på denne side? Kun på sitet, med adminbar, for brugere, der må læse vejledningen.
 */
function webto_vejledning_panel_active(): bool {
	return ! is_admin() && is_admin_bar_showing() && webto_vejledning_can_read();
}

add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! webto_vejledning_panel_active() ) {
			return;
		}
		wp_enqueue_style( 'webto-vejledning-panel', WEBTO_VEJLEDNING_URL . 'assets/panel.css', array( 'dashicons' ), webto_vejledning_asset_version( 'assets/panel.css' ) );
		wp_enqueue_script( 'webto-vejledning-soeg', WEBTO_VEJLEDNING_URL . 'assets/soeg.js', array(), webto_vejledning_asset_version( 'assets/soeg.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_enqueue_script( 'webto-vejledning-panel', WEBTO_VEJLEDNING_URL . 'assets/panel.js', array(), webto_vejledning_asset_version( 'assets/panel.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
);

add_action(
	'wp_footer',
	static function () {
		if ( ! webto_vejledning_panel_active() ) {
			return;
		}
		$guides = webto_vejledning_for_keys( webto_vejledning_page_keys() );
		$cats   = webto_vejledning_kategorier();
		$src    = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'webto-vejledning/v1/indeks' ) );
		?>
		<dialog class="wv-panel" id="wv-panel" aria-labelledby="wv-panel-title">
			<div class="wv-panel__head">
				<h2 class="wv-panel__title" id="wv-panel-title"><?php esc_html_e( 'Vejledning til denne side', 'webto-vejledning' ); ?></h2>
				<button type="button" class="wv-panel__close" data-wv-close><?php esc_html_e( 'Luk', 'webto-vejledning' ); ?></button>
			</div>
			<div class="wv-panel__body">
				<div class="wv-search" role="search" data-wv-search data-wv-src="<?php echo esc_url( $src ); ?>">
					<label class="wv-search__label" for="wv-panel-q"><?php esc_html_e( 'Søg i hele vejledningen', 'webto-vejledning' ); ?></label>
					<input class="wv-search__input" type="search" id="wv-panel-q" autocomplete="off" data-wv-q>
					<p class="wv-search__count" aria-live="polite" data-wv-count></p>
					<ul class="wv-list wv-search__results" data-wv-results hidden></ul>
				</div>
				<div data-wv-browse>
					<?php if ( $guides ) : ?>
						<p class="wv-panel__intro"><?php esc_html_e( 'På denne side:', 'webto-vejledning' ); ?></p>
						<div class="wv-acc">
							<?php foreach ( $guides as $g ) : ?>
								<details class="wv-acc__item">
									<summary class="wv-acc__summary"><span class="wv-acc__title"><?php echo esc_html( $g['titel'] ); ?></span> <span class="wv-acc__cat"><?php echo esc_html( $cats[ $g['kategori'] ] ?? '' ); ?></span></summary>
									<div class="wv-body">
										<?php echo $g['html'] . $g['felter_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renset med wp_kses_post / esc_* i kilderne. ?>
										<p><a href="<?php echo esc_url( webto_vejledning_view_url( $g['id'] ) ); ?>"><?php esc_html_e( 'Åbn i vejledningen', 'webto-vejledning' ); ?></a></p>
									</div>
								</details>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="wv-panel__intro"><?php esc_html_e( 'Der er ingen vejledning til netop denne side. Søg herover, eller se hele vejledningen.', 'webto-vejledning' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<div class="wv-panel__foot">
				<a class="wv-panel__all" href="<?php echo esc_url( admin_url( 'admin.php?page=webto-vejledning' ) ); ?>"><?php esc_html_e( 'Se hele vejledningen', 'webto-vejledning' ); ?></a>
			</div>
		</dialog>
		<?php
	}
);
