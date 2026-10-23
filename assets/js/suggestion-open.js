/**
 * Opens the home page in the language the browser prefers, the first time, see
 * src/Switcher/Suggestion.php. Printed inline in the head, after the hreflang links it reads, so it
 * runs before the page paints. It picks the language as the note does (assets/js/suggestion.js).
 */
( ( data ) => {
	try {
		const store = window.localStorage;
		if ( store.getItem( 'swiftling-chosen' ) || /bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|lighthouse/i.test( navigator.userAgent ) ) {
			return;
		}
		// From a page of the site itself, the visitor chose this page.
		if ( document.referrer && data.hosts.includes( new URL( document.referrer ).hostname ) ) {
			return;
		}
		const base = ( tag ) => tag.toLowerCase().split( '-' )[ 0 ];
		const pages = [ ...document.querySelectorAll( 'link[rel=alternate][hreflang]' ) ].filter( ( link ) => data.offers.includes( link.hreflang ) );
		let offer;
		for ( let tag of navigator.languages || [ navigator.language ] ) {
			tag = String( tag ).toLowerCase();
			offer = pages.find( ( link ) => link.hreflang.toLowerCase() === tag );
			if ( tag === data.shown.toLowerCase() || ( ! offer && base( tag ) === base( data.shown ) ) ) {
				return;
			}
			offer = offer || pages.find( ( link ) => base( link.hreflang ) === base( tag ) );
			if ( offer ) {
				break;
			}
		}
		// A language whose note the visitor closed is not opened either.
		if ( ! offer || ( JSON.parse( store.getItem( 'swiftling-suggestion' ) ) || [] ).includes( offer.hreflang ) ) {
			return;
		}
		// Only the first time: from now on the visitor's own choices count.
		store.setItem( 'swiftling-chosen', '1' );
		window.location.replace( offer.href );
	} catch ( error ) {}
} )( SWIFTLING_OPENING );
