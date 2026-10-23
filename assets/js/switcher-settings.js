/**
 * Switcher screen. The preview is the switcher as the site prints it, with every part; this
 * script shows in it what the choices on the screen would show, before they are saved.
 */
( function () {
	const preview = document.querySelector( '[data-swiftling-preview]' );
	if ( ! preview ) {
		return;
	}

	const panels = Array.from( document.querySelectorAll( '[data-swiftling-place]' ) );

	/* What each language shows in one place, by the same rules as Switcher::label() on the server. */
	const updatePlace = ( panel ) => {
		const switches = {};
		panel.querySelectorAll( '[data-swiftling-show]' ).forEach( ( input ) => {
			switches[ input.dataset.swiftlingShow ] = input;
		} );
		const group = preview.querySelector( '[data-swiftling-preview-place="' + panel.dataset.swiftlingPlace + '"]' );
		if ( ! group ) {
			return;
		}
		const show = {
			flag: switches.flag.checked,
			name: switches.name.checked,
			code: switches.code.checked,
		};
		// A switcher showing nothing would be a row of empty links, so it shows names.
		if ( ! show.flag && ! show.name && ! show.code ) {
			show.name = true;
		}

		group.querySelectorAll( '.swiftling-switcher__link, .swiftling-switcher__toggle' ).forEach( ( label ) => {
			const flag = label.querySelector( '.swiftling-switcher__flag' );
			const hasFlag = show.flag && !! flag;
			if ( flag ) {
				flag.hidden = ! hasFlag;
			}
			label.querySelector( '.swiftling-switcher__name' ).hidden = ! show.name;
			label.querySelector( '.swiftling-switcher__code' ).hidden = ! ( show.code || ( ! hasFlag && ! show.name ) );
		} );

		group.querySelectorAll( '.swiftling-switcher__list > .is-current' ).forEach( ( item ) => {
			item.hidden = ! switches.current.checked;
		} );
		// The code beside the globe of a menu, where the switcher shows codes.
		group.querySelectorAll( '[data-swiftling-globe-code]' ).forEach( ( code ) => {
			code.hidden = ! switches.code.checked;
		} );

		// The size of flags, as Switcher::label() marks it: no class for medium.
		const size = panel.querySelector( '[data-swiftling-flag-size]:checked' );
		group.querySelectorAll( '.swiftling-switcher__flag' ).forEach( ( flag ) => {
			flag.classList.toggle( 'is-small', !! size && 'small' === size.value );
			flag.classList.toggle( 'is-large', !! size && 'large' === size.value );
		} );
		// Only flags: as narrow as a flag, as Switcher::render() marks it.
		group.querySelectorAll( '.swiftling-switcher' ).forEach( ( switcher ) => {
			switcher.classList.toggle( 'is-flag-only', show.flag && ! show.name && ! show.code );
		} );
	};

	const updateParts = () => panels.forEach( updatePlace );

	/* Tabs of step 2: one place at a time, in the form and in the preview. The form posts the tab,
	   so the screen opens on it again after saving. */
	const tabs = Array.from( document.querySelectorAll( '[data-swiftling-tab]' ) );
	const tabField = document.querySelector( '[data-swiftling-tab-field]' );
	const openTab = ( tab ) => {
		tabs.forEach( ( other ) => {
			const selected = other === tab;
			other.classList.toggle( 'is-current', selected );
			other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			other.tabIndex = selected ? 0 : -1;
			document.getElementById( other.getAttribute( 'aria-controls' ) ).hidden = ! selected;
			const group = preview.querySelector( '[data-swiftling-preview-place="' + other.dataset.swiftlingTab + '"]' );
			if ( group ) {
				group.hidden = ! selected;
			}
		} );
		if ( tabField ) {
			tabField.value = tab.dataset.swiftlingTab;
		}
	};
	tabs.forEach( ( tab, index ) => {
		tab.addEventListener( 'click', () => openTab( tab ) );
		tab.addEventListener( 'keydown', ( event ) => {
			const step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ];
			if ( ! step ) {
				return;
			}
			event.preventDefault();
			const next = tabs[ ( index + step + tabs.length ) % tabs.length ];
			openTab( next );
			next.focus();
		} );
	} );

	/* Flags: the choice is in flag-choice.js, which says when one changes. */
	document.addEventListener( 'swiftling:flag', ( event ) => {
		const box = event.target.closest( '[data-swiftling-flag]' );
		const url = event.detail.url;
		preview.querySelectorAll( '[data-locale="' + box.dataset.swiftlingFlag + '"]' ).forEach( ( label ) => {
			let image = label.querySelector( '.swiftling-switcher__flag' );
			if ( ! url ) {
				if ( image ) {
					image.remove();
				}
				return;
			}
			if ( ! image ) {
				image = document.createElement( 'img' );
				image.className = 'swiftling-switcher__flag';
				image.alt = '';
				image.width = 18;
				image.height = 12;
				label.prepend( image );
			}
			image.src = url;
		} );
		updateParts();
	} );

	panels.forEach( ( panel ) => {
		panel.querySelectorAll( '[data-swiftling-show], [data-swiftling-flag-size]' ).forEach( ( input ) => input.addEventListener( 'change', () => updatePlace( panel ) ) );
	} );
	updateParts();

	// The preview is for looking, not for leaving the screen.
	preview.addEventListener( 'click', ( event ) => {
		if ( event.target instanceof Element && event.target.closest( '.swiftling-switcher__link' ) ) {
			event.preventDefault();
		}
	} );
} )();
