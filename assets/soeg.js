/**
 * Webto Vejledning - søgning, der svarer med det samme (i wp-admin og i panelet på sitet).
 *
 * Indekset ligger enten i siden (<script type="application/json" data-wv-index>) eller hentes første gang, man søger
 * (data-wv-src på søgefeltet, kun for brugere, der er logget ind). Alle ord skal findes; titlen vejer mest.
 * Resultater bygges med textContent (ingen HTML fra indekset). Med data-wv-url="soeg" står søgeordene i adressen
 * (oversigten), så "Tilbage" fra en vejledning viser den samme søgning igen.
 */
( () => {
	const norm = ( s ) => String( s || '' ).toLocaleLowerCase( 'da' ).normalize( 'NFKC' );

	function plural( n ) {
		if ( 0 === n ) {
			return 'Ingen vejledninger fundet. Prøv et andet ord.';
		}
		return 1 === n ? '1 vejledning fundet' : n + ' vejledninger fundet';
	}

	function init( box ) {
		const scope = box.closest( '.wv, .wv-panel' ) || document;
		const input = box.querySelector( '[data-wv-q]' );
		const list = box.querySelector( '[data-wv-results]' );
		const count = box.querySelector( '[data-wv-count]' );
		const browse = scope.querySelector( '[data-wv-browse]' );
		const inline = scope.querySelector( 'script[data-wv-index]' );
		const param = box.dataset.wvUrl || '';
		let index = null;
		let loading = null;
		let timer = 0;

		async function load() {
			if ( index ) {
				return index;
			}
			let raw = [];
			if ( inline ) {
				try {
					raw = JSON.parse( inline.textContent );
				} catch ( e ) {
					raw = [];
				}
			} else if ( box.dataset.wvSrc ) {
				loading = loading || fetch( box.dataset.wvSrc, { credentials: 'same-origin' } ).then( ( r ) => ( r.ok ? r.json() : [] ) ).catch( () => [] );
				raw = await loading;
			}
			index = ( Array.isArray( raw ) ? raw : [] ).map( ( g ) => ( {
				...g,
				_t: norm( g.titel ),
				_r: norm( g.kategori + ' ' + g.resume ),
				_x: norm( g.tekst ),
			} ) );
			return index;
		}

		function search( q ) {
			const words = norm( q ).split( /\s+/ ).filter( Boolean );
			const hits = [];
			for ( const g of index ) {
				let score = 0;
				let all = true;
				for ( const w of words ) {
					const t = g._t.includes( w ) ? ( g._t.startsWith( w ) ? 12 : 10 ) : 0;
					const r = g._r.includes( w ) ? 4 : 0;
					const x = g._x.includes( w ) ? 1 : 0;
					if ( ! t && ! r && ! x ) {
						all = false;
						break;
					}
					score += t + r + x;
				}
				if ( all ) {
					hits.push( [ score, g ] );
				}
			}
			return hits.sort( ( a, b ) => b[ 0 ] - a[ 0 ] ).slice( 0, 25 ).map( ( h ) => h[ 1 ] );
		}

		function item( g ) {
			const li = document.createElement( 'li' );
			const a = document.createElement( 'a' );
			a.className = 'wv-list__link';
			a.href = g.url;
			const title = document.createElement( 'span' );
			title.className = 'wv-list__title';
			title.textContent = g.titel;
			const meta = document.createElement( 'span' );
			meta.className = 'wv-list__resume';
			meta.textContent = g.kategori + ( g.resume ? ': ' + g.resume : '' );
			a.append( title, meta );
			li.append( a );
			return li;
		}

		// Søgeordene i adressen uden et nyt trin i historikken (tilbage-knappen springer ikke hvert bogstav igennem).
		function remember( q ) {
			if ( ! param ) {
				return;
			}
			const url = new URL( window.location.href );
			if ( q ) {
				url.searchParams.set( param, q );
			} else {
				url.searchParams.delete( param );
			}
			window.history.replaceState( window.history.state, '', url );
		}

		async function render() {
			const q = input.value.trim();
			remember( q );
			if ( ! q ) {
				list.hidden = true;
				list.replaceChildren();
				count.textContent = '';
				if ( browse ) {
					browse.hidden = false;
				}
				return;
			}
			await load();
			const hits = search( q );
			list.replaceChildren( ...hits.map( item ) );
			list.hidden = 0 === hits.length;
			count.textContent = plural( hits.length );
			if ( browse ) {
				browse.hidden = true;
			}
		}

		input.addEventListener( 'input', () => {
			clearTimeout( timer );
			timer = setTimeout( render, 120 );
		} );
		input.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key && input.value ) {
				e.preventDefault();
				e.stopPropagation(); // Luk ikke panelet; ryd først feltet.
				input.value = '';
				render();
			}
		} );
		// Hent indekset i forvejen, når man går ind i feltet (panelet).
		input.addEventListener( 'focus', () => {
			load();
		}, { once: true } );
		// Kom man tilbage til en søgning (?soeg=), vises resultaterne med det samme.
		if ( input.value.trim() ) {
			render();
		}
	}

	document.querySelectorAll( '[data-wv-search]' ).forEach( init );

	// "Tilbage" på en vejledning: som browserens tilbage-knap, så søgningen, de åbne emner og rullepositionen er
	// som før. Uden historik (fx åbnet i en ny fane) følger knappen bare linket.
	document.querySelectorAll( '[data-wv-tilbage]' ).forEach( ( link ) => {
		link.addEventListener( 'click', ( e ) => {
			if ( e.ctrlKey || e.metaKey || e.shiftKey || 0 !== e.button || ! document.referrer || window.history.length < 2 ) {
				return;
			}
			e.preventDefault();
			window.history.back();
		} );
	} );

	// "Nulstil til standard" / "Slet vejledningen" spørger først.
	document.querySelectorAll( 'form[data-wv-confirm]' ).forEach( ( form ) => {
		form.addEventListener( 'submit', ( e ) => {
			if ( ! window.confirm( form.dataset.wvConfirm ) ) {
				e.preventDefault();
			}
		} );
	} );
} )();
