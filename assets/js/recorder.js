/**
 * Recording on a page of the site.
 *
 * While the user records, each page they open is checked once in the background, as the
 * translation editor checks a page, and the panel says which texts of themes and plugins it showed.
 * The page itself does not change. See src/Packages/Recorder.php.
 */
( function () {
	const config = window.swiftlingRecorder;
	const panel = document.getElementById( 'swiftling-recorder' );
	if ( ! config || ! panel || ! window.swiftlingPageCheck ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const status = panel.querySelector( '.swiftling-recorder__status' );
	const list = panel.querySelector( '.swiftling-recorder__found' );
	const total = panel.querySelector( '.swiftling-recorder__total' );
	const toggle = panel.querySelector( '.swiftling-recorder__hide' );
	const body = panel.querySelector( '.swiftling-recorder__body' );

	// The panel can be folded away; it stays folded on the next pages of this visit.
	const FOLDED = 'swiftlingRecorderFolded';
	const fold = ( folded ) => {
		body.hidden = folded;
		toggle.setAttribute( 'aria-expanded', folded ? 'false' : 'true' );
		toggle.textContent = folded ? __( 'Show', 'swiftling' ) : __( 'Hide', 'swiftling' );
		try {
			window.sessionStorage.setItem( FOLDED, folded ? '1' : '' );
		} catch ( error ) {}
	};
	toggle.addEventListener( 'click', () => fold( ! body.hidden ) );
	try {
		if ( window.sessionStorage.getItem( FOLDED ) ) {
			fold( true );
		}
	} catch ( error ) {}

	if ( config.checked ) {
		status.textContent = __( 'This page is recorded already.', 'swiftling' );
		return;
	}

	const show = ( result ) => {
		list.textContent = '';
		if ( ! result.page.length ) {
			status.textContent = __( 'No texts of themes or plugins on this page.', 'swiftling' );
		} else {
			status.textContent = __( 'On this page', 'swiftling' );
			result.page.forEach( ( entry ) => {
				const item = document.createElement( 'li' );
				const name = document.createElement( 'span' );
				const count = document.createElement( 'span' );
				name.textContent = entry.name;
				count.textContent = sprintf(
					/* translators: 1: number of texts on the page, 2: number of them found for the first time. */
					_n( '%1$s · %2$s new', '%1$s · %2$s new', entry.texts, 'swiftling' ),
					entry.texts,
					entry.new
				);
				item.append( name, count );
				list.append( item );
			} );
		}
		list.hidden = ! result.page.length;
		total.textContent = sprintf(
			/* translators: 1: number of texts, 2: number of pages. */
			_n( 'Recorded so far: %1$s new text on %2$s pages', 'Recorded so far: %1$s new texts on %2$s pages', result.total.texts, 'swiftling' ),
			result.total.texts,
			result.total.pages
		);
	};

	const record = async () => {
		const page = await window.swiftlingPageCheck.check( config.probes );
		const response = await fetch( config.rest + 'record', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( {
				subject: config.subject,
				registry: page.registry,
				signature: page.signature,
				found: page.found,
				control: page.control,
				title: page.title,
			} ),
		} );
		if ( ! response.ok ) {
			throw new Error( 'Request failed with status ' + response.status );
		}
		show( await response.json() );
	};

	// Once the page has loaded, so the check never slows down what the user is looking at.
	const start = () =>
		record().catch( ( error ) => {
			status.textContent = __( 'This page could not be checked. It is checked again when you open it next time.', 'swiftling' );
			// eslint-disable-next-line no-console
			console.error( 'SwiftLing:', error );
		} );
	if ( 'complete' === document.readyState ) {
		start();
	} else {
		window.addEventListener( 'load', start, { once: true } );
	}
} )();
