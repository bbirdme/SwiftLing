/**
 * Menus screen, one menu's items by language: unticking an item in a language leaves its submenu
 * out there too, so the cells under it say so at once instead of offering a tick. Such a cell sends
 * nothing, and the item keeps its own choice for that language (swiftling_save_menu_items()).
 */
( function () {
	const form = document.querySelector( '[data-swiftling-menu-langs]' );
	if ( ! form ) {
		return;
	}
	const { __, sprintf } = wp.i18n;
	const rows = Array.from( form.querySelectorAll( 'tr[data-item]' ) );
	const byId = new Map( rows.map( ( row ) => [ row.dataset.item, row ] ) );
	const unsaved = form.querySelector( '.swiftling-menu-langs__unsaved' );

	const cellOf = ( row, locale ) => Array.from( row.querySelectorAll( 'td[data-locale]' ) ).find( ( cell ) => cell.dataset.locale === locale );

	// Whether an item shows in a language by its own choice: its tick, or the saved choice of a cell
	// that has none.
	const own = ( row, locale ) => {
		const cell = cellOf( row, locale );
		const box = cell.querySelector( 'input[type="checkbox"]' );
		return box ? box.checked : '1' === cell.dataset.own;
	};

	const above = ( row, locale ) => {
		for ( let at = byId.get( row.dataset.parent ); at; at = byId.get( at.dataset.parent ) ) {
			if ( ! own( at, locale ) ) {
				return at;
			}
		}
		return null;
	};

	const tick = ( row, cell ) => {
		const label = document.createElement( 'label' );
		label.className = 'swiftling-menu-langs__tick';
		const box = document.createElement( 'input' );
		box.type = 'checkbox';
		box.name = 'shown[' + row.dataset.item + '][]';
		box.value = cell.dataset.locale;
		box.checked = '1' === cell.dataset.own;
		const name = document.createElement( 'span' );
		name.className = 'screen-reader-text';
		/* translators: 1: name of a menu item, 2: language name. */
		name.textContent = sprintf( __( 'Show %1$s in %2$s', 'swiftling' ), row.dataset.name, cell.dataset.language );
		label.append( box, name );
		cell.prepend( label );
		return label;
	};

	const update = ( locale ) => {
		rows.forEach( ( row ) => {
			const cell = cellOf( row, locale );
			const parent = above( row, locale );
			let lock = cell.querySelector( '.swiftling-menu-langs__locked' );
			let label = cell.querySelector( '.swiftling-menu-langs__tick' );
			if ( parent ) {
				if ( label ) {
					label.hidden = true;
					label.querySelector( 'input' ).disabled = true;
				}
				if ( ! lock ) {
					lock = document.createElement( 'span' );
					lock.className = 'swiftling-menu-langs__locked';
					cell.prepend( lock );
				}
				/* translators: %s: name of the menu item above, e.g. Blog. */
				lock.textContent = sprintf( __( 'with %s', 'swiftling' ), parent.dataset.name );
				return;
			}
			if ( lock ) {
				lock.remove();
			}
			if ( ! label ) {
				label = tick( row, cell );
			}
			label.hidden = false;
			label.querySelector( 'input' ).disabled = false;
		} );
	};

	form.addEventListener( 'change', ( event ) => {
		const cell = event.target.closest( 'td[data-locale]' );
		if ( ! cell ) {
			return;
		}
		update( cell.dataset.locale );
		if ( unsaved ) {
			unsaved.hidden = false;
		}
	} );
} )();
