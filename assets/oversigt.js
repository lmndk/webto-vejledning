/**
 * Webto Vejledning - oversigtens emnekort som masonry (Lasse 2026-11-25).
 *
 * Gitteret (.wv-cats) har rækker på 1 px; hvert kort får lige så mange rækker, som det er højt. Så lukker kortene op
 * under hinanden, og rækkefølgen er stadig fra venstre mod højre (DOM og tastatur uændret). Uden JS er det et
 * almindeligt gitter. Højderne måles igen, når et kort ændrer størrelse (skrifter, bredde, zoom).
 */
( () => {
	const grid = document.querySelector( '.wv-cats' );
	if ( ! grid || ! ( 'ResizeObserver' in window ) ) {
		return;
	}

	const layout = () => {
		// Rækkerne har ingen afstand (1 px hver); afstanden under kortet = afstanden mellem kolonnerne.
		const gap = parseFloat( getComputedStyle( grid ).columnGap ) || 0;
		for ( const card of grid.children ) {
			const height = card.getBoundingClientRect().height;
			card.style.gridRowEnd = 'span ' + Math.max( 1, Math.ceil( height + gap ) );
		}
	};

	let frame = 0;
	const schedule = () => {
		cancelAnimationFrame( frame );
		frame = requestAnimationFrame( layout );
	};

	grid.classList.add( 'wv-cats--masonry' );
	const observer = new ResizeObserver( schedule );
	for ( const card of grid.children ) {
		observer.observe( card );
	}
	schedule();
} )();
