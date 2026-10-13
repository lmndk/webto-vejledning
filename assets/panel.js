/**
 * Webto Vejledning - panelet på sitet. Knappen "Vejledning" i adminbaren åbner et <dialog> i stedet for at gå til
 * wp-admin (uden JavaScript følger knappen bare linket). Esc, Luk og et klik ved siden af lukker; fokus går tilbage
 * til knappen.
 */
( () => {
	const dialog = document.getElementById( 'wv-panel' );
	if ( ! dialog || 'function' !== typeof dialog.showModal ) {
		return;
	}
	const title = dialog.querySelector( '.wv-panel__title' );
	let opener = null;

	title.setAttribute( 'tabindex', '-1' );

	document.addEventListener( 'click', ( e ) => {
		const link = e.target.closest( '#wp-admin-bar-webto-vejledning > a' );
		if ( ! link || e.ctrlKey || e.metaKey || e.shiftKey || 0 !== e.button ) {
			return;
		}
		e.preventDefault();
		opener = link;
		dialog.showModal();
		title.focus();
	} );

	dialog.querySelector( '[data-wv-close]' ).addEventListener( 'click', () => dialog.close() );

	// Et klik på baggrunden (selve dialog-elementet uden for indholdet) lukker.
	dialog.addEventListener( 'click', ( e ) => {
		if ( e.target === dialog ) {
			dialog.close();
		}
	} );

	dialog.addEventListener( 'close', () => {
		if ( opener ) {
			opener.focus();
		}
	} );
} )();
