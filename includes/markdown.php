<?php
/**
 * Markdown og frontmatter. Bevidst lille: det, en vejledning har brug for, og intet andet.
 *
 * Understøtter: # overskrifter, afsnit, - og 1. lister, > tip-bokse, ``` kode, | tabeller, **fed**, *kursiv*, `kode`,
 * [link](adresse) og ![billede](adresse). Særlige adresser:
 *   admin:edit.php?post_type=page   -> en side i wp-admin
 *   vejledning:fast/wordpress-sider -> en anden vejledning
 *   billede:navn.png                -> en fil i pluginnets billeder/ (eller billede:tema/navn.png i temaets vejledning/billeder/)
 * En linje med kun [[navn]] erstattes af HTML fra filteret webto_vejledning_pladsholder (fx [[farver]] i Kadence-vejledningen).
 * Al tekst escapes, og resultatet sendes til sidst gennem wp_kses_post.
 *
 * @package Webto_Vejledning
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deler en fil i frontmatter (mellem to linjer med ---) og brødtekst.
 *
 * Frontmatter er en lille del af YAML: "nøgle: værdi", lister som [a, b] eller linjer med "- a".
 *
 * @param string $raw Filens indhold.
 * @return array{meta: array<string, string|string[]>, body: string}
 */
function webto_vejledning_frontmatter( string $raw ): array {
	$raw  = str_replace( array( "\r\n", "\r" ), "\n", $raw );
	$raw  = preg_replace( '/^\xEF\xBB\xBF/', '', $raw ) ?? $raw; // BOM.
	$meta = array();
	if ( ! preg_match( '/^---\n(.*?)\n---\n?(.*)$/s', $raw, $m ) ) {
		return array( 'meta' => $meta, 'body' => $raw );
	}
	$key = null;
	foreach ( explode( "\n", $m[1] ) as $line ) {
		if ( '' === trim( $line ) || str_starts_with( ltrim( $line ), '#' ) ) {
			continue;
		}
		if ( null !== $key && preg_match( '/^\s+-\s*(.*)$/', $line, $item ) ) {
			$meta[ $key ]   = is_array( $meta[ $key ] ) ? $meta[ $key ] : array();
			$meta[ $key ][] = webto_vejledning_unquote( $item[1] );
			continue;
		}
		if ( preg_match( '/^([a-zA-Z0-9_æøåÆØÅ-]+)\s*:\s*(.*)$/u', $line, $kv ) ) {
			$key   = strtolower( $kv[1] );
			$value = trim( $kv[2] );
			if ( '' === $value ) {
				$meta[ $key ] = array();
			} elseif ( preg_match( '/^\[(.*)\]$/', $value, $list ) ) {
				$meta[ $key ] = array_values( array_filter( array_map( 'webto_vejledning_unquote', explode( ',', $list[1] ) ), 'strlen' ) );
			} else {
				$meta[ $key ] = webto_vejledning_unquote( $value );
			}
		}
	}
	return array( 'meta' => $meta, 'body' => $m[2] );
}

/**
 * Fjerner anførselstegn om en værdi.
 *
 * @param string $value Værdien.
 */
function webto_vejledning_unquote( string $value ): string {
	$value = trim( $value );
	if ( strlen( $value ) >= 2 && ( ( '"' === $value[0] && '"' === substr( $value, -1 ) ) || ( "'" === $value[0] && "'" === substr( $value, -1 ) ) ) ) {
		$value = substr( $value, 1, -1 );
	}
	return $value;
}

/**
 * Laver en særlig adresse om til en rigtig (se toppen af filen). Ukendte skemaer bliver til '#'.
 *
 * @param string $url Adressen fra Markdown (allerede uden HTML-escaping).
 */
function webto_vejledning_resolve_url( string $url ): string {
	if ( str_starts_with( $url, 'admin:' ) ) {
		return admin_url( substr( $url, 6 ) );
	}
	if ( str_starts_with( $url, 'vejledning:' ) ) {
		return webto_vejledning_view_url( substr( $url, 11 ) );
	}
	if ( str_starts_with( $url, 'billede:' ) ) {
		$file = substr( $url, 8 );
		if ( str_starts_with( $file, 'tema/' ) ) {
			return get_stylesheet_directory_uri() . '/vejledning/billeder/' . rawurlencode( basename( $file ) );
		}
		return WEBTO_VEJLEDNING_URL . 'billeder/' . rawurlencode( basename( $file ) );
	}
	if ( preg_match( '#^(https?:|mailto:|tel:|/|\#)#', $url ) ) {
		return $url;
	}
	return '#';
}

/**
 * Inline-formatering af én linje tekst. Teksten escapes først; derefter tilføjes de få tilladte mærker.
 *
 * @param string $text Rå tekst.
 */
function webto_vejledning_inline( string $text ): string {
	$codes = array();
	// `kode` først, så intet andet formateres inde i den.
	$text = preg_replace_callback(
		'/`([^`]+)`/',
		static function ( $m ) use ( &$codes ) {
			$codes[] = '<code>' . esc_html( $m[1] ) . '</code>';
			return "\x1A" . ( count( $codes ) - 1 ) . "\x1A";
		},
		$text
	) ?? $text;

	$text = esc_html( $text );

	// Billeder og links (adressen er escaped; den læses tilbage, før den bygges igen).
	$text = preg_replace_callback(
		'/!\[([^\]]*)\]\(([^)\s]+)\)/',
		static fn( $m ) => '<img src="' . esc_url( webto_vejledning_resolve_url( html_entity_decode( $m[2], ENT_QUOTES ) ) ) . '" alt="' . $m[1] . '" loading="lazy">',
		$text
	) ?? $text;
	$text = preg_replace_callback(
		'/\[([^\]]+)\]\(([^)\s]+)\)/',
		static function ( $m ) {
			$raw  = html_entity_decode( $m[2], ENT_QUOTES );
			$url  = webto_vejledning_resolve_url( $raw );
			$ext  = (bool) preg_match( '#^https?://#', $url ) && ! str_starts_with( $url, home_url() );
			$attr = $ext ? ' target="_blank" rel="noopener"' : '';
			return '<a href="' . esc_url( $url ) . '"' . $attr . '>' . $m[1] . ( $ext ? '<span class="screen-reader-text"> ' . esc_html__( '(åbner i et nyt vindue)', 'webto-vejledning' ) . '</span>' : '' ) . '</a>';
		},
		$text
	) ?? $text;

	$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text ) ?? $text;
	$text = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text ) ?? $text;
	$text = preg_replace( '/(?<![\w])_(?!\s)(.+?)(?<!\s)_(?![\w])/', '<em>$1</em>', $text ) ?? $text;

	return preg_replace_callback( "/\x1A(\d+)\x1A/", static fn( $m ) => $codes[ (int) $m[1] ] ?? '', $text ) ?? $text;
}

/**
 * Markdown -> HTML.
 *
 * @param string $md Markdown (uden frontmatter).
 */
function webto_vejledning_markdown( string $md ): string {
	$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $md ) );
	$html  = '';
	$para  = array();
	$list  = null; // 'ul' eller 'ol'.
	$items = array();
	$quote = array();

	$flush_para  = static function () use ( &$para, &$html ) {
		if ( $para ) {
			$html .= '<p>' . webto_vejledning_inline( implode( ' ', $para ) ) . "</p>\n";
			$para  = array();
		}
	};
	$flush_list  = static function () use ( &$list, &$items, &$html ) {
		if ( $list ) {
			$html .= '<' . $list . '>' . implode( '', array_map( static fn( $i ) => '<li>' . webto_vejledning_inline( $i ) . '</li>', $items ) ) . '</' . $list . ">\n";
			$list  = null;
			$items = array();
		}
	};
	$flush_quote = static function () use ( &$quote, &$html ) {
		if ( $quote ) {
			$html .= '<div class="wv-tip">' . webto_vejledning_markdown( implode( "\n", $quote ) ) . "</div>\n";
			$quote = array();
		}
	};
	$flush_all   = static function () use ( $flush_para, $flush_list, $flush_quote ) {
		$flush_para();
		$flush_list();
		$flush_quote();
	};

	$count = count( $lines );
	for ( $i = 0; $i < $count; $i++ ) {
		$line = $lines[ $i ];

		// Kodeblok.
		if ( preg_match( '/^```/', $line ) ) {
			$flush_all();
			$code = array();
			for ( $i++; $i < $count && ! preg_match( '/^```/', $lines[ $i ] ); $i++ ) {
				$code[] = $lines[ $i ];
			}
			$html .= '<pre><code>' . esc_html( implode( "\n", $code ) ) . "</code></pre>\n";
			continue;
		}
		// Tip-boks.
		if ( preg_match( '/^>\s?(.*)$/', $line, $m ) ) {
			$flush_para();
			$flush_list();
			$quote[] = $m[1];
			continue;
		}
		$flush_quote();

		if ( '' === trim( $line ) ) {
			$flush_para();
			$flush_list();
			continue;
		}
		// Pladsholder.
		if ( preg_match( '/^\[\[([a-z0-9-]+)\]\]$/', trim( $line ), $m ) ) {
			$flush_all();
			$html .= (string) apply_filters( 'webto_vejledning_pladsholder', '', $m[1] ) . "\n";
			continue;
		}
		// Overskrift: # og ## bliver h2 (sidens titel er h1), ### h3, #### h4.
		if ( preg_match( '/^(#{1,4})\s+(.*)$/', $line, $m ) ) {
			$flush_all();
			$level = max( 2, strlen( $m[1] ) );
			$html .= '<h' . $level . '>' . webto_vejledning_inline( trim( $m[2] ) ) . '</h' . $level . ">\n";
			continue;
		}
		// Tabel: linjer, der starter med |, og anden linje er |---|.
		if ( str_starts_with( trim( $line ), '|' ) && isset( $lines[ $i + 1 ] ) && preg_match( '/^\s*\|[\s:|-]+\|\s*$/', $lines[ $i + 1 ] ) ) {
			$flush_all();
			$cells = static fn( $row ) => array_map( 'trim', explode( '|', trim( trim( $row ), '|' ) ) );
			$html .= '<div class="wv-table"><table><thead><tr>' . implode( '', array_map( static fn( $c ) => '<th scope="col">' . webto_vejledning_inline( $c ) . '</th>', $cells( $line ) ) ) . '</tr></thead><tbody>';
			for ( $i += 2; $i < $count && str_starts_with( trim( $lines[ $i ] ), '|' ); $i++ ) {
				$html .= '<tr>' . implode( '', array_map( static fn( $c ) => '<td>' . webto_vejledning_inline( $c ) . '</td>', $cells( $lines[ $i ] ) ) ) . '</tr>';
			}
			$i--;
			$html .= "</tbody></table></div>\n";
			continue;
		}
		// Lister.
		if ( preg_match( '/^\s*([-*]|\d+[.)])\s+(.*)$/', $line, $m ) ) {
			$flush_para();
			$type = ctype_digit( $m[1][0] ) ? 'ol' : 'ul';
			if ( $list && $list !== $type ) {
				$flush_list();
			}
			$list    = $type;
			$items[] = $m[2];
			continue;
		}
		// En linje med indrykning lige efter et listepunkt hører til punktet.
		if ( $list && preg_match( '/^\s{2,}(\S.*)$/', $line, $m ) ) {
			$items[ count( $items ) - 1 ] .= ' ' . $m[1];
			continue;
		}
		$flush_list();
		$para[] = trim( $line );
	}
	$flush_all();
	return wp_kses_post( $html );
}

/**
 * Ren tekst til søgningen (uden HTML, med mellemrum mellem blokke).
 *
 * @param string $html HTML.
 */
function webto_vejledning_plain( string $html ): string {
	$text = wp_strip_all_tags( preg_replace( '/<\/(p|li|h\d|td|th|div)>/', ' ', $html ) ?? $html );
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES ) ) ?? $text );
}
