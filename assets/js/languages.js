/**
 * Languages screen.
 *
 * - The order of the languages. A row moves by dragging its handle, or with the arrow keys while
 *   the handle has focus. Each row carries a hidden order[] field, so the order of the rows is what
 *   the form saves.
 * - Deleting what a language no longer on the site kept: its two warnings in one dialog, see the
 *   second part below.
 */
( function () {
	const table = document.querySelector( '[data-swiftling-order]' );
	if ( ! table ) {
		return;
	}
	const { __, sprintf } = window.wp.i18n;
	const body = table.tBodies[ 0 ];
	const status = document.querySelector( '[data-swiftling-order-status]' );
	let dragged = null;

	const announce = ( row ) => {
		const position = Array.prototype.indexOf.call( body.rows, row ) + 1;
		/* translators: 1: language name, 2: position, 3: number of languages. */
		status.textContent = sprintf( __( '%1$s is now %2$d of %3$d.', 'swiftling' ), row.dataset.swiftlingLanguage, position, body.rows.length );
	};

	body.addEventListener( 'keydown', ( event ) => {
		const handle = event.target.closest( '[data-swiftling-move]' );
		if ( ! handle || ( 'ArrowUp' !== event.key && 'ArrowDown' !== event.key ) ) {
			return;
		}
		event.preventDefault();
		const row = handle.closest( 'tr' );
		const sibling = 'ArrowUp' === event.key ? row.previousElementSibling : row.nextElementSibling;
		if ( ! sibling ) {
			return;
		}
		body.insertBefore( row, 'ArrowUp' === event.key ? sibling : sibling.nextElementSibling );
		handle.focus();
		announce( row );
	} );

	body.addEventListener( 'dragstart', ( event ) => {
		const handle = event.target.closest( '[data-swiftling-move]' );
		if ( ! handle ) {
			event.preventDefault();
			return;
		}
		dragged = handle.closest( 'tr' );
		dragged.classList.add( 'is-dragging' );
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setDragImage( dragged, 20, 20 );
	} );

	body.addEventListener( 'dragover', ( event ) => {
		const row = event.target.closest( 'tr' );
		if ( ! dragged || ! row || row === dragged ) {
			return;
		}
		event.preventDefault();
		const box = row.getBoundingClientRect();
		body.insertBefore( dragged, event.clientY > box.top + box.height / 2 ? row.nextElementSibling : row );
	} );

	body.addEventListener( 'dragend', () => {
		if ( dragged ) {
			dragged.classList.remove( 'is-dragging' );
			announce( dragged );
			dragged = null;
		}
	} );

	// Adding a language fetches translations of WordPress, the theme and the plugins from
	// wordpress.org before the page loads again. The button must not be disabled: a disabled
	// button is left out of the request, and the language would not be added.
	const form = table.closest( 'form' );
	const progress = document.querySelector( '[data-swiftling-progress]' );
	let adding = null;

	const finish = () => {
		if ( ! adding ) {
			return;
		}
		adding.button.classList.remove( 'is-busy' );
		adding.button.removeAttribute( 'aria-busy' );
		adding.row.remove();
		progress.textContent = '';
		adding = null;
	};

	if ( form && progress ) {
		form.addEventListener( 'submit', ( event ) => {
			if ( adding ) {
				// A second click while the first request runs.
				event.preventDefault();
				return;
			}
			const button = event.submitter;
			if ( ! button || ( 'add' !== button.name && 'add_custom' !== button.name && 'add_again' !== button.name ) ) {
				return;
			}
			const field = 'add' === button.name ? form.elements.new_language : form.elements[ 'custom[name]' ];
			let name = '';
			if ( 'add_again' === button.name ) {
				name = button.dataset.swiftlingName || '';
			} else if ( 'add' === button.name && field && field.selectedOptions.length ) {
				name = field.selectedOptions[ 0 ].textContent;
			} else if ( field ) {
				name = field.value;
			}
			name = name.trim();

			const row = body.insertRow();
			row.className = 'swiftling-languages__pending';
			const cell = row.insertCell();
			cell.colSpan = table.tHead.rows[ 0 ].cells.length;
			/* translators: %s: language name, e.g. Deutsch. */
			cell.textContent = '' !== name ? sprintf( __( 'Adding %s…', 'swiftling' ), name ) : __( 'Adding the language…', 'swiftling' );

			button.classList.add( 'is-busy' );
			button.setAttribute( 'aria-busy', 'true' );
			progress.textContent = __( 'Adding the language and fetching translations of WordPress, your theme and plugins. This can take a few seconds.', 'swiftling' );
			adding = { button, row };
		} );

		// Coming back with the browser's back button shows this page as it was left, spinner and all.
		window.addEventListener( 'pageshow', ( event ) => {
			if ( event.persisted ) {
				finish();
			}
		} );
	}
} )();

/*
 * Deleting what a language no longer on the site kept. Delete… opens the first warning as a dialog;
 * once its box is ticked, its Delete… shows the last warning in the same dialog, whose button
 * deletes. Without this script, each opens the screen with the warning shown in place, and the
 * server asks for both answers again, see includes/kept-languages.php.
 */
( function () {
	document.querySelectorAll( '[data-swiftling-forget]' ).forEach( ( opener ) => {
		const dialog = document.getElementById( 'swiftling-forget-' + opener.dataset.swiftlingForget );
		if ( ! dialog || 'function' !== typeof dialog.showModal ) {
			return;
		}
		const understood = dialog.querySelector( 'input[name="understood"]' );
		const show = ( step ) => {
			dialog.querySelectorAll( '[data-swiftling-step]' ).forEach( ( part ) => {
				part.hidden = part.dataset.swiftlingStep !== step;
			} );
			const first = dialog.querySelector( '[data-swiftling-step="' + step + '"] input, [data-swiftling-step="' + step + '"] [data-swiftling-back]' );
			if ( first ) {
				first.focus();
			}
		};

		opener.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			understood.checked = false;
			dialog.showModal();
			show( 'check' );
		} );
		dialog.querySelector( '[data-swiftling-continue]' ).addEventListener( 'click', ( event ) => {
			event.preventDefault();
			if ( ! understood.checked ) {
				understood.reportValidity();
				return;
			}
			show( 'last' );
		} );
		dialog.querySelector( '[data-swiftling-back]' ).addEventListener( 'click', ( event ) => {
			event.preventDefault();
			show( 'check' );
		} );
		dialog.querySelector( '[data-swiftling-close]' ).addEventListener( 'click', ( event ) => {
			event.preventDefault();
			dialog.close();
		} );
	} );
} )();
