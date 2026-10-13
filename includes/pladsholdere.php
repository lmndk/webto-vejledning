<?php
/**
 * Pladsholdere i vejledningerne ([[navn]] på en linje for sig): tabeller med sitets egne værdier fra theme.json, så
 * "Byg selv med Kadence" altid viser netop dette sites farver, skriftstørrelser og afstande.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'webto_vejledning_pladsholder',
	static function ( string $html, string $name ): string {
		switch ( $name ) {
			case 'farver':
				return webto_vejledning_ph_colors();
			case 'skriftstoerrelser':
				return webto_vejledning_ph_font_sizes();
			case 'afstande':
				return webto_vejledning_ph_spacing();
		}
		return $html;
	},
	10,
	2
);

/**
 * Temaets palet med Kadences palettenummer ved siden af (fra temaets Kadence-bro, når den findes).
 */
function webto_vejledning_ph_colors(): string {
	$palette = (array) ( wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) ?: array() );
	if ( ! $palette ) {
		return '';
	}
	$kadence = array();
	if ( function_exists( 'webto_kadence_palette' ) ) {
		foreach ( webto_kadence_palette() as $var => $value ) {
			if ( preg_match( '/^var\(--wp--preset--color--([a-z0-9-]+)\)$/', $value, $m ) ) {
				$kadence[ $m[1] ][] = str_replace( '--global-palette', '', $var );
			}
		}
	}
	$rows = '';
	foreach ( $palette as $c ) {
		$slug  = (string) ( $c['slug'] ?? '' );
		$color = sanitize_hex_color( (string) ( $c['color'] ?? '' ) ) ?: '';
		$rows .= '<tr><td><span class="wv-swatch" style="background-color:' . esc_attr( $color ) . '"></span> ' . esc_html( (string) ( $c['name'] ?? $slug ) ) . '</td>'
			. '<td><code>' . esc_html( $color ) . '</code></td>'
			. '<td>' . esc_html( isset( $kadence[ $slug ] ) ? sprintf( /* translators: %s: numre */ __( 'Palette %s', 'webto-vejledning' ), implode( ', ', $kadence[ $slug ] ) ) : '' ) . '</td></tr>';
	}
	return '<div class="wv-table"><table><thead><tr><th scope="col">' . esc_html__( 'Farve', 'webto-vejledning' ) . '</th><th scope="col">' . esc_html__( 'Kode', 'webto-vejledning' ) . '</th><th scope="col">' . esc_html__( 'I Kadence', 'webto-vejledning' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
}

/**
 * En CSS-længde i pixels (rem/em regnes med 16 px), så kunden kan forholde sig til den.
 *
 * @param string $length Fx 1.0625rem.
 */
function webto_vejledning_ph_px( string $length ): string {
	if ( preg_match( '/^\s*([\d.]+)\s*(rem|em|px)\s*$/', $length, $m ) ) {
		return (string) round( (float) $m[1] * ( 'px' === $m[2] ? 1 : 16 ) );
	}
	return trim( $length );
}

/**
 * En størrelse fra theme.json i pixels (flydende størrelser vises som "fra - til px" for mobil - computer).
 *
 * @param array<string, mixed> $s Størrelsen.
 */
function webto_vejledning_ph_size( array $s ): string {
	if ( ! empty( $s['fluid'] ) && is_array( $s['fluid'] ) ) {
		return webto_vejledning_ph_px( (string) ( $s['fluid']['min'] ?? '' ) ) . ' - ' . webto_vejledning_ph_px( (string) ( $s['fluid']['max'] ?? '' ) ) . ' px';
	}
	$size = (string) ( $s['size'] ?? '' );
	if ( preg_match( '/^clamp\(([^,]+),[^,]+,([^)]+)\)$/', $size, $m ) ) {
		return webto_vejledning_ph_px( $m[1] ) . ' - ' . webto_vejledning_ph_px( $m[2] ) . ' px';
	}
	return webto_vejledning_ph_px( $size ) . ' px';
}

/**
 * Skriftstørrelserne og Kadences tilsvarende størrelse.
 */
function webto_vejledning_ph_font_sizes(): string {
	$sizes = (array) ( wp_get_global_settings( array( 'typography', 'fontSizes', 'theme' ) ) ?: array() );
	$map   = function_exists( 'webto_kadence_font_sizes' ) ? array_flip( array_map( static fn( $v ) => preg_replace( '/^var\(--wp--preset--font-size--(.+)\)$/', '$1', $v ), webto_kadence_font_sizes() ) ) : array();
	$rows  = '';
	foreach ( $sizes as $s ) {
		$slug  = (string) ( $s['slug'] ?? '' );
		$rows .= '<tr><td>' . esc_html( (string) ( $s['name'] ?? $slug ) ) . '</td><td>' . esc_html( webto_vejledning_ph_size( $s ) ) . '</td><td>' . esc_html( isset( $map[ $slug ] ) ? strtoupper( (string) $map[ $slug ] ) : '' ) . '</td></tr>';
	}
	return $rows ? '<div class="wv-table"><table><thead><tr><th scope="col">' . esc_html__( 'Størrelse', 'webto-vejledning' ) . '</th><th scope="col">' . esc_html__( 'Mobil - computer', 'webto-vejledning' ) . '</th><th scope="col">' . esc_html__( 'I Kadence', 'webto-vejledning' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '';
}

/**
 * Afstandene og Kadences tilsvarende trin.
 */
function webto_vejledning_ph_spacing(): string {
	$sizes = (array) ( wp_get_global_settings( array( 'spacing', 'spacingSizes', 'theme' ) ) ?: array() );
	$map   = array( '20' => 'XXS', '30' => 'XS', '40' => 'SM', '50' => 'MD', '60' => 'LG', '70' => 'XL' );
	$rows  = '';
	foreach ( $sizes as $s ) {
		$slug  = (string) ( $s['slug'] ?? '' );
		// Kun Kadences navn: temaets egne navne (XS, S, M ...) ville forveksles med Kadences (XXS, XS, SM ...).
		$label = function_exists( 'webto_kadence_palette' ) ? ( $map[ $slug ] ?? '' ) : (string) ( $s['name'] ?? $slug );
		if ( '' !== $label ) {
			$rows .= '<tr><td>' . esc_html( $label ) . '</td><td>' . esc_html( webto_vejledning_ph_size( $s ) ) . '</td></tr>';
		}
	}
	return $rows ? '<div class="wv-table"><table><thead><tr><th scope="col">' . esc_html__( 'I Kadence', 'webto-vejledning' ) . '</th><th scope="col">' . esc_html__( 'Mobil - computer', 'webto-vejledning' ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : '';
}
