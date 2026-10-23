/**
 * Settings screen.
 *
 * - The confirmation of starting over opens as a dialog. Its button starts over only once the
 *   confirmation words are typed; the server checks them again.
 * - The index of translated text, which search in the visitor's language reads, is built again in
 *   batches from here, see includes/search.php.
 */
( function () {
	const dialog = document.getElementById( 'swiftling-start-over-dialog' );
	if ( ! dialog || 'function' !== typeof dialog.showModal ) {
		return;
	}
	const input = dialog.querySelector( '[data-swiftling-confirm]' );
	const submit = dialog.querySelector( '[data-swiftling-submit]' );
	const update = () => {
		submit.disabled = input.value.trim().toUpperCase() !== input.dataset.swiftlingConfirm.toUpperCase();
	};

	// The button of the card and the one in the header. Without this script, both open the screen
	// with the confirmation shown in place.
	document.querySelectorAll( '[data-swiftling-open], a[href*="swiftling_confirm=1"]' ).forEach( ( opener ) => {
		opener.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			input.value = '';
			update();
			dialog.showModal();
			input.focus();
		} );
	} );
	dialog.querySelector( '[data-swiftling-close]' ).addEventListener( 'click', ( event ) => {
		event.preventDefault();
		dialog.close();
	} );
	input.addEventListener( 'input', update );
	update();
} )();

/* Building the index of translated text again, one batch of pages at a time. */
( function () {
	const element = document.getElementById( 'swiftling-search-config' );
	if ( ! element ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const config = JSON.parse( element.textContent );
	const button = document.getElementById( 'swiftling-search-build' );
	const status = document.getElementById( 'swiftling-search-status' );
	const state = document.getElementById( 'swiftling-search-state' );

	const batch = async ( after ) => {
		const response = await fetch( config.rest + 'search-index', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( { after } ),
		} );
		if ( ! response.ok ) {
			throw new Error( 'Request failed with status ' + response.status );
		}
		return response.json();
	};

	button.addEventListener( 'click', async () => {
		button.disabled = true;
		let after = '';
		let done = 0;
		try {
			// Each batch is a few pages; the page stays open until the last one answers.
			for (;;) {
				const result = await batch( after );
				done += result.done;
				after = result.after;
				status.textContent = sprintf(
					/* translators: %s: number of pages. */
					_n( 'Building the index: %s page so far…', 'Building the index: %s pages so far…', done, 'swiftling' ),
					done
				);
				if ( result.finished ) {
					state.textContent = [
						sprintf(
							/* translators: %s: number of pages. */
							_n( '%s page in the index', '%s pages in the index', result.pages, 'swiftling' ),
							result.pages
						),
						sprintf(
							/* translators: %s: number of languages. */
							_n( '%s language', '%s languages', result.languages, 'swiftling' ),
							result.languages
						),
					].join( ' · ' );
					status.textContent = __( 'The index is up to date.', 'swiftling' );
					break;
				}
			}
		} catch ( error ) {
			status.textContent = __( 'The index could not be built. Try again.', 'swiftling' );
			// eslint-disable-next-line no-console
			console.error( 'SwiftLing:', error );
		} finally {
			button.disabled = false;
		}
	} );
} )();
