/**
 * Log screen.
 *
 * - The confirmation of clearing the log opens as a dialog. Without this script, the button opens
 *   the screen with the confirmation shown in place.
 * - Copy puts the report for support on the clipboard; where the browser refuses, the report is
 *   selected, to copy by hand.
 * - The report is as tall as its text, long lines wrapped included, so it reads without scrolling;
 *   without this script it has a row for every line.
 */
( function () {
	const { __ } = window.wp.i18n;

	const dialog = document.getElementById( 'swiftling-clear-log-dialog' );
	if ( dialog && 'function' === typeof dialog.showModal ) {
		document.querySelectorAll( 'a[href*="swiftling_confirm=1"]' ).forEach( ( opener ) => {
			opener.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				dialog.showModal();
			} );
		} );
		dialog.querySelector( '[data-swiftling-close]' ).addEventListener( 'click', ( event ) => {
			event.preventDefault();
			dialog.close();
		} );
	}

	const button = document.getElementById( 'swiftling-support-copy' );
	const text = document.getElementById( 'swiftling-support-text' );
	const status = document.getElementById( 'swiftling-support-copied' );
	if ( ! button || ! text ) {
		return;
	}
	const fit = () => {
		text.style.height = 'auto';
		text.style.height = text.scrollHeight + 'px';
	};
	fit();
	text.addEventListener( 'input', fit );
	window.addEventListener( 'resize', fit );

	const selectAll = () => {
		text.focus();
		text.select();
	};
	button.addEventListener( 'click', () => {
		if ( ! navigator.clipboard ) {
			selectAll();
			status.textContent = __( 'Selected. Copy it with Ctrl+C or Cmd+C.', 'swiftling' );
			return;
		}
		navigator.clipboard.writeText( text.value ).then(
			() => {
				status.textContent = __( 'Copied.', 'swiftling' );
			},
			() => {
				selectAll();
				status.textContent = __( 'Selected. Copy it with Ctrl+C or Cmd+C.', 'swiftling' );
			}
		);
	} );
} )();
