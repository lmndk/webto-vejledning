<?php
/**
 * Status: hvad mangler en vejledning, og hvilke vejledninger er forældede? Samme svar i ability, WP-CLI og wp-admin.
 *
 * Dækning: hver af sitets blokke, egne indholdstyper (og Indlæg/Sider/Medier), ACF-feltgrupper, menupladser, aktive
 * plugins, funktioner (filteret webto_vejledning_funktioner) og sitets egne blokgrupper (temaets og de synkroniserede)
 * skal have en SKREVET vejledning, hvis "gaelder" nævner den. Automatiske vejledninger tæller ikke (de er kun et sikkerhedsnet).
 * Forældet: en af vejledningens "kilder" (filer i temaet) er ændret efter vejledningen (opdateret, filens egen
 * ændringstid eller rettelsen i wp-admin - det seneste af dem), eller kilden findes ikke længere.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alt, der skal have en vejledning.
 *
 * @return array<int, array{type: string, noegle: string, navn: string, forslag: string, udvider: string, kilder: string[]}>
 */
function webto_vejledning_krav(): array {
	$req = array();
	$add = static function ( string $type, string $key, string $name, string $file, string $extends = '', array $sources = array() ) use ( &$req ) {
		$req[] = array(
			'type'    => $type,
			'noegle'  => $key,
			'navn'    => $name,
			'forslag' => $file,
			'udvider' => $extends,
			'kilder'  => $sources,
		);
	};

	foreach ( webto_vejledning_site_blocks() as $name => $block ) {
		$rel = str_replace( trailingslashit( $block['theme'] ), '', $block['dir'] );
		$add( 'blok', 'blok:' . $name, (string) ( $block['json']['title'] ?? $name ), $rel . '/vejledning.md', '', webto_vejledning_block_sources( $name, $block ) );
	}

	foreach ( array( 'post', 'page', 'attachment' ) as $builtin ) {
		$type = get_post_type_object( $builtin );
		if ( $type ) {
			$add( 'indholdstype', 'posttype:' . $builtin, (string) $type->labels->name, 'vejledning/posttype-' . $builtin . '.md' );
		}
	}
	foreach ( webto_vejledning_site_post_types() as $name => $type ) {
		$add( 'indholdstype', 'posttype:' . $name, (string) $type->labels->name, 'vejledning/posttype-' . $name . '.md', 'posttype/' . $name );
	}

	// Feltgrupper på sitets blokke og egne indholdstyper dækkes af deres vejledning (feltlisten kommer automatisk med).
	if ( function_exists( 'acf_get_field_groups' ) ) {
		$covered_by = array_merge( array_keys( webto_vejledning_site_blocks() ), array_keys( webto_vejledning_site_post_types() ) );
		foreach ( acf_get_field_groups() as $group ) {
			$own = false;
			foreach ( (array) ( $group['location'] ?? array() ) as $and ) {
				foreach ( (array) $and as $rule ) {
					if ( in_array( $rule['param'] ?? '', array( 'block', 'post_type' ), true ) && in_array( $rule['value'] ?? '', $covered_by, true ) ) {
						$own = true;
					}
				}
			}
			if ( ! $own ) {
				$add( 'feltgruppe', 'feltgruppe:' . $group['key'], (string) $group['title'], 'vejledning/felter-' . sanitize_title( (string) $group['title'] ) . '.md', '', is_file( get_stylesheet_directory() . '/acf-json/' . $group['key'] . '.json' ) ? array( 'acf-json/' . $group['key'] . '.json' ) : array() );
			}
		}
	}

	// Sitets egne blokgrupper (temaets patterns/ og synkroniserede blokgrupper): beskrives i vejledning/blokgrupper.md.
	foreach ( webto_vejledning_site_patterns() as $key => $pattern ) {
		$add( 'blokgruppe', $key, $pattern['titel'], 'vejledning/blokgrupper.md', 'site/blokgrupper', '' !== $pattern['fil'] ? array( $pattern['fil'] ) : array() );
	}

	foreach ( get_registered_nav_menus() as $slug => $label ) {
		$add( 'menuplads', 'menu:' . $slug, (string) $label, 'vejledning/menuer.md', 'site/menuer-og-header' );
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
		$slug = dirname( (string) $plugin );
		$slug = '.' === $slug ? basename( (string) $plugin, '.php' ) : $slug;
		if ( str_starts_with( $slug, 'webto-' ) ) {
			continue; // Vores egne plugins har deres vejledning i sig.
		}
		$data = is_file( WP_PLUGIN_DIR . '/' . $plugin ) ? get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false ) : array();
		$add( 'plugin', 'plugin:' . $slug, (string) ( $data['Name'] ?? $slug ), 'vejledning/plugin-' . $slug . '.md' );
	}

	/*
	 * Funktioner, der skal forklares (fx header-builderen). Temaet og plugins melder dem:
	 *   add_filter( 'webto_vejledning_funktioner', fn( $f ) => $f + array( 'header-builder' => array( 'titel' => 'Headeren', 'kilder' => array( 'header-builder/' ) ) ) );
	 */
	foreach ( (array) apply_filters( 'webto_vejledning_funktioner', array() ) as $slug => $info ) {
		$add( 'funktion', 'funktion:' . $slug, (string) ( $info['titel'] ?? $slug ), 'vejledning/' . sanitize_title( (string) $slug ) . '.md', (string) ( $info['udvider'] ?? '' ), (array) ( $info['kilder'] ?? array() ) );
	}

	return (array) apply_filters( 'webto_vejledning_krav', $req );
}

/**
 * Tidspunktet, en vejledning sidst blev set efter: det seneste af "opdateret", filens ændringstid og rettelsen.
 *
 * @param array<string, mixed> $g Vejledningen.
 */
function webto_vejledning_checked_at( array $g ): int {
	$times = array( 0 );
	if ( '' !== $g['opdateret'] ) {
		// "opdateret" er sitets tid (dansk tid), ikke UTC. Kun en dato tæller som hele dagen.
		try {
			$value   = (string) $g['opdateret'];
			$date    = new DateTimeImmutable( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value . ' 23:59:59' : $value, wp_timezone() );
			$times[] = $date->getTimestamp();
		} catch ( Exception $e ) {
			$times[] = 0;
		}
	}
	if ( '' !== $g['fil'] && is_file( (string) $g['fil'] ) ) {
		$times[] = (int) filemtime( (string) $g['fil'] );
	}
	if ( $g['post_id'] ) {
		$times[] = (int) get_post_modified_time( 'U', true, (int) $g['post_id'] );
	}
	return max( $times );
}

/**
 * En kildes sti på disken. Kilder skrives relativt til temaet (fx blocks/hero/render.php); en mappe (slutter med /)
 * tæller med sin nyeste fil.
 *
 * @param string $source Kilden.
 * @return array{path: string, mtime: int}|null
 */
function webto_vejledning_source_file( string $source ): ?array {
	$source = ltrim( str_replace( '..', '', $source ), '/' );
	foreach ( webto_vejledning_theme_dirs() as $theme ) {
		$path = $theme . '/' . $source;
		if ( is_file( $path ) ) {
			return array( 'path' => $path, 'mtime' => (int) filemtime( $path ) );
		}
		if ( is_dir( $path ) ) {
			$newest = 0;
			$it     = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && 'vejledning.md' !== $file->getFilename() ) {
					$newest = max( $newest, $file->getMTime() );
				}
			}
			return array( 'path' => $path, 'mtime' => $newest );
		}
	}
	return null;
}

/**
 * Hele status: manglende, forældede og instruktionen.
 *
 * @return array<string, mixed>
 */
function webto_vejledning_status(): array {
	$guides  = webto_vejledning_alle( true );
	$written = array();
	foreach ( $guides as $g ) {
		if ( $g['skrevet'] ) {
			foreach ( $g['gaelder'] as $key ) {
				$written[ $key ][] = $g['id'];
			}
		}
	}

	$missing = array();
	foreach ( webto_vejledning_krav() as $req ) {
		if ( empty( $written[ $req['noegle'] ] ) ) {
			$missing[] = $req;
		}
	}

	$outdated = array();
	foreach ( $guides as $g ) {
		if ( ! $g['skrevet'] || ! $g['kilder'] || 'fast' === $g['kilde'] ) {
			continue;
		}
		$at      = webto_vejledning_checked_at( $g );
		$changed = array();
		foreach ( $g['kilder'] as $source ) {
			$file = webto_vejledning_source_file( $source );
			if ( null === $file ) {
				$changed[] = array( 'kilde' => $source, 'grund' => 'findes ikke' );
			} elseif ( $file['mtime'] > $at ) {
				$changed[] = array( 'kilde' => $source, 'grund' => 'ændret ' . wp_date( 'Y-m-d H:i', $file['mtime'] ) );
			}
		}
		if ( $changed ) {
			$outdated[] = array(
				'id'        => $g['id'],
				'titel'     => $g['titel'],
				'fil'       => webto_vejledning_relative_file( $g ),
				'opdateret' => wp_date( 'Y-m-d H:i', $at ),
				'kilder'    => $changed,
				'rettet'    => (bool) $g['rettet'],
			);
		}
	}

	return array(
		'ok'          => ! $missing && ! $outdated,
		'antal'       => count( $missing ) + count( $outdated ),
		'manglende'   => $missing,
		'foraeldede'  => $outdated,
		'mappe'       => webto_vejledning_theme_guide_dir(),
		'kan_skrive'  => webto_vejledning_theme_writable(),
		'instruktion' => webto_vejledning_instructions(),
		'skabelon'    => webto_vejledning_template(),
	);
}

/**
 * Vejledningens fil relativt til temaet (eller pluginnet/databasen).
 *
 * @param array<string, mixed> $g Vejledningen.
 */
function webto_vejledning_relative_file( array $g ): string {
	if ( '' === $g['fil'] ) {
		return $g['post_id'] ? 'wp-admin (database)' : '';
	}
	foreach ( webto_vejledning_theme_dirs() as $theme ) {
		if ( str_starts_with( (string) $g['fil'], trailingslashit( $theme ) ) ) {
			return substr( (string) $g['fil'], strlen( trailingslashit( $theme ) ) );
		}
	}
	return str_replace( trailingslashit( WP_CONTENT_DIR ), 'wp-content/', (string) $g['fil'] );
}

/**
 * Kan WordPress selv skrive i temaets vejledning-mappe? (På homelab: nej - dér skriver AI'en filerne i projektmappen.)
 */
function webto_vejledning_theme_writable(): bool {
	$dir = webto_vejledning_theme_guide_dir();
	return is_dir( $dir ) ? wp_is_writable( $dir ) : wp_is_writable( dirname( $dir ) );
}

/**
 * Skabelonen til en ny vejledning.
 */
function webto_vejledning_template(): string {
	return "---\n"
		. "titel: Kort titel, som kunden ville søge efter\n"
		. "kategori: Dit site            # Kom i gang, Dit site, Blokke, Indholdstyper, Byg selv med Kadence, WordPress eller Plugins\n"
		. "resume: Én sætning om, hvad vejledningen hjælper med.\n"
		. "gaelder: [blok:webto/navn]    # det, vejledningen forklarer (se nøglerne i status)\n"
		. "kilder: [blocks/navn/render.php, blocks/navn/block.json]   # filer i temaet, vejledningen beskriver\n"
		. "opdateret: " . wp_date( 'Y-m-d H:i' ) . "\n"
		. "---\n\n"
		. "Kort indledning: hvad er det, og hvornår bruger man det?\n\n"
		. "## Sådan gør du\n\n"
		. "1. Gå til **Sider** i menuen til venstre, og åbn siden.\n"
		. "2. Klik på ...\n"
		. "3. Klik på **Gem**.\n\n"
		. "> **Tip:** Én konkret ting, der sparer kunden tid eller en fejl.\n";
}

/**
 * Instruktionen til AI'en (dansk, præcis, uden at gætte).
 */
function webto_vejledning_instructions(): string {
	$theme = wp_get_theme()->get_stylesheet();
	return implode(
		"\n",
		array(
			'Webto Vejledning: hver ny funktion, blok, indholdstype, menuplads, feltgruppe, blokgruppe eller plugin skal have en vejledning til kunden, og en rettelse i koden skal følges af en opdateret vejledning. Status skal være 0, før opgaven er færdig.',
			'',
			'HVOR:',
			"- En blok: blocks/<blok>/vejledning.md i temaet (wp-content/themes/$theme/). Feltlisten kommer automatisk fra ACF; skriv kun det, felterne ikke selv siger.",
			"- Alt andet: vejledning/<navn>.md i temaet. Brug filnavnet i 'forslag' fra status.",
			"- Udvider en fil en automatisk vejledning (status viser 'udvider', fx site/menuer-og-header eller posttype/case), så skriv 'udvider: <id>' i frontmatter; så erstatter din tekst den automatiske.",
			"- Blokgrupper (temaets patterns/ og synkroniserede blokgrupper): beskriv dem alle i vejledning/blokgrupper.md med 'udvider: site/blokgrupper' og hver blokgruppes nøgle (blokgruppe:<navn>) i gaelder: hvad hver side og sektion er til. Listen over blokgrupperne kommer automatisk med.",
			'- Har du adgang til temaets filer (fx i projektmappen på homelab: ~/projects/<projekt>/.../tema/webto/), så skriv filen direkte og læg den i git sammen med koden. Ellers: brug ability webto-vejledning/gem (skriver filen, eller gemmer i databasen, hvis temaet ikke kan skrives).',
			'',
			'FRONTMATTER: titel, kategori, resume, gaelder (nøglerne fra status), kilder (filerne i temaet, vejledningen beskriver - så kan status se, når koden ændres), opdateret (dato og klokkeslæt NU, ÅÅÅÅ-MM-DD TT:MM). Ret "opdateret", hver gang du har tjekket vejledningen mod koden.',
			'',
			'SKRIVEMÅDE (kunden er ikke udvikler): dansk, du-form, korte afsnit og nummererede trin. Knapper og menupunkter skrives præcis som de står i wp-admin og med fed (**Udgiv**). Ingen kode, filnavne, feltnøgler eller tekniske ord. Forklar, hvad kunden ser, og hvad der sker. Links: [Sider](admin:edit.php?post_type=page) eller [anden vejledning](vejledning:<id>). Tip-boks: en linje, der starter med "> ".',
			'',
			'KONTROL: kør webto-vejledning/status igen (eller wp webto vejledning status). Den skal svare 0.',
		)
	);
}
