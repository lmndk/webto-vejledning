<?php
/**
 * wp-admin: menuen "Vejledning" (oversigt med søgning, én vejledning, ret/ny vejledning og dækning).
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function () {
		add_menu_page(
			__( 'Vejledning', 'webto-vejledning' ),
			__( 'Vejledning', 'webto-vejledning' ),
			(string) apply_filters( 'webto_vejledning_cap_read', 'edit_posts' ),
			'webto-vejledning',
			'webto_vejledning_page',
			'dashicons-book-alt',
			3
		);
		add_submenu_page( 'webto-vejledning', __( 'Vejledning', 'webto-vejledning' ), __( 'Alle vejledninger', 'webto-vejledning' ), (string) apply_filters( 'webto_vejledning_cap_read', 'edit_posts' ), 'webto-vejledning', 'webto_vejledning_page' );
		add_submenu_page( 'webto-vejledning', __( 'Skriv vejledning', 'webto-vejledning' ), __( 'Skriv vejledning', 'webto-vejledning' ), (string) apply_filters( 'webto_vejledning_cap_edit', 'edit_others_pages' ), 'webto-vejledning-skriv', 'webto_vejledning_edit_page' );
		// Dækning er Webtos værktøj (kunden ser den ikke, heller ikke som administrator).
		if ( webto_vejledning_is_webto() ) {
			add_submenu_page( 'webto-vejledning', __( 'Dækning', 'webto-vejledning' ), __( 'Dækning', 'webto-vejledning' ), 'manage_options', 'webto-vejledning-daekning', 'webto_vejledning_coverage_page' );
		}
	}
);

// Ingen beskeder fra WordPress og plugins på vejledningens sider (Lasse 2026-10-13); de ses på alle andre sider.
// Vejledningens egne beskeder (fx "Vejledningen er gemt") skrives i siden og bliver stående.
add_action(
	'in_admin_header',
	static function () {
		$screen = get_current_screen();
		if ( $screen && str_contains( (string) $screen->id, 'webto-vejledning' ) ) {
			foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
				remove_all_actions( $hook );
			}
		}
	},
	PHP_INT_MAX
);

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( ! str_contains( (string) $hook, 'webto-vejledning' ) ) {
			return;
		}
		wp_enqueue_style( 'webto-vejledning', WEBTO_VEJLEDNING_URL . 'assets/vejledning.css', array(), webto_vejledning_asset_version( 'assets/vejledning.css' ) );
		wp_enqueue_script( 'webto-vejledning-soeg', WEBTO_VEJLEDNING_URL . 'assets/soeg.js', array(), webto_vejledning_asset_version( 'assets/soeg.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
);

/**
 * En besked øverst efter gem/nulstil.
 */
function webto_vejledning_notice(): void {
	$messages = array(
		'gemt'      => __( 'Vejledningen er gemt.', 'webto-vejledning' ),
		'nulstillet' => __( 'Vejledningen er sat tilbage til standard.', 'webto-vejledning' ),
		'slettet'   => __( 'Vejledningen er slettet.', 'webto-vejledning' ),
	);
	$key      = isset( $_GET['wv_besked'] ) ? sanitize_key( wp_unslash( $_GET['wv_besked'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $messages[ $key ] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
	}
}

/**
 * Søgefeltet og indekset (bruges af assets/soeg.js).
 *
 * @param string $id_prefix Præfiks til id'er, så feltet kan stå flere steder.
 */
function webto_vejledning_search_box( string $id_prefix = 'wv' ): void {
	?>
	<div class="wv-search" role="search" data-wv-search>
		<label class="wv-search__label" for="<?php echo esc_attr( $id_prefix ); ?>-q"><?php esc_html_e( 'Søg i vejledningen', 'webto-vejledning' ); ?></label>
		<input class="wv-search__input" type="search" id="<?php echo esc_attr( $id_prefix ); ?>-q" autocomplete="off" placeholder="<?php esc_attr_e( 'Fx billede, menu, formular, SEO', 'webto-vejledning' ); ?>" data-wv-q>
		<p class="wv-search__count" aria-live="polite" data-wv-count></p>
		<ul class="wv-list wv-search__results" data-wv-results hidden></ul>
	</div>
	<script type="application/json" data-wv-index><?php echo wp_json_encode( webto_vejledning_index(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
	<?php
}

/**
 * Et punkt i en liste af vejledninger.
 *
 * @param array<string, mixed> $g Vejledningen.
 * @param string               $current Id på den viste vejledning.
 */
function webto_vejledning_list_item( array $g, string $current = '' ): string {
	$is = $current === $g['id'];
	return '<li><a class="wv-list__link" href="' . esc_url( webto_vejledning_view_url( $g['id'] ) ) . '"' . ( $is ? ' aria-current="page"' : '' ) . '>'
		. '<span class="wv-list__title">' . esc_html( $g['titel'] ) . '</span>'
		. ( '' !== $g['resume'] && '' === $current ? '<span class="wv-list__resume">' . esc_html( $g['resume'] ) . '</span>' : '' )
		. '</a></li>';
}

/**
 * Siden "Vejledning": oversigt, eller én vejledning (?vejledning=id).
 */
function webto_vejledning_page(): void {
	if ( ! webto_vejledning_can_read() ) {
		wp_die( esc_html__( 'Du har ikke adgang til vejledningen.', 'webto-vejledning' ), 403 );
	}
	$id = isset( $_GET['vejledning'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['vejledning'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' !== $id ) {
		webto_vejledning_single( $id );
		return;
	}

	$guides = webto_vejledning_alle();
	$cats   = webto_vejledning_kategorier();
	$til    = isset( $_GET['til'] ) ? webto_vejledning_clean_keys( sanitize_text_field( wp_unslash( (string) $_GET['til'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap wv">
		<h1 class="wv-title"><?php esc_html_e( 'Vejledning', 'webto-vejledning' ); ?></h1>
		<hr class="wp-header-end">
		<p class="wv-lead"><?php esc_html_e( 'Hjælp til dit website: søg, eller vælg et emne herunder.', 'webto-vejledning' ); ?></p>
		<?php webto_vejledning_notice(); ?>
		<?php webto_vejledning_search_box(); ?>

		<div data-wv-browse>
			<?php
			$here = $til ? webto_vejledning_for_keys( $til ) : array();
			if ( $here ) :
				?>
				<section class="wv-cat wv-cat--here" aria-labelledby="wv-cat-here">
					<h2 class="wv-cat__title" id="wv-cat-here"><?php esc_html_e( 'Til den side, du kom fra', 'webto-vejledning' ); ?></h2>
					<ul class="wv-list"><?php echo implode( '', array_map( 'webto_vejledning_list_item', $here ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped i webto_vejledning_list_item. ?></ul>
				</section>
			<?php endif; ?>

			<div class="wv-cats">
				<?php
				foreach ( $cats as $slug => $label ) :
					$in = array_filter( $guides, static fn( $g ) => $g['kategori'] === $slug );
					if ( ! $in ) {
						continue;
					}
					?>
					<section class="wv-cat" aria-labelledby="wv-cat-<?php echo esc_attr( $slug ); ?>">
						<h2 class="wv-cat__title" id="wv-cat-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></h2>
						<ul class="wv-list"><?php echo implode( '', array_map( 'webto_vejledning_list_item', $in ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped i webto_vejledning_list_item. ?></ul>
					</section>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( webto_vejledning_can_edit() ) : ?>
			<p class="wv-foot"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=webto-vejledning-skriv' ) ); ?>"><?php esc_html_e( 'Skriv en ny vejledning', 'webto-vejledning' ); ?></a></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Én vejledning.
 *
 * @param string $id Id.
 */
function webto_vejledning_single( string $id ): void {
	$g = webto_vejledning_get( $id );
	if ( ! $g ) {
		echo '<div class="wrap wv"><h1>' . esc_html__( 'Vejledningen findes ikke', 'webto-vejledning' ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=webto-vejledning' ) ) . '">' . esc_html__( 'Tilbage til vejledningen', 'webto-vejledning' ) . '</a></p></div>';
		return;
	}
	$cats   = webto_vejledning_kategorier();
	$guides = webto_vejledning_alle();
	?>
	<div class="wrap wv wv--single">
		<p class="wv-back"><a href="<?php echo esc_url( admin_url( 'admin.php?page=webto-vejledning' ) ); ?>"><?php esc_html_e( 'Alle vejledninger', 'webto-vejledning' ); ?></a></p>
		<hr class="wp-header-end">
		<?php webto_vejledning_notice(); ?>
		<div class="wv-layout">
			<article class="wv-article">
				<p class="wv-kicker"><?php echo esc_html( $cats[ $g['kategori'] ] ?? '' ); ?></p>
				<h1 class="wv-title"><?php echo esc_html( $g['titel'] ); ?></h1>
				<div class="wv-body">
					<?php echo $g['html'] . $g['felter_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renset med wp_kses_post / esc_* i kilderne. ?>
				</div>
				<footer class="wv-meta">
					<?php if ( $g['rettet'] ) : ?>
						<p class="wv-badge"><?php esc_html_e( 'Rettet her på sitet', 'webto-vejledning' ); ?></p>
					<?php endif; ?>
					<?php if ( webto_vejledning_can_edit() ) : ?>
						<div class="wv-actions">
							<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'webto-vejledning-skriv', 'vejledning' => rawurlencode( $g['id'] ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Ret vejledningen', 'webto-vejledning' ); ?></a>
							<?php if ( $g['post_id'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-wv-confirm="<?php echo esc_attr( $g['rettet'] ? __( 'Sæt vejledningen tilbage til standard? Din rettelse slettes.', 'webto-vejledning' ) : __( 'Slet vejledningen?', 'webto-vejledning' ) ); ?>">
									<input type="hidden" name="action" value="webto_vejledning_nulstil">
									<input type="hidden" name="vejledning" value="<?php echo esc_attr( $g['id'] ); ?>">
									<?php wp_nonce_field( 'webto_vejledning_nulstil_' . $g['id'] ); ?>
									<button type="submit" class="button wv-button-danger"><?php echo esc_html( $g['rettet'] ? __( 'Nulstil til standard', 'webto-vejledning' ) : __( 'Slet vejledningen', 'webto-vejledning' ) ); ?></button>
								</form>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</footer>
			</article>
			<nav class="wv-nav" aria-label="<?php esc_attr_e( 'Andre vejledninger', 'webto-vejledning' ); ?>">
				<?php
				$same = array_filter( $guides, static fn( $o ) => $o['kategori'] === $g['kategori'] );
				?>
				<h2 class="wv-nav__title"><?php echo esc_html( $cats[ $g['kategori'] ] ?? '' ); ?></h2>
				<ul class="wv-list wv-list--compact"><?php echo implode( '', array_map( static fn( $o ) => webto_vejledning_list_item( $o, $g['id'] ), $same ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped i webto_vejledning_list_item. ?></ul>
			</nav>
		</div>
	</div>
	<?php
}

/**
 * Siden "Skriv vejledning": ny vejledning, eller ret en eksisterende (?vejledning=id). En rettelse gemmes i databasen
 * og vinder over filen; "Nulstil til standard" sletter den igen.
 */
function webto_vejledning_edit_page(): void {
	if ( ! webto_vejledning_can_edit() ) {
		wp_die( esc_html__( 'Du har ikke adgang til at rette vejledningen.', 'webto-vejledning' ), 403 );
	}
	$id = isset( $_GET['vejledning'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['vejledning'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$g  = '' !== $id ? webto_vejledning_get( $id ) : null;
	$own = ! $g || 'egen' === $g['kilde'];
	?>
	<div class="wrap wv wv--edit">
		<p class="wv-back"><a href="<?php echo esc_url( $g ? webto_vejledning_view_url( $g['id'] ) : admin_url( 'admin.php?page=webto-vejledning' ) ); ?>"><?php echo esc_html( $g ? __( 'Tilbage til vejledningen', 'webto-vejledning' ) : __( 'Alle vejledninger', 'webto-vejledning' ) ); ?></a></p>
		<h1 class="wv-title"><?php echo esc_html( $g ? __( 'Ret vejledning', 'webto-vejledning' ) : __( 'Skriv en ny vejledning', 'webto-vejledning' ) ); ?></h1>
		<hr class="wp-header-end">
		<?php if ( $g && ! $own ) : ?>
			<p class="wv-lead"><?php esc_html_e( 'Din rettelse gemmes her på sitet og vises i stedet for standardteksten. Du kan altid sætte den tilbage til standard.', 'webto-vejledning' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wv-form">
			<input type="hidden" name="action" value="webto_vejledning_gem">
			<input type="hidden" name="vejledning" value="<?php echo esc_attr( $g['id'] ?? '' ); ?>">
			<?php wp_nonce_field( 'webto_vejledning_gem' ); ?>
			<p class="wv-field">
				<label for="wv-titel"><?php esc_html_e( 'Titel', 'webto-vejledning' ); ?></label>
				<input type="text" class="widefat" id="wv-titel" name="titel" required value="<?php echo esc_attr( $g['titel'] ?? '' ); ?>">
			</p>
			<p class="wv-field">
				<label for="wv-resume"><?php esc_html_e( 'Kort beskrivelse (vises i oversigten)', 'webto-vejledning' ); ?></label>
				<input type="text" class="widefat" id="wv-resume" name="resume" value="<?php echo esc_attr( $g['resume'] ?? '' ); ?>">
			</p>
			<?php if ( $own ) : ?>
				<p class="wv-field">
					<label for="wv-kategori"><?php esc_html_e( 'Emne', 'webto-vejledning' ); ?></label>
					<select id="wv-kategori" name="kategori">
						<?php foreach ( webto_vejledning_kategorier() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $g['kategori'] ?? 'dit-site', $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endif; ?>
			<div class="wv-field">
				<label for="wv_tekst"><?php esc_html_e( 'Tekst', 'webto-vejledning' ); ?></label>
				<?php
				wp_editor(
					(string) ( $g['html'] ?? '' ),
					'wv_tekst',
					array(
						'textarea_name' => 'tekst',
						'textarea_rows' => 18,
						'media_buttons' => current_user_can( 'upload_files' ),
						'teeny'         => false,
					)
				);
				?>
			</div>
			<?php if ( $own && current_user_can( 'manage_options' ) && webto_vejledning_is_webto() ) : ?>
				<p class="wv-field">
					<label for="wv-gaelder"><?php esc_html_e( 'Vises på (valgfrit)', 'webto-vejledning' ); ?></label>
					<input type="text" class="widefat" id="wv-gaelder" name="gaelder" value="<?php echo esc_attr( implode( ', ', $g['gaelder'] ?? array() ) ); ?>" aria-describedby="wv-gaelder-hjaelp">
					<span class="wv-help" id="wv-gaelder-hjaelp"><?php esc_html_e( 'Adskilt med komma, fx side:42, posttype:page eller blok:webto/hero. Så vises vejledningen under knappen Vejledning på de sider.', 'webto-vejledning' ); ?></span>
				</p>
			<?php endif; ?>
			<p class="wv-actions">
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Gem vejledningen', 'webto-vejledning' ); ?></button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Gemmer en rettelse eller en egen vejledning.
 */
function webto_vejledning_handle_save(): void {
	if ( ! webto_vejledning_can_edit() ) {
		wp_die( esc_html__( 'Du har ikke adgang til at rette vejledningen.', 'webto-vejledning' ), 403 );
	}
	check_admin_referer( 'webto_vejledning_gem' );
	$id      = sanitize_text_field( wp_unslash( $_POST['vejledning'] ?? '' ) );
	$title   = sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) );
	$text    = wp_kses_post( wp_unslash( $_POST['tekst'] ?? '' ) );
	$resume  = sanitize_text_field( wp_unslash( $_POST['resume'] ?? '' ) );
	$g       = '' !== $id ? webto_vejledning_get( $id ) : null;
	if ( '' !== $id && ! $g ) {
		wp_die( esc_html__( 'Vejledningen findes ikke.', 'webto-vejledning' ), 404 );
	}
	$own     = ! $g || 'egen' === $g['kilde'];
	$post_id = $g ? (int) $g['post_id'] : 0;

	$postarr = array(
		'ID'           => $post_id,
		'post_type'    => 'webto_vejledning',
		'post_status'  => 'publish',
		'post_title'   => '' !== $title ? $title : __( 'Vejledning', 'webto-vejledning' ),
		'post_content' => $text,
	);
	$post_id = $post_id ? wp_update_post( wp_slash( $postarr ), true ) : wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $post_id ) ) {
		wp_die( esc_html( $post_id->get_error_message() ) );
	}
	update_post_meta( $post_id, '_wv_resume', $resume );
	delete_post_meta( $post_id, '_wv_markdown' ); // Teksten er nu HTML fra editoren.
	if ( $own ) {
		update_post_meta( $post_id, '_wv_kategori', webto_vejledning_kategori( sanitize_key( wp_unslash( $_POST['kategori'] ?? 'dit-site' ) ) ) );
		if ( current_user_can( 'manage_options' ) && webto_vejledning_is_webto() && isset( $_POST['gaelder'] ) ) {
			update_post_meta( $post_id, '_wv_gaelder', webto_vejledning_clean_keys( (string) wp_unslash( $_POST['gaelder'] ) ) );
		}
	} else {
		update_post_meta( $post_id, '_wv_erstatter', $g['id'] );
	}
	$new_id = $own ? 'egen/' . $post_id : $g['id'];
	wp_safe_redirect( add_query_arg( 'wv_besked', 'gemt', webto_vejledning_view_url( $new_id ) ) );
	exit;
}
add_action( 'admin_post_webto_vejledning_gem', 'webto_vejledning_handle_save' );

/**
 * Nøgler som "side:42, posttype:page" -> rene nøgler.
 *
 * @param string|string[] $raw Komma-adskilt tekst eller en liste.
 * @return string[]
 */
function webto_vejledning_clean_keys( $raw ): array {
	$list = is_array( $raw ) ? $raw : explode( ',', $raw );
	$out  = array();
	foreach ( $list as $key ) {
		$key = strtolower( trim( (string) $key ) );
		if ( preg_match( '#^[a-z]+:[a-z0-9_./*-]+$#', $key ) ) {
			$out[] = $key;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Nulstil en rettelse (eller slet en egen vejledning).
 */
function webto_vejledning_handle_reset(): void {
	$id = sanitize_text_field( wp_unslash( $_POST['vejledning'] ?? '' ) );
	if ( ! webto_vejledning_can_edit() ) {
		wp_die( esc_html__( 'Du har ikke adgang til at rette vejledningen.', 'webto-vejledning' ), 403 );
	}
	check_admin_referer( 'webto_vejledning_nulstil_' . $id );
	$g = webto_vejledning_get( $id );
	if ( ! $g || ! $g['post_id'] || 'webto_vejledning' !== get_post_type( (int) $g['post_id'] ) ) {
		wp_die( esc_html__( 'Der er ingen rettelse at nulstille.', 'webto-vejledning' ), 404 );
	}
	wp_delete_post( (int) $g['post_id'], true );
	$target = $g['rettet'] ? add_query_arg( 'wv_besked', 'nulstillet', webto_vejledning_view_url( $g['id'] ) ) : add_query_arg( array( 'page' => 'webto-vejledning', 'wv_besked' => 'slettet' ), admin_url( 'admin.php' ) );
	wp_safe_redirect( $target );
	exit;
}
add_action( 'admin_post_webto_vejledning_nulstil', 'webto_vejledning_handle_reset' );

/**
 * Siden "Dækning": det samme som webto-vejledning/status, så Lasse kan følge med.
 */
function webto_vejledning_coverage_page(): void {
	if ( ! current_user_can( 'manage_options' ) || ! webto_vejledning_is_webto() ) {
		wp_die( esc_html__( 'Du har ikke adgang til siden.', 'webto-vejledning' ), 403 );
	}
	$s = webto_vejledning_status();
	?>
	<div class="wrap wv wv--coverage">
		<h1 class="wv-title"><?php esc_html_e( 'Dækning', 'webto-vejledning' ); ?></h1>
		<hr class="wp-header-end">
		<p class="wv-lead"><?php esc_html_e( 'Kun for Webto. Har alt på sitet en vejledning, og passer vejledningerne stadig til koden? Samme svar som wp webto vejledning status.', 'webto-vejledning' ); ?></p>
		<div class="wv-summary <?php echo $s['ok'] ? 'wv-summary--ok' : 'wv-summary--todo'; ?>" role="status">
			<?php if ( $s['ok'] ) : ?>
				<p><strong><?php esc_html_e( 'Alt er dækket.', 'webto-vejledning' ); ?></strong> <?php esc_html_e( 'Alt har en vejledning, og ingen er forældet.', 'webto-vejledning' ); ?></p>
			<?php else : ?>
				<p><strong><?php echo esc_html( sprintf( /* translators: %d: antal */ _n( '%d ting mangler', '%d ting mangler', (int) $s['antal'], 'webto-vejledning' ), (int) $s['antal'] ) ); ?></strong>
				<?php echo esc_html( sprintf( /* translators: 1: manglende, 2: forældede */ __( '%1$d uden vejledning, %2$d forældede.', 'webto-vejledning' ), count( $s['manglende'] ), count( $s['foraeldede'] ) ) ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( $s['manglende'] ) : ?>
			<h2><?php esc_html_e( 'Mangler en vejledning', 'webto-vejledning' ); ?></h2>
			<div class="wv-table"><table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Hvad', 'webto-vejledning' ); ?></th><th scope="col"><?php esc_html_e( 'Navn', 'webto-vejledning' ); ?></th><th scope="col"><?php esc_html_e( 'Nøgle (gaelder)', 'webto-vejledning' ); ?></th><th scope="col"><?php esc_html_e( 'Forslag til fil i temaet', 'webto-vejledning' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $s['manglende'] as $m ) : ?>
					<tr><td><?php echo esc_html( $m['type'] ); ?></td><td><?php echo esc_html( $m['navn'] ); ?></td><td><code><?php echo esc_html( $m['noegle'] ); ?></code></td><td><code><?php echo esc_html( $m['forslag'] ); ?></code><?php echo '' !== $m['udvider'] ? ' <span class="wv-help">' . esc_html( 'udvider: ' . $m['udvider'] ) . '</span>' : ''; ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>

		<?php if ( $s['foraeldede'] ) : ?>
			<h2><?php esc_html_e( 'Forældede vejledninger', 'webto-vejledning' ); ?></h2>
			<div class="wv-table"><table class="widefat striped">
				<thead><tr><th scope="col"><?php esc_html_e( 'Vejledning', 'webto-vejledning' ); ?></th><th scope="col"><?php esc_html_e( 'Fil', 'webto-vejledning' ); ?></th><th scope="col"><?php esc_html_e( 'Sidst tjekket', 'webto-vejledning' ); ?></th><th scope="col"><?php esc_html_e( 'Ændret siden', 'webto-vejledning' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $s['foraeldede'] as $o ) : ?>
					<tr><td><a href="<?php echo esc_url( webto_vejledning_view_url( $o['id'] ) ); ?>"><?php echo esc_html( $o['titel'] ); ?></a></td><td><code><?php echo esc_html( $o['fil'] ); ?></code></td><td><?php echo esc_html( $o['opdateret'] ); ?></td>
					<td><?php echo esc_html( implode( ', ', array_map( static fn( $k ) => $k['kilde'] . ' (' . $k['grund'] . ')', $o['kilder'] ) ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>

		<details class="wv-details">
			<summary><?php esc_html_e( 'Instruktionen til AI\'en', 'webto-vejledning' ); ?></summary>
			<pre class="wv-pre"><?php echo esc_html( $s['instruktion'] ); ?></pre>
			<h3><?php esc_html_e( 'Skabelon', 'webto-vejledning' ); ?></h3>
			<pre class="wv-pre"><?php echo esc_html( $s['skabelon'] ); ?></pre>
		</details>
	</div>
	<?php
}
