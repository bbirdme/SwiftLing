/**
 * Appearance → Menus: the bar of each item names what "Show in" chose, such as "Only HR" or
 * "Not IT", and follows the ticks as they change. WordPress has no hook for the bar itself.
 */
( function () {
	const { __, sprintf } = wp.i18n;

	const badge = ( fieldset ) => {
		const boxes = Array.from( fieldset.querySelectorAll( 'input[type="checkbox"]' ) );
		const shown = boxes.filter( ( box ) => box.checked );
		const out = boxes.filter( ( box ) => ! box.checked );
		const controls = fieldset.closest( '.menu-item' )?.querySelector( '.menu-item-bar .item-controls' );
		if ( ! controls ) {
			return;
		}
		let text = '';
		let variant = '';
		if ( out.length && ! shown.length ) {
			text = __( 'No language', 'swiftling' );
			variant = 'is-none';
		} else if ( out.length && ! boxes[ 0 ].checked ) {
			/* translators: %s: language codes, e.g. DE, EN. */
			text = sprintf( __( 'Only %s', 'swiftling' ), shown.map( ( box ) => box.dataset.code ).join( ', ' ) );
		} else if ( out.length ) {
			/* translators: %s: language codes, e.g. DE, EN. */
			text = sprintf( __( 'Not %s', 'swiftling' ), out.map( ( box ) => box.dataset.code ).join( ', ' ) );
			variant = 'is-not';
		}
		let element = controls.querySelector( '.swiftling-show-in-badge' );
		if ( ! text ) {
			element?.remove();
			return;
		}
		if ( ! element ) {
			element = document.createElement( 'span' );
			controls.prepend( element );
		}
		element.className = ( 'swiftling-show-in-badge ' + variant ).trim();
		element.textContent = text;
	};

	const all = () => document.querySelectorAll( '[data-swiftling-show-in]' ).forEach( badge );

	document.addEventListener( 'change', ( event ) => {
		const fieldset = event.target.closest?.( '[data-swiftling-show-in]' );
		if ( fieldset ) {
			badge( fieldset );
		}
	} );
	// Items added from the side come in later, without a page load.
	const list = document.getElementById( 'menu-to-edit' );
	if ( list ) {
		new MutationObserver( all ).observe( list, { childList: true } );
	}
	all();
} )();
