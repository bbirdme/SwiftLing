/**
 * Language switcher dropdowns on the site. They open and close without this script; it only
 * closes an open one when the visitor clicks elsewhere, presses Escape or tabs out of it.
 *
 * The globe of a menu leads nowhere: a click or a tap opens its languages, as the theme opens a
 * submenu, also where the theme does so only on hover, see src/Switcher/NavMenu.php.
 */
( function () {
	const OPEN = '.swiftling-switcher__dropdown[open]';
	const GLOBE = '.swiftling-menu-language-globe';

	const setGlobe = ( item, open ) => {
		item.classList.toggle( 'swiftling-is-open', open );
		item.querySelector( 'a' ).setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	};
	const closeGlobes = ( except ) => {
		document.querySelectorAll( GLOBE + '.swiftling-is-open' ).forEach( ( item ) => {
			if ( item !== except ) {
				setGlobe( item, false );
			}
		} );
	};

	const closeAll = ( except ) => {
		document.querySelectorAll( OPEN ).forEach( ( dropdown ) => {
			if ( dropdown !== except ) {
				dropdown.open = false;
			}
		} );
	};

	document.addEventListener( 'click', ( event ) => {
		closeAll( event.target instanceof Element ? event.target.closest( '.swiftling-switcher__dropdown' ) : null );
	} );

	// Before the theme sees it: themes close their phone menu on a click on any of its links, as
	// Divi does, and the globe only opens its languages.
	document.addEventListener(
		'click',
		( event ) => {
			const globe = event.target instanceof Element ? event.target.closest( GLOBE + ' > a' ) : null;
			closeGlobes( globe ? globe.parentElement : null );
			if ( globe ) {
				event.preventDefault();
				event.stopPropagation();
				setGlobe( globe.parentElement, ! globe.parentElement.classList.contains( 'swiftling-is-open' ) );
			}
		},
		true
	);

	document.addEventListener( 'keydown', ( event ) => {
		// The globe is a button to screen readers, which also opens with Space.
		if ( ' ' === event.key && event.target instanceof Element && event.target.matches( GLOBE + ' > a' ) ) {
			event.preventDefault();
			event.target.click();
			return;
		}
		if ( 'Escape' !== event.key ) {
			return;
		}
		const globe = document.querySelector( GLOBE + '.swiftling-is-open' );
		if ( globe ) {
			setGlobe( globe, false );
			globe.querySelector( 'a' ).focus();
		}
		const dropdown = document.activeElement && document.activeElement.closest ? document.activeElement.closest( OPEN ) : null;
		closeAll( null );
		if ( dropdown ) {
			dropdown.querySelector( 'summary' ).focus();
		}
	} );

	// Only when focus moves on to something else: a click on a link does not focus it in every
	// browser, and closing then would hide the link before the click lands.
	document.addEventListener( 'focusout', ( event ) => {
		const dropdown = event.target instanceof Element ? event.target.closest( OPEN ) : null;
		if ( dropdown && event.relatedTarget && ! dropdown.contains( event.relatedTarget ) ) {
			dropdown.open = false;
		}
	} );
} )();
