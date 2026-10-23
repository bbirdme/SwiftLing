/**
 * Addresses screen: a domain changed since the last check shows as not checked, so an old "Leads
 * here" does not stand next to a new domain. The line for wp-config.php gets a Copy button where
 * the browser can copy. Save changes is never locked: for a subdomain or an own domain it checks
 * every address first, and switches once all of them lead here, see swiftling_save_url_format().
 */
( function () {
	const form = document.getElementById( 'swiftling-format' );
	if ( ! form ) {
		return;
	}
	const { __ } = window.wp.i18n;

	form.querySelectorAll( '[data-swiftling-domain]' ).forEach( ( input ) => {
		const checked = input.value;
		input.addEventListener( 'input', () => {
			const row = input.closest( 'tr' );
			const badge = row.querySelector( '.swiftling-badge' );
			const todo = row.querySelector( '.swiftling-hosts__todo' );
			if ( ! badge.dataset.swiftlingChecked ) {
				badge.dataset.swiftlingChecked = badge.className + '|' + badge.textContent;
			}
			const [ className, text ] = badge.dataset.swiftlingChecked.split( '|' );
			const same = input.value.trim() === checked;
			badge.className = same ? className : 'swiftling-badge is-untested';
			badge.textContent = same ? text : __( 'Not checked', 'swiftling' );
			if ( todo ) {
				todo.hidden = ! same;
			}
		} );
	} );

	const copy = form.querySelector( '[data-swiftling-copy]' );
	if ( copy && navigator.clipboard ) {
		copy.hidden = false;
		copy.addEventListener( 'click', () => {
			navigator.clipboard.writeText( document.getElementById( copy.dataset.swiftlingCopy ).textContent ).then( () => {
				copy.textContent = __( 'Copied', 'swiftling' );
			} );
		} );
	}
} )();
