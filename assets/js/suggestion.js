/**
 * Suggests the page in a language the browser prefers, see src/Switcher/Suggestion.php, and notes
 * a language the visitor chose, so the home page no longer opens in the browser's.
 */
( () => {
	document.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '.swiftling-switcher a, .swiftling-menu-language a, .swiftling-suggestion__show' ) ) {
			try {
				localStorage.setItem( 'swiftling-chosen', '1' );
			} catch ( error ) {}
		}
	} );
	const data = document.getElementById( 'swiftling-suggestion-data' );
	if ( ! data ) {
		return;
	}
	const { position, shown, offers } = JSON.parse( data.textContent );
	const base = ( tag ) => tag.toLowerCase().split( '-' )[ 0 ];
	const pages = [ ...document.querySelectorAll( 'link[rel=alternate][hreflang]' ) ].filter( ( link ) => offers[ link.hreflang ] );

	// The first language the visitor prefers that the page is in, unless it is the one shown.
	let offer;
	for ( let tag of navigator.languages || [ navigator.language ] ) {
		tag = String( tag ).toLowerCase();
		offer = pages.find( ( link ) => link.hreflang.toLowerCase() === tag );
		if ( tag === shown.toLowerCase() || ( ! offer && base( tag ) === base( shown ) ) ) {
			return;
		}
		offer = offer || pages.find( ( link ) => base( link.hreflang ) === base( tag ) );
		if ( offer ) {
			break;
		}
	}
	const key = 'swiftling-suggestion';
	let closed = [];
	try {
		closed = JSON.parse( localStorage.getItem( key ) ) || [];
	} catch ( error ) {}
	if ( ! offer || closed.includes( offer.hreflang ) ) {
		return;
	}

	const texts = offers[ offer.hreflang ];
	const make = ( tag, part, props ) => Object.assign( document.createElement( tag ), { className: 'swiftling-suggestion' + part }, props );
	const note = make( 'aside', ' is-' + position, { lang: texts.lang, dir: texts.dir, ariaLabel: texts.label } );
	const dismiss = () => {
		note.remove();
		try {
			localStorage.setItem( key, JSON.stringify( closed.concat( offer.hreflang ) ) );
		} catch ( error ) {}
	};
	note.onkeydown = ( event ) => 'Escape' === event.key && dismiss();
	note.append(
		make( 'p', '__text', { textContent: texts.text } ),
		make( 'a', '__show', { textContent: texts.show, href: offer.href, hreflang: offer.hreflang } ),
		make( 'button', '__close', { textContent: '×', type: 'button', ariaLabel: texts.close, onclick: dismiss } )
	);
	document.body.prepend( note );
} )();
