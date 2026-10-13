<?php
/**
 * Kortet "Vejledning" i Webto Dashboards kontrolpanel (kun når Webto Dashboard er aktivt - pluginnerne kender
 * hinanden via hooket webto_dashboard_tiles, ikke via hårde afhængigheder): søgning, "Ny her? Start her" og de senest
 * opdaterede vejledninger.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

/**
 * De senest opdaterede, skrevne vejledninger (ikke de automatiske).
 *
 * @param int $count Antal.
 * @return array<int, array<string, mixed>>
 */
function webto_vejledning_latest( int $count = 3 ): array {
	// Kun sitets egne (temaets filer, blokkenes vejledning.md, rettelser og egne fra wp-admin): de faste i pluginnet
	// har alle pluginnets installationstid og siger ikke kunden noget nyt.
	$guides = array_filter( webto_vejledning_alle(), static fn( $g ) => $g['skrevet'] && ( 'fast' !== $g['kilde'] || $g['rettet'] ) && ( 'auto' !== $g['kilde'] || $g['rettet'] ) );
	foreach ( $guides as $id => $g ) {
		$guides[ $id ]['_tid'] = webto_vejledning_checked_at( $g );
	}
	uasort( $guides, static fn( $a, $b ) => $b['_tid'] <=> $a['_tid'] );
	return array_slice( array_values( $guides ), 0, $count );
}

add_filter(
	'webto_dashboard_tiles',
	static function ( array $cards ): array {
		if ( ! webto_vejledning_can_read() ) {
			return $cards;
		}
		$src   = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'webto-vejledning/v1/indeks' ) );
		$start = webto_vejledning_get( 'fast/kom-i-gang' );
		$when  = static fn( int $t ) => function_exists( 'webto_dashboard_when' ) ? webto_dashboard_when( $t ) : wp_date( 'j. M', $t );

		$html  = '<div class="wv wv--kort">';
		$html .= '<div class="wv-search" role="search" data-wv-search data-wv-src="' . esc_url( $src ) . '">'
			. '<label class="screen-reader-text" for="wv-kort-q">' . esc_html__( 'Søg i vejledningen', 'webto-vejledning' ) . '</label>'
			. '<input class="wv-search__input" type="search" id="wv-kort-q" autocomplete="off" placeholder="' . esc_attr__( 'Søg, fx billede, menu eller SEO', 'webto-vejledning' ) . '" data-wv-q>'
			. '<p class="wv-search__count" aria-live="polite" data-wv-count></p>'
			. '<ul class="wv-list wv-search__results" data-wv-results hidden></ul></div>';
		$html .= '<div data-wv-browse>';
		if ( $start ) {
			$html .= '<a class="wd-start" href="' . esc_url( webto_vejledning_view_url( $start['id'] ) ) . '"><span class="dashicons dashicons-flag" aria-hidden="true"></span><span><strong>' . esc_html__( 'Ny her? Start her', 'webto-vejledning' ) . '</strong>' . esc_html( $start['resume'] ) . '</span></a>';
		}
		$latest = webto_vejledning_latest( 3 );
		if ( $latest ) {
			$html .= '<h3 class="wd-sub">' . esc_html__( 'Senest opdateret', 'webto-vejledning' ) . '</h3><ul class="wd-links">';
			foreach ( $latest as $g ) {
				$html .= '<li><a href="' . esc_url( webto_vejledning_view_url( $g['id'] ) ) . '"><span class="dashicons dashicons-media-text" aria-hidden="true"></span><span>' . esc_html( $g['titel'] ) . '</span><small>' . esc_html( $when( (int) $g['_tid'] ) ) . '</small></a></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</div></div>';

		$cards[] = array(
			'id'           => 'vejledning',
			'titel'        => __( 'Vejledning', 'webto-vejledning' ),
			'ikon'         => 'dashicons-book-alt',
			'link'         => array( 'url' => admin_url( 'admin.php?page=webto-vejledning' ), 'tekst' => __( 'Se alle', 'webto-vejledning' ) ),
			'html'         => $html,
			'omraade'      => 'hjaelp',
			'raekkefoelge' => 20,
		);
		return $cards;
	}
);

// Søgningen i kortet bruger vejledningens soeg.js (indekset hentes først, når man søger) og lidt CSS til kortet.
add_action(
	'admin_enqueue_scripts',
	static function () {
		if ( ! function_exists( 'webto_dashboard_is_screen' ) || ! webto_dashboard_is_screen() || ! webto_vejledning_can_read() ) {
			return;
		}
		wp_enqueue_style( 'webto-vejledning-kort', WEBTO_VEJLEDNING_URL . 'assets/kort.css', array(), webto_vejledning_asset_version( 'assets/kort.css' ) );
		wp_enqueue_script( 'webto-vejledning-soeg', WEBTO_VEJLEDNING_URL . 'assets/soeg.js', array(), webto_vejledning_asset_version( 'assets/soeg.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
);
