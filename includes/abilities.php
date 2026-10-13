<?php
/**
 * Abilities (WordPress' Abilities API), synlige for AI'en via Novamira (meta.mcp.public):
 *   webto-vejledning/status  Hvad mangler en vejledning, hvad er forældet, og præcis hvordan det rettes.
 *   webto-vejledning/hent    Én vejledning (frontmatter + Markdown), så den kan opdateres.
 *   webto-vejledning/gem     Skriver en vejledning i temaet - eller i databasen, hvis temaet ikke kan skrives.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		if ( function_exists( 'wp_register_ability_category' ) && ! wp_has_ability_category( 'webto' ) ) {
			wp_register_ability_category(
				'webto',
				array(
					'label'       => __( 'Webto', 'webto-vejledning' ),
					'description' => __( 'Webtos egne funktioner på sitet (vejledning, dashboard).', 'webto-vejledning' ),
				)
			);
		}
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$meta = static fn( bool $readonly ) => array(
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);

		wp_register_ability(
			'webto-vejledning/status',
			array(
				'label'               => __( 'Vejledning: status', 'webto-vejledning' ),
				'description'         => 'Webto Vejledning: lists everything on the site that is missing a customer guide (blocks, post types, ACF field groups, menu locations, active plugins, features) and every guide that is outdated because a source file changed after it was written. Returns exact file paths, frontmatter keys, a template and Danish writing rules. Call after building or changing any feature, block or fix; the task is not done until "antal" is 0.',
				'category'            => 'webto',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new stdClass(),
					'default'    => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ok'          => array( 'type' => 'boolean' ),
						'antal'       => array( 'type' => 'integer' ),
						'manglende'   => array( 'type' => 'array' ),
						'foraeldede'  => array( 'type' => 'array' ),
						'mappe'       => array( 'type' => 'string' ),
						'kan_skrive'  => array( 'type' => 'boolean' ),
						'instruktion' => array( 'type' => 'string' ),
						'skabelon'    => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static fn() => webto_vejledning_status(),
				'permission_callback' => 'webto_vejledning_can_read',
				'meta'                => $meta( true ),
			)
		);

		wp_register_ability(
			'webto-vejledning/hent',
			array(
				'label'               => __( 'Vejledning: hent', 'webto-vejledning' ),
				'description'         => 'Webto Vejledning: returns one guide by id (from status or the guide list): title, category, frontmatter keys (gaelder, kilder, opdateret), file path and the Markdown body, so it can be updated with webto-vejledning/gem. Without id: returns the list of all guides (id, titel, kategori, kilde).',
				'category'            => 'webto',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'string',
							'description' => 'Guide id, e.g. blok/webto/hero, tema/menuer, fast/wordpress-sider.',
						),
					),
					'default'    => array(),
				),
				'execute_callback'    => static function ( $input = array() ) {
					$id = is_array( $input ) ? (string) ( $input['id'] ?? '' ) : '';
					if ( '' === $id ) {
						return array_values( array_map( static fn( $g ) => array_intersect_key( $g, array_flip( array( 'id', 'titel', 'kategori', 'kilde', 'skrevet' ) ) ), webto_vejledning_alle() ) );
					}
					$g = webto_vejledning_get( $id );
					if ( ! $g ) {
						return new WP_Error( 'ukendt', 'Unknown guide id: ' . $id );
					}
					return array(
						'id'        => $g['id'],
						'titel'     => $g['titel'],
						'kategori'  => $g['kategori'],
						'resume'    => $g['resume'],
						'gaelder'   => $g['gaelder'],
						'kilder'    => $g['kilder'],
						'opdateret' => $g['opdateret'],
						'kilde'     => $g['kilde'],
						'fil'       => webto_vejledning_relative_file( $g ),
						'markdown'  => $g['markdown'] ?? '',
						'tekst'     => webto_vejledning_plain( $g['html'] ),
						'rettet'    => $g['rettet'],
					);
				},
				'permission_callback' => 'webto_vejledning_can_read',
				'meta'                => $meta( true ),
			)
		);

		wp_register_ability(
			'webto-vejledning/gem',
			array(
				'label'               => __( 'Vejledning: gem', 'webto-vejledning' ),
				'description'         => 'Webto Vejledning: writes a customer guide (Danish Markdown) to the theme: "fil" is vejledning/<name>.md or blocks/<block>/vejledning.md (use "forslag" from status). If the theme folder is not writable, the guide is stored in the database instead (same effect for the site; the answer says where). Prefer writing the file directly when you have file access, so it is versioned with the code.',
				'category'            => 'webto',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'fil'       => array( 'type' => 'string', 'description' => 'vejledning/<name>.md or blocks/<block>/vejledning.md' ),
						'titel'     => array( 'type' => 'string' ),
						'kategori'  => array( 'type' => 'string', 'description' => 'Kom i gang, Dit site, Blokke, Indholdstyper, Byg selv med Kadence, WordPress or Plugins' ),
						'resume'    => array( 'type' => 'string' ),
						'gaelder'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'kilder'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'udvider'   => array( 'type' => 'string', 'description' => 'Optional id of an automatic guide this file replaces (from status "udvider").' ),
						'markdown'  => array( 'type' => 'string', 'description' => 'The guide body in Markdown (no frontmatter).' ),
					),
					'required'   => array( 'fil', 'titel', 'markdown' ),
				),
				'execute_callback'    => 'webto_vejledning_ability_save',
				'permission_callback' => static fn() => current_user_can( 'manage_options' ),
				'meta'                => $meta( false ),
			)
		);
	}
);

/**
 * webto-vejledning/gem.
 *
 * @param array<string, mixed> $input Input.
 * @return array<string, mixed>|WP_Error
 */
function webto_vejledning_ability_save( $input ) {
	$input = is_array( $input ) ? $input : array();
	$file  = trim( (string) ( $input['fil'] ?? '' ), '/ ' );
	if ( ! preg_match( '#^(vejledning/[a-z0-9-]+\.md|blocks/[a-z0-9-]+/vejledning\.md)$#', $file ) ) {
		return new WP_Error( 'fil', 'fil must be vejledning/<a-z0-9->.md or blocks/<block>/vejledning.md' );
	}
	if ( str_starts_with( $file, 'blocks/' ) && ! is_dir( get_stylesheet_directory() . '/' . dirname( $file ) ) ) {
		return new WP_Error( 'blok', 'The block folder does not exist in the theme: ' . dirname( $file ) );
	}
	$one   = static fn( $v ) => trim( preg_replace( '/[\r\n]+/', ' ', sanitize_text_field( (string) $v ) ) ?? '' );
	$keys  = webto_vejledning_clean_keys( (array) ( $input['gaelder'] ?? array() ) );
	$srcs  = array_values( array_filter( array_map( static fn( $s ) => ltrim( str_replace( '..', '', $one( $s ) ), '/' ), (array) ( $input['kilder'] ?? array() ) ), 'strlen' ) );
	$now   = wp_date( 'Y-m-d H:i' );
	$md    = str_replace( "\r\n", "\n", (string) ( $input['markdown'] ?? '' ) );
	$fm    = array(
		'titel'     => $one( $input['titel'] ?? '' ),
		'kategori'  => $one( $input['kategori'] ?? '' ),
		'resume'    => $one( $input['resume'] ?? '' ),
		'udvider'   => $one( $input['udvider'] ?? '' ),
		'gaelder'   => $keys ? '[' . implode( ', ', $keys ) . ']' : '',
		'kilder'    => $srcs ? '[' . implode( ', ', $srcs ) . ']' : '',
		'opdateret' => $now,
	);
	$text  = "---\n";
	foreach ( array_filter( $fm, 'strlen' ) as $key => $value ) {
		$text .= $key . ': ' . $value . "\n";
	}
	$text .= "---\n\n" . trim( $md ) . "\n";

	$path = get_stylesheet_directory() . '/' . $file;
	$dir  = dirname( $path );
	if ( ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) && wp_is_writable( $dir ) && ( ! file_exists( $path ) || wp_is_writable( $path ) ) ) {
		if ( false !== file_put_contents( $path, $text ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			// Kan gruppen skrive i mappen (fx lasse på homelab), så kan den også rette filen.
			chmod( $path, ( fileperms( $dir ) & 0020 ) ? 0664 : 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			$status = webto_vejledning_status();
			return array( 'gemt' => 'fil', 'fil' => $file, 'antal_tilbage' => $status['antal'] );
		}
	}

	// Temaet kan ikke skrives: gem i databasen (samme virkning på sitet). Samme "fil" opdaterer den samme post.
	$existing = get_posts( array( 'post_type' => 'webto_vejledning', 'post_status' => 'publish', 'meta_key' => '_wv_fil', 'meta_value' => $file, 'posts_per_page' => 1, 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$post_id  = wp_insert_post(
		wp_slash(
			array(
				'ID'           => $existing ? (int) $existing[0] : 0,
				'post_type'    => 'webto_vejledning',
				'post_status'  => 'publish',
				'post_title'   => $fm['titel'],
				'post_content' => '',
			)
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$replaces = str_starts_with( $file, 'blocks/' ) ? webto_vejledning_block_id_for_dir( dirname( $file ) ) : $fm['udvider'];
	update_post_meta( $post_id, '_wv_fil', $file );
	update_post_meta( $post_id, '_wv_markdown', $md );
	update_post_meta( $post_id, '_wv_kategori', webto_vejledning_kategori( $fm['kategori'] ) );
	update_post_meta( $post_id, '_wv_resume', $fm['resume'] );
	update_post_meta( $post_id, '_wv_gaelder', $keys );
	update_post_meta( $post_id, '_wv_kilder', $srcs );
	update_post_meta( $post_id, '_wv_opdateret', $now );
	if ( '' !== $replaces ) {
		update_post_meta( $post_id, '_wv_erstatter', $replaces );
	} else {
		delete_post_meta( $post_id, '_wv_erstatter' );
	}
	$status = webto_vejledning_status();
	return array(
		'gemt'          => 'database',
		'fil'           => $file,
		'besked'        => 'The theme folder is not writable by WordPress, so the guide was stored in the database. Write the same file in the theme later if you get file access.',
		'antal_tilbage' => $status['antal'],
	);
}

/**
 * Id på den automatiske vejledning til blokken i en temamappe (blocks/hero -> blok/webto/hero).
 *
 * @param string $rel_dir Fx blocks/hero.
 */
function webto_vejledning_block_id_for_dir( string $rel_dir ): string {
	foreach ( webto_vejledning_site_blocks() as $name => $block ) {
		if ( str_ends_with( $block['dir'], '/' . $rel_dir ) ) {
			return 'blok/' . $name;
		}
	}
	return '';
}
