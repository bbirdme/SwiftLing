/**
 * The choice of a language's flag, on the Languages and the Switcher screen, printed by
 * Ui::flagChoice(). A country from the list, no flag, or an image of the media library; the
 * picture beside the list follows. When the flag changes, the box fires swiftling:flag (it bubbles),
 * with detail.url, the address of the flag now shown or an empty string, for the screen to follow.
 */
( function () {
	const { __ } = window.wp.i18n;
	const config = window.swiftlingFlags || { url: '' };

	const urlOf = ( box ) => {
		const image = box.querySelector( 'input[type="hidden"]' ).value;
		if ( image ) {
			return image;
		}
		const country = box.querySelector( 'select' ).value || box.dataset.automatic;
		return country && 'none' !== country ? config.url + country + '.svg' : '';
	};

	const update = ( box ) => {
		const url = urlOf( box );
		const picture = box.querySelector( '.swiftling-flag-choice__image' );
		picture.hidden = ! url;
		if ( url ) {
			picture.src = url;
		}
		box.querySelector( '[data-swiftling-flag-remove]' ).hidden = ! box.querySelector( 'input[type="hidden"]' ).value;
		box.dispatchEvent( new CustomEvent( 'swiftling:flag', { bubbles: true, detail: { url } } ) );
	};

	document.querySelectorAll( '[data-swiftling-flag]' ).forEach( ( box ) => {
		const input = box.querySelector( 'input[type="hidden"]' );
		box.querySelector( 'select' ).addEventListener( 'change', () => update( box ) );

		box.querySelector( '[data-swiftling-flag-choose]' ).addEventListener( 'click', () => {
			if ( ! window.wp.media ) {
				return;
			}
			const frame = window.wp.media( {
				title: __( 'Choose a flag', 'swiftling' ),
				button: { text: __( 'Use as flag', 'swiftling' ) },
				library: { type: 'image' },
				multiple: false,
			} );
			frame.on( 'select', () => {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				// A smaller copy where there is one: a flag is a few pixels high. Not the thumbnail,
				// which WordPress crops to a square.
				const sizes = attachment.sizes || {};
				input.value = ( sizes.medium || attachment ).url;
				update( box );
			} );
			frame.open();
		} );

		box.querySelector( '[data-swiftling-flag-remove]' ).addEventListener( 'click', () => {
			input.value = '';
			update( box );
		} );
	} );
}() );
