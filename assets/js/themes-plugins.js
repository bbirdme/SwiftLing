/**
 * Themes & plugins screen.
 *
 * - The scan of a theme's or plugin's settings first checks the front page of the site in hidden
 *   frames, as a recording does, so the server learns which settings show there and which code reads
 *   them; then the list is loaded again. It also counts the values ticked.
 * - The scan of everything in the code runs in steps, each a few seconds of reading files, and
 *   opens the list of all texts when it is done.
 *
 * See admin/views/themes-plugins-choose.php and themes-plugins-scan.php.
 */
( function () {
	const { __, _n, sprintf } = window.wp.i18n;

	const post = async ( config, route, body ) => {
		const response = await fetch( config.rest + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body ),
		} );
		if ( ! response.ok ) {
			throw new Error( 'Request failed with status ' + response.status );
		}
		return response.json();
	};

	/* Explanations of the columns: Escape hides the one shown, as tooltips should. */

	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key ) {
			document.querySelectorAll( '.swiftling-tip' ).forEach( ( tip ) => tip.classList.add( 'is-dismissed' ) );
		}
	} );
	document.addEventListener( 'pointerover', ( event ) => {
		const tip = event.target.closest && event.target.closest( '.swiftling-tip' );
		if ( tip ) {
			tip.classList.remove( 'is-dismissed' );
		}
	} );
	document.addEventListener( 'focusin', ( event ) => {
		const tip = event.target.closest && event.target.closest( '.swiftling-tip' );
		if ( tip ) {
			tip.classList.remove( 'is-dismissed' );
		}
	} );

	/* The scan of settings. */

	const scanConfig = document.getElementById( 'swiftling-scan-config' );
	if ( scanConfig ) {
		const config = JSON.parse( scanConfig.textContent );
		const form = document.getElementById( 'swiftling-scan' );
		const count = document.getElementById( 'swiftling-scan-count' );
		const again = document.getElementById( 'swiftling-scan-again' );
		const status = document.getElementById( 'swiftling-scan-check' );

		const showCount = () => {
			const boxes = form.querySelectorAll( 'input[name="paths[]"]' );
			const ticked = form.querySelectorAll( 'input[name="paths[]"]:checked' ).length;
			count.textContent = sprintf(
				/* translators: 1: values ticked, 2: all values. */
				_n( '%1$s of %2$s chosen. You can change this any time.', '%1$s of %2$s chosen. You can change this any time.', boxes.length, 'swiftling' ),
				ticked,
				boxes.length
			);
		};
		if ( form ) {
			form.addEventListener( 'change', showCount );
			showCount();
		}

		const checkFront = async () => {
			again.disabled = true;
			status.textContent = __( 'Checking the front page of the site in the background…', 'swiftling' );
			try {
				const page = await window.swiftlingPageCheck.check( config.probes );
				await post( config, 'record', {
					subject: config.subject,
					registry: page.registry,
					signature: page.signature,
					found: page.found,
					control: page.control,
					title: page.title,
				} );
				window.location.href = config.reload;
			} catch ( error ) {
				again.disabled = false;
				status.textContent = __( 'The front page could not be checked. The list shows what SwiftLing knew before.', 'swiftling' );
				// eslint-disable-next-line no-console
				console.error( 'SwiftLing:', error );
			}
		};
		if ( config.probes ) {
			again.addEventListener( 'click', checkFront );
			if ( config.auto ) {
				checkFront();
			}
		}
	}

	/* The scan of the code. */

	const codeConfig = document.getElementById( 'swiftling-code-scan-config' );
	if ( codeConfig ) {
		const config = JSON.parse( codeConfig.textContent );
		const start = document.getElementById( 'swiftling-code-scan-start' );
		const status = document.getElementById( 'swiftling-code-scan-status' );
		const bar = document.getElementById( 'swiftling-code-scan-bar' );
		const fill = bar.querySelector( '.is-progress' );

		const scan = async () => {
			start.disabled = true;
			let offset = 0;
			let texts = 0;
			try {
				for ( ;; ) {
					const step = await post( config, 'scan-code', { package: config.package, offset } );
					offset = step.offset;
					texts += step.texts;
					const percent = step.total ? Math.round( ( 100 * offset ) / step.total ) : 100;
					fill.style.width = percent + '%';
					bar.setAttribute( 'aria-valuenow', String( percent ) );
					status.textContent = sprintf( config.strings.progress, offset, step.total, texts );
					if ( step.done ) {
						break;
					}
				}
				window.location.href = config.done;
			} catch ( error ) {
				start.disabled = false;
				status.textContent = config.strings.failed;
				// eslint-disable-next-line no-console
				console.error( 'SwiftLing:', error );
			}
		};
		start.addEventListener( 'click', scan );
		if ( config.auto ) {
			scan();
		}
	}
} )();
