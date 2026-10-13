<?php
/**
 * Kilderne: samler alle vejledninger i ét indeks (id => vejledning).
 *
 * En vejledning er et array med:
 *   id, titel, kategori (slug), resume, html (brødtekst), felter_html (automatisk feltliste, kan ikke rettes),
 *   gaelder (fx blok:webto/hero, posttype:case, side:42, menu:primary, plugin:wp-seopress, funktion:header-builder,
 *   feltgruppe:group_x), kilder (filer relativt til temaet), opdateret, kilde (fast|auto|tema|blok|egen), fil (sti|''),
 *   markdown (rå brødtekst, når den findes), skrevet (true = skrevet af et menneske/AI, false = kun automatisk),
 *   rettet (true = rettet i wp-admin), post_id (rettelsen eller den egne vejledning), raekkefoelge.
 *
 * Kilder i rækkefølge (en senere kilde med samme id vinder):
 *   1. Faste vejledninger i pluginnet: indhold/*.md (id fast/<fil>).
 *   2. Automatiske: hver webto-blok (blok/<navn>), hver egen indholdstype (posttype/<navn>), menuer og header.
 *   3. Sitets egne filer: temaets vejledning/*.md (id tema/<fil>) og blokkenes blocks/<blok>/vejledning.md.
 *      En temafil med "udvider: posttype/case" (eller site/menuer-og-header) erstatter teksten i den automatiske.
 *   4. Filteret webto_vejledning_sources (fremtidige funktioner og plugins kan melde deres egne vejledninger).
 *   5. Databasen (wp-admin): en rettelse (_wv_erstatter = id) erstatter titel og tekst; ellers en egen vejledning.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kategorierne i fast rækkefølge (slug => navn).
 *
 * @return array<string, string>
 */
function webto_vejledning_kategorier(): array {
	return (array) apply_filters(
		'webto_vejledning_kategorier',
		array(
			'kom-i-gang'    => __( 'Kom i gang', 'webto-vejledning' ),
			'dit-site'      => __( 'Dit site', 'webto-vejledning' ),
			'blokke'        => __( 'Blokke', 'webto-vejledning' ),
			'indholdstyper' => __( 'Indholdstyper', 'webto-vejledning' ),
			'kadence'       => __( 'Byg selv med Kadence', 'webto-vejledning' ),
			'wordpress'     => __( 'WordPress', 'webto-vejledning' ),
			'plugins'       => __( 'Plugins', 'webto-vejledning' ),
		)
	);
}

/**
 * Kategori fra en fil (slug eller navn, store/små bogstaver er ligegyldige). Ukendt = "Dit site".
 *
 * @param string $value Værdien fra frontmatter.
 */
function webto_vejledning_kategori( string $value ): string {
	$cats = webto_vejledning_kategorier();
	$slug = sanitize_title( $value );
	if ( isset( $cats[ $slug ] ) ) {
		return $slug;
	}
	foreach ( $cats as $key => $label ) {
		if ( 0 === strcasecmp( $label, trim( $value ) ) ) {
			return $key;
		}
	}
	return 'dit-site';
}

/**
 * Udfylder en vejledning med standardværdier.
 *
 * @param array<string, mixed> $g Vejledningen.
 * @return array<string, mixed>
 */
function webto_vejledning_normalize( array $g ): array {
	$g = wp_parse_args(
		$g,
		array(
			'id'           => '',
			'titel'        => '',
			'kategori'     => 'dit-site',
			'resume'       => '',
			'html'         => '',
			'felter_html'  => '',
			'gaelder'      => array(),
			'kilder'       => array(),
			'opdateret'    => '',
			'kilde'        => 'tema',
			'fil'          => '',
			'markdown'     => null,
			'skrevet'      => true,
			'rettet'       => false,
			'post_id'      => 0,
			'raekkefoelge' => 50,
		)
	);
	$g['gaelder'] = array_values( array_unique( array_filter( array_map( 'trim', (array) $g['gaelder'] ), 'strlen' ) ) );
	$g['kilder']  = array_values( array_unique( array_filter( array_map( 'trim', (array) $g['kilder'] ), 'strlen' ) ) );
	if ( '' === $g['resume'] ) {
		$g['resume'] = wp_trim_words( webto_vejledning_plain( (string) $g['html'] ), 22, ' ...' );
	}
	return $g;
}

/**
 * Læser en Markdown-fil som vejledning.
 *
 * @param string               $path Filens sti.
 * @param string               $id   Vejledningens id.
 * @param array<string, mixed> $base Standardværdier (fx kilde og kategori).
 * @return array<string, mixed>|null
 */
function webto_vejledning_from_file( string $path, string $id, array $base = array() ): ?array {
	$raw = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( '' === trim( $raw ) ) {
		return null;
	}
	$fm    = webto_vejledning_frontmatter( $raw );
	$meta  = $fm['meta'];
	$body  = $fm['body'];
	$title = (string) ( $meta['titel'] ?? '' );
	if ( '' === $title && preg_match( '/^#\s+(.+)$/m', $body, $h ) ) {
		$title = trim( $h[1] );
		$body  = preg_replace( '/^#\s+.+\n?/m', '', $body, 1 ) ?? $body;
	}
	return array_merge(
		$base,
		array(
			'id'           => $id,
			'titel'        => '' !== $title ? $title : ucfirst( str_replace( '-', ' ', basename( $path, '.md' ) ) ),
			'kategori'     => isset( $meta['kategori'] ) ? webto_vejledning_kategori( (string) $meta['kategori'] ) : ( $base['kategori'] ?? 'dit-site' ),
			'kategori_sat' => isset( $meta['kategori'] ),
			'resume'       => (string) ( $meta['resume'] ?? '' ),
			'gaelder'      => array_merge( (array) ( $base['gaelder'] ?? array() ), (array) ( $meta['gaelder'] ?? array() ) ),
			'kilder'       => (array) ( $meta['kilder'] ?? ( $base['kilder'] ?? array() ) ),
			'opdateret'    => (string) ( $meta['opdateret'] ?? '' ),
			'udvider'      => (string) ( $meta['udvider'] ?? '' ),
			'raekkefoelge' => isset( $meta['raekkefoelge'] ) ? (int) $meta['raekkefoelge'] : ( $base['raekkefoelge'] ?? 50 ),
			'fil'          => $path,
			'markdown'     => $body,
			'html'         => webto_vejledning_markdown( $body ),
			'skrevet'      => true,
		)
	);
}

/**
 * Temaets mapper (barnetema først, så forældretemaet), uden dubletter.
 *
 * @return string[]
 */
function webto_vejledning_theme_dirs(): array {
	return array_values( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) );
}

/**
 * Mappen, hvor sitets egne vejledninger ligger (temaets vejledning/).
 */
function webto_vejledning_theme_guide_dir(): string {
	return get_stylesheet_directory() . '/vejledning';
}

/**
 * Sitets egne blokke: dem, der ligger i temaets blocks/ og er registreret (navn => mappe, block.json, temamappe).
 *
 * @return array<string, array{dir: string, json: array<string, mixed>, theme: string}>
 */
function webto_vejledning_site_blocks(): array {
	static $blocks = null;
	if ( null !== $blocks ) {
		return $blocks;
	}
	$blocks   = array();
	$registry = WP_Block_Type_Registry::get_instance();
	foreach ( webto_vejledning_theme_dirs() as $theme ) {
		foreach ( glob( $theme . '/blocks/*/block.json' ) ?: array() as $file ) {
			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$name = is_array( $json ) ? (string) ( $json['name'] ?? '' ) : '';
			if ( '' !== $name && ! isset( $blocks[ $name ] ) && $registry->is_registered( $name ) ) {
				$blocks[ $name ] = array(
					'dir'   => dirname( $file ),
					'json'  => $json,
					'theme' => $theme,
				);
			}
		}
	}
	return (array) apply_filters( 'webto_vejledning_site_blocks', $blocks );
}

/**
 * ACF-feltgrupper, der vises for en blok eller en indholdstype.
 *
 * @param string $param 'block' eller 'post_type'.
 * @param string $value Bloknavn eller indholdstype.
 * @return array<int, array<string, mixed>>
 */
function webto_vejledning_acf_groups( string $param, string $value ): array {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return array();
	}
	$out = array();
	foreach ( acf_get_field_groups() as $group ) {
		foreach ( (array) ( $group['location'] ?? array() ) as $and ) {
			foreach ( (array) $and as $rule ) {
				if ( ( $rule['param'] ?? '' ) === $param && ( $rule['operator'] ?? '' ) === '==' && ( $rule['value'] ?? '' ) === $value ) {
					$out[ $group['key'] ] = $group;
				}
			}
		}
	}
	return array_values( $out );
}

/**
 * Feltets type i et sprog, kunden forstår.
 *
 * @param string $type ACF-type.
 */
function webto_vejledning_field_type( string $type ): string {
	$types = array(
		'text'             => __( 'tekst', 'webto-vejledning' ),
		'textarea'         => __( 'tekst over flere linjer', 'webto-vejledning' ),
		'wysiwyg'          => __( 'tekst med formatering', 'webto-vejledning' ),
		'number'           => __( 'tal', 'webto-vejledning' ),
		'email'            => __( 'e-mail', 'webto-vejledning' ),
		'url'              => __( 'adresse', 'webto-vejledning' ),
		'link'             => __( 'link: adresse og tekst', 'webto-vejledning' ),
		'image'            => __( 'billede', 'webto-vejledning' ),
		'gallery'          => __( 'galleri', 'webto-vejledning' ),
		'file'             => __( 'fil', 'webto-vejledning' ),
		'select'           => __( 'valg', 'webto-vejledning' ),
		'radio'            => __( 'valg', 'webto-vejledning' ),
		'button_group'     => __( 'valg', 'webto-vejledning' ),
		'checkbox'         => __( 'flere valg', 'webto-vejledning' ),
		'true_false'       => __( 'til/fra', 'webto-vejledning' ),
		'post_object'      => __( 'valg af indhold', 'webto-vejledning' ),
		'relationship'     => __( 'valg af indhold', 'webto-vejledning' ),
		'page_link'        => __( 'valg af side', 'webto-vejledning' ),
		'taxonomy'         => __( 'valg af kategori', 'webto-vejledning' ),
		'date_picker'      => __( 'dato', 'webto-vejledning' ),
		'date_time_picker' => __( 'dato og tid', 'webto-vejledning' ),
		'time_picker'      => __( 'tid', 'webto-vejledning' ),
		'color_picker'     => __( 'farve', 'webto-vejledning' ),
		'repeater'         => __( 'liste, du kan tilføje rækker til', 'webto-vejledning' ),
		'flexible_content' => __( 'sektioner, du kan tilføje', 'webto-vejledning' ),
		'group'            => __( 'gruppe af felter', 'webto-vejledning' ),
		'oembed'           => __( 'video eller indlejring (adresse)', 'webto-vejledning' ),
	);
	return $types[ $type ] ?? $type;
}

/**
 * Felterne i en eller flere feltgrupper som en liste.
 *
 * @param array<int, array<string, mixed>> $groups Feltgrupper.
 */
function webto_vejledning_fields_html( array $groups ): string {
	if ( ! $groups || ! function_exists( 'acf_get_fields' ) ) {
		return '';
	}
	$render = static function ( array $fields ) use ( &$render ): string {
		$html = '';
		foreach ( $fields as $field ) {
			if ( in_array( $field['type'] ?? '', array( 'tab', 'message', 'accordion' ), true ) || '' === (string) ( $field['label'] ?? '' ) ) {
				continue;
			}
			$html .= '<li><strong>' . esc_html( (string) $field['label'] ) . '</strong> <span class="wv-type">(' . esc_html( webto_vejledning_field_type( (string) $field['type'] ) ) . ( ! empty( $field['required'] ) ? ', ' . esc_html__( 'skal udfyldes', 'webto-vejledning' ) : '' ) . ')</span>';
			if ( '' !== trim( (string) ( $field['instructions'] ?? '' ) ) ) {
				$html .= '<br>' . esc_html( (string) $field['instructions'] );
			}
			if ( ! empty( $field['sub_fields'] ) ) {
				$html .= '<ul>' . $render( (array) $field['sub_fields'] ) . '</ul>';
			}
			$html .= '</li>';
		}
		return $html;
	};
	$items = '';
	foreach ( $groups as $group ) {
		$items .= $render( (array) acf_get_fields( $group ) );
	}
	return '' === $items ? '' : '<h2>' . esc_html__( 'Felterne', 'webto-vejledning' ) . '</h2><ul class="wv-fields">' . $items . '</ul>';
}

/**
 * Standardkilderne for en blok: blokkens egne filer (undtagen vejledningen) og dens ACF-feltgrupper i acf-json.
 *
 * @param string               $name  Bloknavn.
 * @param array<string, mixed> $block Fra webto_vejledning_site_blocks().
 * @return string[] Stier relativt til temaet.
 */
function webto_vejledning_block_sources( string $name, array $block ): array {
	$theme = trailingslashit( $block['theme'] );
	$files = array();
	foreach ( glob( $block['dir'] . '/*' ) ?: array() as $file ) {
		if ( is_file( $file ) && 'vejledning.md' !== basename( $file ) ) {
			$files[] = str_replace( $theme, '', $file );
		}
	}
	foreach ( webto_vejledning_acf_groups( 'block', $name ) as $group ) {
		if ( is_file( $theme . 'acf-json/' . $group['key'] . '.json' ) ) {
			$files[] = 'acf-json/' . $group['key'] . '.json';
		}
	}
	sort( $files );
	return $files;
}

/**
 * Sitets egne indholdstyper, der skal have en vejledning: ikke WordPress' egne og ikke plugins' typer, der ligger
 * under pluginnets menu (de dækkes af pluginnets vejledning).
 *
 * @return WP_Post_Type[]
 */
function webto_vejledning_site_post_types(): array {
	$out = array();
	foreach ( get_post_types( array( 'show_ui' => true, '_builtin' => false ), 'objects' ) as $type ) {
		if ( true === $type->show_in_menu && 'webto_vejledning' !== $type->name ) {
			$out[ $type->name ] = $type;
		}
	}
	return (array) apply_filters( 'webto_vejledning_site_post_types', $out );
}

/**
 * De automatiske vejledninger (blokke, indholdstyper, menuer og header).
 *
 * @return array<string, array<string, mixed>>
 */
function webto_vejledning_auto(): array {
	$out = array();

	foreach ( webto_vejledning_site_blocks() as $name => $block ) {
		$json  = $block['json'];
		$title = (string) ( $json['title'] ?? $name );
		$desc  = (string) ( $json['description'] ?? '' );
		$html  = ( '' !== $desc ? '<p>' . esc_html( $desc ) . '</p>' : '' )
			. '<h2>' . esc_html__( 'Sådan sætter du den ind', 'webto-vejledning' ) . '</h2><ol>'
			. '<li>' . esc_html__( 'Åbn siden i editoren, og klik på plusset (+), hvor blokken skal stå.', 'webto-vejledning' ) . '</li>'
			/* translators: %s: blokkens navn */
			. '<li>' . sprintf( esc_html__( 'Skriv "%s" i søgefeltet, og vælg blokken.', 'webto-vejledning' ), esc_html( $title ) ) . '</li>'
			. '<li>' . esc_html__( 'Udfyld felterne i blokken eller i panelet til højre, og gem siden.', 'webto-vejledning' ) . '</li></ol>';
		$id    = 'blok/' . $name;
		$g     = array(
			'id'           => $id,
			'titel'        => $title,
			'kategori'     => 'blokke',
			'resume'       => $desc,
			'html'         => $html,
			'felter_html'  => webto_vejledning_fields_html( webto_vejledning_acf_groups( 'block', $name ) ),
			'gaelder'      => array( 'blok:' . $name ),
			'kilder'       => webto_vejledning_block_sources( $name, $block ),
			'kilde'        => 'auto',
			'skrevet'      => false,
			'raekkefoelge' => 50,
		);
		// Blokkens egen vejledning.md erstatter den automatiske tekst (feltlisten bliver).
		$file = $block['dir'] . '/vejledning.md';
		if ( is_file( $file ) ) {
			$written = webto_vejledning_from_file( $file, $id, $g );
			if ( $written ) {
				$written['kilde']   = 'blok';
				$written['kilder']  = $written['kilder'] ?: $g['kilder'];
				$written['gaelder'] = array_merge( $g['gaelder'], $written['gaelder'] );
				$g                  = $written;
			}
		}
		$out[ $id ] = $g;
	}

	foreach ( webto_vejledning_site_post_types() as $name => $type ) {
		$labels = $type->labels;
		$html   = ( '' !== (string) $type->description ? '<p>' . esc_html( (string) $type->description ) . '</p>' : '' )
			. '<h2>' . esc_html__( 'Sådan opretter du en ny', 'webto-vejledning' ) . '</h2><ol>'
			/* translators: %s: menupunktets navn */
			. '<li>' . sprintf( esc_html__( 'Gå til %s i menuen til venstre.', 'webto-vejledning' ), '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . $name ) ) . '"><strong>' . esc_html( (string) $labels->menu_name ) . '</strong></a>' ) . '</li>'
			/* translators: %s: knappens tekst */
			. '<li>' . sprintf( esc_html__( 'Klik på %s, udfyld felterne, og klik på Udgiv.', 'webto-vejledning' ), '<strong>' . esc_html( (string) $labels->add_new_item ) . '</strong>' ) . '</li></ol>';
		$taxes  = array_filter( get_object_taxonomies( $name, 'objects' ), static fn( $t ) => $t->show_ui );
		if ( $taxes ) {
			$html .= '<h2>' . esc_html__( 'Sortering', 'webto-vejledning' ) . '</h2><ul>';
			foreach ( $taxes as $tax ) {
				$html .= '<li><a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . $tax->name . '&post_type=' . $name ) ) . '">' . esc_html( (string) $tax->labels->name ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		$id         = 'posttype/' . $name;
		$out[ $id ] = array(
			'id'          => $id,
			'titel'       => (string) $labels->name,
			'kategori'    => 'indholdstyper',
			'html'        => $html,
			'felter_html' => webto_vejledning_fields_html( webto_vejledning_acf_groups( 'post_type', $name ) ),
			'gaelder'     => array( 'posttype:' . $name ),
			/* translators: %s: indholdstypens navn */
			'resume'      => '' !== (string) $type->description ? (string) $type->description : sprintf( __( 'Sådan opretter og retter du %s.', 'webto-vejledning' ), mb_strtolower( (string) $labels->name ) ),
			'kilde'       => 'auto',
			'skrevet'     => false,
		);
	}

	$menus = get_registered_nav_menus();
	$hb    = function_exists( 'webto_hb_render' );
	if ( $menus || $hb ) {
		$html = '';
		if ( $menus ) {
			$html .= '<p>'
				/* translators: %s: link til Udseende -> Menuer */
				. sprintf( esc_html__( 'Menuerne rettes under %s. Under fanen "Håndter områder" vælger du, hvilken menu der står hvor.', 'webto-vejledning' ), '<a href="' . esc_url( admin_url( 'nav-menus.php' ) ) . '"><strong>' . esc_html__( 'Udseende -> Menuer', 'webto-vejledning' ) . '</strong></a>' )
				. '</p>';
		}
		if ( $hb ) {
			$html .= '<h2>' . esc_html__( 'Headeren', 'webto-vejledning' ) . '</h2><p>'
				/* translators: %s: link til Tilpasseren */
				. sprintf( esc_html__( 'Headerens opbygning (logo, menu, knapper og mobilmenuen) rettes under %s. Opbygningen ligger nederst på skærmen med fanerne Computer, Mobil og Skuffe, og du ser ændringerne med det samme, før du gemmer.', 'webto-vejledning' ), '<a href="' . esc_url( admin_url( 'customize.php?autofocus[panel]=webto_hb' ) ) . '"><strong>' . esc_html__( 'Udseende -> Tilpas -> Header', 'webto-vejledning' ) . '</strong></a>' )
				. '</p>';
		}
		// Listen over pladserne (og hvilken menu der står der lige nu) er altid den aktuelle og bliver stående,
		// også når temaet har sin egen tekst (vejledning/menuer.md med "udvider: site/menuer-og-header").
		$places = '';
		if ( $menus ) {
			$assigned = get_nav_menu_locations();
			$places  .= '<h2>' . esc_html__( 'Menupladserne på dit site', 'webto-vejledning' ) . '</h2><ul>';
			foreach ( $menus as $slug => $label ) {
				$menu    = ! empty( $assigned[ $slug ] ) ? wp_get_nav_menu_object( $assigned[ $slug ] ) : null;
				$places .= '<li><strong>' . esc_html( $label ) . '</strong>: ' . ( $menu ? esc_html( $menu->name ) : esc_html__( 'ingen menu valgt', 'webto-vejledning' ) ) . '</li>';
			}
			$places .= '</ul>';
		}
		$gaelder = array_map( static fn( $slug ) => 'menu:' . $slug, array_keys( $menus ) );
		if ( $hb ) {
			$gaelder[] = 'funktion:header-builder';
		}
		$out['site/menuer-og-header'] = array(
			'id'           => 'site/menuer-og-header',
			'titel'        => __( 'Menuer og header', 'webto-vejledning' ),
			'kategori'     => 'dit-site',
			'resume'       => $hb ? __( 'Hvor du retter menuerne og headerens opbygning.', 'webto-vejledning' ) : __( 'Hvor du retter menuerne.', 'webto-vejledning' ),
			'html'         => $html,
			'felter_html'  => $places,
			'gaelder'      => $gaelder,
			'kilde'        => 'auto',
			'skrevet'      => false,
			'raekkefoelge' => 20,
		);
	}
	return $out;
}

/**
 * Alle vejledninger (id => vejledning), sorteret efter kategori, rækkefølge og titel. Gemmes for resten af kaldet.
 *
 * @param bool $fresh Byg indekset igen (efter en ændring).
 * @return array<string, array<string, mixed>>
 */
function webto_vejledning_alle( bool $fresh = false ): array {
	static $all = null;
	if ( null !== $all && ! $fresh ) {
		return $all;
	}
	$guides = array();

	// 1. Faste.
	foreach ( glob( WEBTO_VEJLEDNING_DIR . '/indhold/*.md' ) ?: array() as $file ) {
		$g = webto_vejledning_from_file( $file, 'fast/' . basename( $file, '.md' ), array( 'kilde' => 'fast', 'kategori' => 'wordpress' ) );
		if ( $g ) {
			$guides[ $g['id'] ] = $g;
		}
	}

	// 2. Automatiske (inkl. blokkenes vejledning.md).
	$guides = array_merge( $guides, webto_vejledning_auto() );

	// 3. Temaets egne filer.
	foreach ( webto_vejledning_theme_dirs() as $theme ) {
		foreach ( glob( $theme . '/vejledning/*.md' ) ?: array() as $file ) {
			$g = webto_vejledning_from_file( $file, 'tema/' . basename( $file, '.md' ), array( 'kilde' => 'tema' ) );
			if ( ! $g || ( isset( $guides[ $g['id'] ] ) && 'tema' === $guides[ $g['id'] ]['kilde'] ) ) {
				continue; // Barnetemaets fil vinder over forældretemaets.
			}
			$extends = $g['udvider'];
			if ( '' !== $extends && isset( $guides[ $extends ] ) ) {
				$base               = $guides[ $extends ];
				$g['id']            = $extends;
				$g['felter_html']   = $base['felter_html'];
				$g['gaelder']       = array_merge( $base['gaelder'], $g['gaelder'] );
				$g['kategori']      = $g['kategori_sat'] ? $g['kategori'] : $base['kategori'];
				$guides[ $extends ] = $g;
				continue;
			}
			$guides[ $g['id'] ] = $g;
		}
	}

	// 4. Andre kilder.
	$guides = (array) apply_filters( 'webto_vejledning_sources', $guides );

	// 5. Rettelser og egne vejledninger fra wp-admin.
	foreach ( webto_vejledning_db_posts() as $post ) {
		$replaces = (string) get_post_meta( $post->ID, '_wv_erstatter', true );
		$md       = get_post_meta( $post->ID, '_wv_markdown', true );
		$html     = is_string( $md ) && '' !== $md ? webto_vejledning_markdown( $md ) : wp_kses_post( wpautop( $post->post_content ) );
		$sources  = get_post_meta( $post->ID, '_wv_kilder', true );
		$meta     = array(
			'kilder'    => is_array( $sources ) ? array_filter( $sources, 'strlen' ) : array(),
			'opdateret' => (string) get_post_meta( $post->ID, '_wv_opdateret', true ),
			'fil'       => (string) get_post_meta( $post->ID, '_wv_fil', true ),
		);
		if ( '' !== $replaces && isset( $guides[ $replaces ] ) ) {
			$guides[ $replaces ] = array_merge(
				$guides[ $replaces ],
				array(
					'titel'    => '' !== $post->post_title ? $post->post_title : $guides[ $replaces ]['titel'],
					'html'     => $html,
					'markdown' => is_string( $md ) && '' !== $md ? $md : null,
					'resume'   => (string) get_post_meta( $post->ID, '_wv_resume', true ),
					'skrevet'  => true,
					'rettet'   => true,
					'post_id'  => $post->ID,
				),
				array_filter( $meta )
			);
			continue;
		}
		$id            = 'egen/' . $post->ID;
		$guides[ $id ] = array_merge(
			array(
				'id'       => $id,
				'titel'    => $post->post_title,
				'kategori' => webto_vejledning_kategori( (string) get_post_meta( $post->ID, '_wv_kategori', true ) ),
				'resume'   => (string) get_post_meta( $post->ID, '_wv_resume', true ),
				'html'     => $html,
				'markdown' => is_string( $md ) && '' !== $md ? $md : null,
				'gaelder'  => array_filter( (array) get_post_meta( $post->ID, '_wv_gaelder', true ), 'strlen' ),
				'kilde'    => 'egen',
				'skrevet'  => true,
				'post_id'  => $post->ID,
			),
			array_filter( $meta )
		);
	}

	$guides = array_map( 'webto_vejledning_normalize', $guides );
	$order  = array_flip( array_keys( webto_vejledning_kategorier() ) );
	uasort(
		$guides,
		static fn( $a, $b ) => array( $order[ $a['kategori'] ] ?? 99, $a['raekkefoelge'], $a['titel'] ) <=> array( $order[ $b['kategori'] ] ?? 99, $b['raekkefoelge'], $b['titel'] )
	);
	$all = $guides;
	return $all;
}

/**
 * Rettelser og egne vejledninger i databasen.
 *
 * @return WP_Post[]
 */
function webto_vejledning_db_posts(): array {
	return get_posts(
		array(
			'post_type'      => 'webto_vejledning',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
}

/**
 * Én vejledning.
 *
 * @param string $id Id.
 * @return array<string, mixed>|null
 */
function webto_vejledning_get( string $id ): ?array {
	return webto_vejledning_alle()[ $id ] ?? null;
}

/**
 * Adressen til en vejledning i wp-admin.
 *
 * @param string $id Id.
 */
function webto_vejledning_view_url( string $id ): string {
	return add_query_arg( array( 'page' => 'webto-vejledning', 'vejledning' => rawurlencode( $id ) ), admin_url( 'admin.php' ) );
}

/**
 * Vejledningerne, der gælder for en liste af nøgler (fx sidens blokke), i nøglernes rækkefølge.
 *
 * @param string[] $keys Nøgler som blok:webto/hero og posttype:page.
 * @return array<string, array<string, mixed>>
 */
function webto_vejledning_for_keys( array $keys ): array {
	$keys  = array_values( array_unique( $keys ) );
	$rank  = array_flip( $keys );
	$found = array();
	foreach ( webto_vejledning_alle() as $id => $g ) {
		$hits = array_intersect( $g['gaelder'], $keys );
		if ( $hits ) {
			$found[ $id ] = min( array_map( static fn( $k ) => $rank[ $k ], $hits ) );
		}
	}
	asort( $found );
	$all = webto_vejledning_alle();
	$out = array();
	foreach ( array_keys( $found ) as $id ) {
		$out[ $id ] = $all[ $id ];
	}
	return $out;
}

/**
 * Søgeindekset (til søgefeltet i wp-admin og i panelet).
 *
 * @return array<int, array<string, string>>
 */
function webto_vejledning_index(): array {
	$cats = webto_vejledning_kategorier();
	$out  = array();
	foreach ( webto_vejledning_alle() as $g ) {
		$out[] = array(
			'id'       => $g['id'],
			'titel'    => $g['titel'],
			'kategori' => $cats[ $g['kategori'] ] ?? $g['kategori'],
			'resume'   => $g['resume'],
			'tekst'    => mb_substr( webto_vejledning_plain( $g['html'] . ' ' . $g['felter_html'] ), 0, 4000 ),
			'url'      => webto_vejledning_view_url( $g['id'] ),
		);
	}
	return $out;
}
