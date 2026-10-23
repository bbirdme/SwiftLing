/**
 * Translation editor.
 *
 * The page is checked in this browser. It opens in two hidden frames, once with a marker on every
 * value that looks like text and once as visitors see it. The script reports where each marker
 * ended up, and the server answers with the texts to translate.
 */
( function () {
	const configElement = document.getElementById( 'swiftling-editor-config' );
	if ( ! configElement ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const config = JSON.parse( configElement.textContent );
	const strings = config.strings;
	const status = document.getElementById( 'swiftling-live-status' );
	const noteBox = document.getElementById( 'swiftling-live-note' );
	const table = document.getElementById( 'swiftling-live-table' );
	const saveButton = document.getElementById( 'swiftling-live-save' );
	const extrasToggle = document.getElementById( 'swiftling-live-extras' );
	const extrasCount = document.getElementById( 'swiftling-live-extras-count' );
	const onlyEmptyToggle = document.getElementById( 'swiftling-live-only-empty' );
	const emptyCount = document.getElementById( 'swiftling-live-empty-count' );
	const markEmptyButton = document.getElementById( 'swiftling-live-mark-empty' );
	const textsTab = document.getElementById( 'swiftling-live-view-texts' );
	const textsCount = document.getElementById( 'swiftling-live-texts-count' );
	const unsavedCount = document.getElementById( 'swiftling-live-unsaved' );
	const summary = document.getElementById( 'swiftling-live-summary' );
	const meter = document.getElementById( 'swiftling-live-meter' );
	const copyButton = document.getElementById( 'swiftling-live-copy' );
	const machineButton = document.getElementById( 'swiftling-live-machine' );
	const allDone = document.getElementById( 'swiftling-live-all-done' );
	const editorCard = table.closest( '.swiftling-live-editor' );
	// Opening and reading the page in hidden frames, shared with recording: assets/js/page-check.js.
	const pageCheck = window.swiftlingPageCheck;

	const dirty = new Set();

	/* Talking to the server. */

	const api = async ( route, body ) => {
		const response = await fetch( config.rest + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body ),
		} );
		if ( ! response.ok ) {
			// The server says what went wrong, e.g. that the translation service refused the key.
			let message = '';
			try {
				message = ( await response.json() ).message || '';
			} catch ( error ) {}
			const failure = new Error( message || 'Request failed with status ' + response.status );
			failure.serverMessage = message;
			throw failure;
		}
		return response.json();
	};

	const setState = ( element, state ) => {
		element.classList.remove( 'is-reviewed', 'is-machine', 'is-official', 'is-outdated', 'is-partial', 'is-missing' );
		element.classList.add( 'is-' + state );
	};

	const updateRows = ( states ) => {
		Object.entries( states ).forEach( ( [ key, state ] ) => {
			table.querySelectorAll( 'tr[data-key="' + key + '"]' ).forEach( ( row ) => {
				const icon = row.querySelector( '.swiftling-status' );
				// A link address has no state of its own: empty means the original address.
				if ( ! icon ) {
					return;
				}
				setState( row, state );
				setState( icon, state );
				icon.title = config.labels[ state ];
				icon.querySelector( '.screen-reader-text' ).textContent = config.labels[ state ];
			} );
		} );
	};

	const updateTabs = ( languages ) => {
		Object.entries( languages ).forEach( ( [ lang, state ] ) => {
			const icon = document.querySelector( '.swiftling-segmented a[data-lang="' + lang + '"] .swiftling-status' );
			if ( icon ) {
				setState( icon, state );
				icon.title = config.labels[ state ];
			}
		} );
	};

	/* Checking the page. */

	// Shows how far the check of the page has come: which step, how much of the bar, and the seconds
	// so far. Within a step the bar creeps towards the step's end, so a long step does not look stuck.
	const progress = ( () => {
		const box = document.getElementById( 'swiftling-live-progress' );
		const bar = box.querySelector( '[role="progressbar"]' );
		const fill = bar.querySelector( '.is-progress' );
		let timer = 0;
		let started = 0;
		let text = () => '';
		let from = 0;
		let to = 0;
		let since = 0;
		const draw = () => {
			const now = Date.now();
			const percent = Math.round( from + ( to - from ) * ( 1 - Math.pow( 0.5, ( now - since ) / 3000 ) ) );
			status.textContent = text( Math.round( ( now - started ) / 1000 ) );
			fill.style.width = percent + '%';
			bar.setAttribute( 'aria-valuenow', String( percent ) );
		};
		return {
			step( next, start, end ) {
				if ( ! timer ) {
					started = Date.now();
					box.hidden = false;
					timer = setInterval( draw, 250 );
				}
				text = next;
				from = start;
				to = end;
				since = Date.now();
				draw();
			},
			done() {
				clearInterval( timer );
				timer = 0;
				box.hidden = true;
				fill.style.width = '0%';
			},
			// Fills the bar and says the editor is ready, for a moment, so the user sees what just
			// finished, then hides it.
			finish( text ) {
				clearInterval( timer );
				timer = 0;
				fill.style.width = '100%';
				bar.setAttribute( 'aria-valuenow', '100' );
				status.textContent = text;
				setTimeout( () => {
					box.hidden = true;
					if ( status.textContent === text ) {
						status.textContent = '';
					}
				}, 2500 );
			},
		};
	} )();

	const check = async () => {
		let loaded = 0;
		const opened = () => progress.step( ( seconds ) => strings.stepOpen.replace( '%s', seconds ), loaded * 30, loaded * 30 + 28 );
		opened();
		const page = await pageCheck.check( config.probes, () => {
			++loaded;
			opened();
			if ( 2 === loaded ) {
				progress.step( ( seconds ) => strings.stepRead.replace( '%s', seconds ), 60, 70 );
			}
		} );
		// A list of terms asks for the page of terms it shows.
		const body = { subject: config.subject, lang: config.lang, ...( config.list || {} ), ...page };

		progress.step( ( seconds ) => strings.stepSave.replace( '%s', seconds ), 70, 98 );
		showRows( await api( 'probe', body ) );
		progress.finish( strings.ready );
	};

	// Puts the rows the server rendered into the table and makes the tools ready.
	const showRows = ( result ) => {
		table.querySelectorAll( 'tbody' ).forEach( ( tbody ) => tbody.remove() );
		table.insertAdjacentHTML( 'beforeend', result.rows );

		startRichEditors();
		// The tab stays in its place, also with nothing to show: then it says 0 and cannot be chosen.
		extrasCount.textContent = String( result.extras || 0 );
		extrasToggle.disabled = ! result.extras;
		markEmptyButton.disabled = false;
		copyButton.disabled = false;
		if ( machineButton && config.machine ) {
			machineButton.disabled = false;
		}
		showView();
		// Save stays available: with nothing to save it says so.
		saveButton.disabled = false;
		// Defined further down; the rows of a page come after the script ran to its end.
		if ( window.swiftlingEditor ) {
			editorReady();
		}
	};

	// A list of terms comes ready with the screen; a page or template is checked first.
	if ( config.rows ) {
		status.textContent = '';
		document.getElementById( 'swiftling-live-progress' ).hidden = true;
	} else {
		check().catch( ( error ) => {
			progress.done();
			status.textContent = strings.failed;
			status.classList.add( 'is-error' );
			// eslint-disable-next-line no-console
			console.error( 'SwiftLing:', error );
			// For the log, so the failure can be found later; what the browser said, in short.
			api( 'check-failed', { subject: config.subject, lang: config.lang, reason: String( error && error.message ? error.message : error ).slice( 0, 200 ) } ).catch( () => {} );
		} );
	}

	/* Translating. */

	// Says what the last save or tool did, under the Save button: info, success or error.
	const note = ( text, kind ) => {
		noteBox.textContent = text;
		noteBox.className = 'swiftling-live-note' + ( text ? ' is-' + kind : '' );
	};

	const markDirty = ( input ) => {
		// "Saved." no longer holds once something changes.
		if ( noteBox.classList.contains( 'is-success' ) ) {
			note( '' );
		}
		dirty.add( input );
		saveButton.disabled = false;
		refreshEmpty( input.closest( 'tr' ) );
		showUnsaved();
	};

	// How many fields changed since the last save, next to Save.
	const showUnsaved = () => {
		unsavedCount.textContent = dirty.size
			? sprintf(
					/* translators: %s: number of fields. */
					_n( '%s unsaved', '%s unsaved', dirty.size, 'swiftling' ),
					dirty.size
			  )
			: '';
	};

	// Text with simple formatting gets the WordPress visual editor, with a Code tab. The tags around
	// the text stay outside the editor and are put back when the translation is saved.
	const isRich = ( input ) => input.classList.contains( 'swiftling-live-rich' );
	const richEditor = ( input ) => window.tinymce && window.tinymce.get( input.id );

	const startRichEditor = ( input ) => {
		window.wp.editor.initialize( input.id, {
			tinymce: {
				wpautop: false,
				forced_root_block: '1' === input.dataset.blocks ? 'p' : false,
				toolbar1: 'bold,italic,link,unlink,superscript,subscript,removeformat,undo,redo',
				toolbar2: '',
				menubar: false,
				statusbar: false,
				// The editor grows with the text, as the WordPress post editor does.
				wp_autoresize_on: true,
				autoresize_min_height: 40,
				autoresize_max_height: 600,
				content_style: 'body { margin: 8px 10px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: 14px; line-height: 1.5; } p { margin: 0 0 8px; }',
				setup: ( editor ) => editor.on( 'input change keyup undo redo', () => markDirty( input ) ),
			},
			quicktags: { buttons: 'strong,em,link,code,close' },
			mediaButtons: false,
		} );
	};

	// Starting hundreds of visual editors at once, as an options page with a long list of formatted
	// fields has, held the page up for minutes. With many, each starts once its row comes near the
	// screen; until then its field is the plain text area underneath, which saving and the tools
	// read and fill as well.
	const RICH_AT_ONCE = 30;
	const startRichEditors = () => {
		if ( ! window.wp || ! window.wp.editor || ! window.wp.editor.initialize ) {
			return;
		}
		const inputs = [ ...table.querySelectorAll( 'textarea.swiftling-live-rich:not([disabled])' ) ];
		if ( inputs.length <= RICH_AT_ONCE || ! window.IntersectionObserver ) {
			inputs.forEach( startRichEditor );
			return;
		}
		const observer = new window.IntersectionObserver(
			( entries ) => entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					observer.unobserve( entry.target );
					startRichEditor( entry.target );
				}
			} ),
			{ rootMargin: '800px 0px' }
		);
		inputs.forEach( ( input ) => observer.observe( input ) );
	};

	// The text inside the editable part, as HTML.
	const richContent = ( input ) => ( window.wp && window.wp.editor && window.wp.editor.getContent ? window.wp.editor.getContent( input.id ) : input.value ) || '';

	const setRichContent = ( input, html ) => {
		const editor = richEditor( input );
		input.value = html;
		if ( editor && ! editor.isHidden() ) {
			editor.setContent( html );
		}
	};

	// Plain text typed between the tags of a heading or a paragraph, as HTML.
	const escapeText = ( text ) => text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

	// The translation as it is saved: for formatted text and for text inside tags, with the tags
	// around it put back.
	const translationOf = ( input ) => {
		if ( input.classList.contains( 'swiftling-live-wrapped' ) ) {
			return '' === input.value.trim() ? '' : input.dataset.prefix + escapeText( input.value ) + input.dataset.suffix;
		}
		if ( ! isRich( input ) ) {
			return input.value;
		}
		const middle = richContent( input );
		return '' === middle.replace( /<[^>]*>|&nbsp;|\s/g, '' ) ? '' : input.dataset.prefix + middle + input.dataset.suffix;
	};

	/* Placeholders of texts from code. */

	// A placeholder of PHP's sprintf(), or "%%". A space as a flag is left out on purpose, as on the
	// server: see swiftling_format_placeholders() in includes/sources.php, which this mirrors.
	const PLACEHOLDER = /%(?:%|(?:(\d+)\$)?((?:[-+0]|'.)*)(\d*)(?:\.(\d+))?([bcdeEfFgGhHosuxX]))/g;

	// The placeholders of a text, each with the value it takes; null when a "%" is no placeholder.
	const placeholdersOf = ( text ) => {
		if ( ! text.includes( '%' ) ) {
			return [];
		}
		const found = [];
		let next = 0;
		const rest = text.replace( PLACEHOLDER, ( whole, number, flags, width, precision, type ) => {
			if ( '%%' !== whole ) {
				found.push( { raw: whole, position: number ? Number( number ) : ++next, type } );
			}
			return '';
		} );
		return rest.includes( '%' ) ? null : found;
	};

	// Whether a translation would stop the page, and which placeholders it leaves out, as
	// swiftling_format_problems() decides on the server.
	const formatProblems = ( original, translation ) => {
		const source = placeholdersOf( original );
		if ( ! source || ! source.length ) {
			return { breaks: false, missing: [] };
		}
		const types = new Map( source.map( ( placeholder ) => [ placeholder.position, placeholder.type ] ) );
		const target = placeholdersOf( translation );
		let breaks = null === target;
		const used = new Set();
		( target || [] ).forEach( ( placeholder ) => {
			if ( types.get( placeholder.position ) !== placeholder.type ) {
				breaks = true;
			}
			used.add( placeholder.position );
		} );
		const missing = [ ...new Set( source.filter( ( placeholder ) => ! used.has( placeholder.position ) ).map( ( placeholder ) => placeholder.raw ) ) ];
		return { breaks, missing };
	};

	// Says under a field of a text from code whether its translation keeps the placeholders.
	// Returns true when the translation would stop the page.
	const checkFormat = ( input ) => {
		if ( ! input.dataset.format ) {
			return false;
		}
		const noteElement = document.getElementById( input.getAttribute( 'aria-describedby' ) );
		const text = translationOf( input );
		const problems = '' === text.trim() ? { breaks: false, missing: [] } : formatProblems( input.dataset.original, text );
		if ( noteElement ) {
			noteElement.dataset.keep = noteElement.dataset.keep || noteElement.textContent.trim();
			if ( problems.breaks ) {
				noteElement.textContent = sprintf(
					/* translators: %s: placeholders, e.g. %1$s %2$d. */
					_n(
						'This would break the page in this language: keep %s exactly as it is, and add no other %% sign. It may move to another place in the sentence.',
						'This would break the page in this language: keep %s exactly as they are, and add no other %% sign. They may move to another place in the sentence.',
						input.dataset.format.split( ' ' ).length,
						'swiftling'
					),
					input.dataset.format
				);
			} else if ( problems.missing.length ) {
				noteElement.textContent = sprintf(
					/* translators: %s: placeholders, e.g. %2$s. */
					_n(
						'Left out: %s. The page shows without that value here; add it where it belongs in the sentence.',
						'Left out: %s. The page shows without those values here; add them where they belong in the sentence.',
						problems.missing.length,
						'swiftling'
					),
					problems.missing.join( ' ' )
				);
			} else {
				noteElement.textContent = noteElement.dataset.keep;
			}
			noteElement.classList.toggle( 'is-error', problems.breaks );
			noteElement.classList.toggle( 'is-warning', ! problems.breaks && problems.missing.length > 0 );
		}
		input.closest( 'tr' ).classList.toggle( 'is-format-error', problems.breaks );
		return problems.breaks;
	};

	table.addEventListener( 'input', ( event ) => {
		const input = event.target.closest( '.swiftling-live-input' );
		if ( ! input ) {
			return;
		}
		// Each place has a translation of its own, also where the same words show twice.
		markDirty( input );
		checkFormat( input );
	} );

	/* The address of the page in the language. */

	const addressForm = document.getElementById( 'swiftling-address-form' );
	const viewIn = document.getElementById( 'swiftling-view-in' );

	// Shows the address the server answered with: the slug, how it was made and where the page opens.
	const showAddress = ( address ) => {
		if ( ! addressForm || ! address ) {
			return;
		}
		const decode = ( text ) => {
			try {
				return decodeURIComponent( text );
			} catch ( error ) {
				return text;
			}
		};
		document.getElementById( 'swiftling-address-slug' ).value = decode( address.slug );
		document.getElementById( 'swiftling-address-prefix' ).textContent = address.prefix;
		document.getElementById( 'swiftling-address-note' ).textContent = 'machine' === address.state ? strings.slugMade : strings.slugHint;
		const link = document.getElementById( 'swiftling-address-url' );
		link.hidden = ! address.url;
		if ( address.url ) {
			link.href = address.url;
			link.textContent = decode( address.url );
			if ( viewIn ) {
				viewIn.href = address.url;
			}
		}
	};

	if ( addressForm ) {
		addressForm.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			const button = addressForm.querySelector( 'button[type="submit"]' );
			button.disabled = true;
			try {
				showAddress(
					await api( 'slug', {
						subject: config.subject,
						lang: config.lang,
						slug: document.getElementById( 'swiftling-address-slug' ).value,
					} )
				);
				note( strings.slugSaved, 'success' );
			} catch ( error ) {
				note( error.serverMessage || strings.slugFailed, 'error' );
			} finally {
				button.disabled = false;
			}
		} );
	}

	// The address of each term in the editor of a list of terms, in a row under the term's texts.
	// The rows come and go with the page check, so the table listens for them.
	table.addEventListener( 'submit', async ( event ) => {
		const form = event.target.closest( '.swiftling-term-address' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();
		const button = form.querySelector( 'button[type="submit"]' );
		const input = form.querySelector( 'input' );
		button.disabled = true;
		try {
			const address = await api( 'slug', {
				subject: config.subject,
				lang: config.lang,
				term: Number( form.dataset.term ),
				slug: input.value,
			} );
			try {
				input.value = decodeURIComponent( address.slug );
			} catch ( error ) {
				input.value = address.slug;
			}
			form.querySelector( '.swiftling-term-address__prefix' ).textContent = address.prefix;
			note( strings.slugSaved, 'success' );
		} catch ( error ) {
			note( error.serverMessage || strings.slugFailed, 'error' );
		} finally {
			button.disabled = false;
		}
	} );

	// Says that some translations were not saved because they would stop the page.
	const refusedNote = ( count ) =>
		sprintf(
			/* translators: %s: number of fields. */
			_n(
				'%s translation was not saved: its placeholders do not fit the original, which would break the page. The field says what to fix.',
				'%s translations were not saved: their placeholders do not fit the original, which would break the page. The fields say what to fix.',
				count,
				'swiftling'
			),
			count
		);

	const save = async ( inputs, state ) => {
		// A translation that would stop the page is not saved; its field says why.
		const broken = inputs.filter( ( input ) => checkFormat( input ) );
		const items = new Map();
		inputs
			.filter( ( input ) => ! broken.includes( input ) )
			.forEach( ( input ) => items.set( input.dataset.key, { text: translationOf( input ), original: input.dataset.original || '' } ) );
		if ( ! items.size ) {
			note( broken.length ? refusedNote( broken.length ) : strings.nothing, broken.length ? 'error' : 'info' );
			return;
		}

		// A spinner in the button shows the save; nothing else on the page moves.
		saveButton.disabled = true;
		saveButton.classList.add( 'is-busy' );
		saveButton.setAttribute( 'aria-busy', 'true' );
		note( strings.saving, 'info' );
		try {
			const result = await api( 'translations', {
				subject: config.subject,
				lang: config.lang,
				status: state,
				items: [ ...items ].map( ( [ key, item ] ) => ( { key, text: item.text, original: item.original } ) ),
			} );
			updateRows( result.states );
			updateTabs( result.languages );
			// Saving the title's first translation can give the page its address in the language.
			showAddress( result.address );
			// The server checks the placeholders again, and refuses what would stop the page.
			const refused = new Set( result.refused || [] );
			inputs.forEach( ( input ) => {
				if ( refused.has( input.dataset.key ) ) {
					broken.push( input );
				} else if ( ! broken.includes( input ) ) {
					dirty.delete( input );
				}
			} );
			showUnsaved();
			refreshEmpty();
			if ( broken.length ) {
				note( refusedNote( broken.length ), 'error' );
			} else {
				note( strings.saved, 'success' );
			}
		} catch ( error ) {
			note( strings.saveFailed, 'error' );
		} finally {
			saveButton.disabled = false;
			saveButton.classList.remove( 'is-busy' );
			saveButton.removeAttribute( 'aria-busy' );
		}
	};

	// Saves what was typed and marks every translation on the page as reviewed.
	saveButton.addEventListener( 'click', () => {
		const inputs = [ ...table.querySelectorAll( '.swiftling-live-input:not([disabled])' ) ].filter( ( input ) => dirty.has( input ) || input.closest( 'tr.is-machine' ) );
		save( inputs, 'reviewed' );
	} );

	/* Empty fields. */

	// The text fields of a row the user may translate. An address is never empty: empty means the
	// original address.
	const textInputs = ( row ) => [ ...row.querySelectorAll( '.swiftling-live-input:not(.is-link):not([disabled])' ) ];

	// Marks the rows whose translation is still empty, all of them or one that just changed, and
	// counts them all: each one keeps the page from counting as translated, also code that shows
	// only on request.
	// A row that falls back to another text while it is empty, such as a menu's label of a page's
	// title, is not empty while that text is translated: visitors read it.
	const refreshEmpty = ( changed ) => {
		const rows = changed ? [ changed ] : table.querySelectorAll( 'tr[data-key]' );
		rows.forEach( ( row ) => {
			row.classList.toggle( 'is-empty', ! row.dataset.fallback && textInputs( row ).some( ( input ) => '' === translationOf( input ).trim() ) );
		} );
		const empty = [ ...table.querySelectorAll( 'tr.is-empty' ) ];
		emptyCount.textContent = String( empty.length );
		showProgress();
		return empty;
	};

	// How many texts are translated, as the fields are now: above the table, and in the tab of the
	// language. Machine translations not reviewed yet show as their own part of the bar.
	const showProgress = () => {
		const rows = [ ...table.querySelectorAll( 'tr[data-key]' ) ].filter( ( row ) => textInputs( row ).length );
		const total = rows.length;
		const empty = rows.filter( ( row ) => row.classList.contains( 'is-empty' ) ).length;
		textsCount.textContent = String( rows.filter( ( row ) => ! row.classList.contains( 'is-extra' ) ).length );
		const machine = rows.filter( ( row ) => ! row.classList.contains( 'is-empty' ) && row.classList.contains( 'is-machine' ) ).length;
		const done = total - empty;
		const share = ( part ) => ( total ? ( 100 * part ) / total : 0 ) + '%';

		summary.textContent = total
			? sprintf(
					/* translators: 1: texts translated, 2: all texts. */
					_n( '%1$s of %2$s text translated', '%1$s of %2$s texts translated', total, 'swiftling' ),
					done,
					total
			  ) +
			  ( empty
					? ' · ' +
					  sprintf(
							/* translators: %s: number of texts. */
							_n( '%s still empty', '%s still empty', empty, 'swiftling' ),
							empty
					  )
					: '' )
			: '';
		meter.querySelector( '.is-reviewed' ).style.width = share( done - machine );
		meter.querySelector( '.is-machine' ).style.width = share( machine );

		const tab = document.querySelector( '.swiftling-segmented a[data-lang="' + config.lang + '"]' );
		// A list shown one page at a time counts its whole list in the tab, as the server does.
		if ( ! tab || ! total || config.paged ) {
			return;
		}
		let box = tab.querySelector( '.swiftling-segmented__progress' );
		if ( ! box ) {
			// A page checked for the first time has no count in its tab yet.
			box = document.createElement( 'span' );
			box.className = 'swiftling-segmented__progress';
			box.innerHTML = '<span class="swiftling-segmented__count"></span><span class="swiftling-bar swiftling-segmented__meter" aria-hidden="true"><span class="is-reviewed"></span></span>';
			tab.appendChild( box );
		}
		box.querySelector( '.swiftling-segmented__count' ).textContent = done + '/' + total;
		box.querySelector( '.is-reviewed' ).style.width = share( done );
	};

	// Shows only the rows that are empty at this moment, code that shows only on request among them.
	// A row filled in afterwards stays, so it does not vanish under the cursor. A group without such
	// rows hides with its heading, and when no row is empty the editor says so.
	const filterEmpty = () => {
		const on = onlyEmptyToggle.checked;
		const empty = refreshEmpty();
		table.querySelectorAll( 'tr[data-key]' ).forEach( ( row ) => row.classList.toggle( 'is-filter-match', row.classList.contains( 'is-empty' ) ) );
		table.querySelectorAll( 'tbody.swiftling-live-group' ).forEach( ( group ) => {
			group.classList.toggle( 'has-no-match', ! empty.some( ( row ) => group.contains( row ) ) );
		} );
		editorCard.classList.toggle( 'is-only-empty', on );
		allDone.hidden = ! on || empty.length > 0;
	};

	// Draws a border around every empty row and goes to the first, until the button is pressed again.
	markEmptyButton.addEventListener( 'click', () => {
		const on = 'true' !== markEmptyButton.getAttribute( 'aria-pressed' );
		markEmptyButton.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		editorCard.classList.toggle( 'is-marking-empty', on );
		const first = refreshEmpty().find( ( row ) => row.offsetParent );
		if ( on && first ) {
			first.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	} );

	// Puts the original into every empty field, as a start for the translation: also code that shows
	// only on request, which mostly stays as it is. Nothing is saved until the user saves. A row that
	// falls back to another text is left alone: filled, it would no longer follow that text.
	copyButton.addEventListener( 'click', () => {
		let copied = 0;
		refreshEmpty()
			.filter( ( row ) => ! row.dataset.follows )
			.forEach( ( row ) => {
				textInputs( row ).forEach( ( input ) => {
					if ( '' !== translationOf( input ).trim() ) {
						return;
					}
					if ( isRich( input ) ) {
						setRichContent( input, input.dataset.original );
					} else {
						input.value = input.dataset.original;
					}
					markDirty( input );
					++copied;
				} );
			} );
		const copiedText = sprintf(
			/* translators: %s: number of fields. */
			_n( 'The original is in %s empty field now. Change what needs changing, then save.', 'The original is in %s empty fields now. Change what needs changing, then save.', copied, 'swiftling' ),
			copied
		);
		note( copied ? copiedText : strings.noEmpty, 'info' );
	} );

	/* Machine translation. */

	// The fields machine translation fills: the empty text fields the user may translate. Code keeps
	// the form it is stored in and an address its original, so neither is sent, and a row that falls
	// back to another text keeps following it.
	const machineInputs = () =>
		[ ...table.querySelectorAll( 'tr[data-key]:not([data-follows]) .swiftling-live-input:not(.is-link):not(.swiftling-code):not([disabled])' ) ].filter(
			( input ) => '' === translationOf( input ).trim() && '' !== ( input.dataset.original || '' ).trim()
		);

	// Requests of at most 50 texts and 4,000 characters, as the Translate everything screen sends
	// them too, see assets/js/machine.js.
	const machineBatches = ( inputs ) => window.swiftlingMachine.batches( inputs, ( input ) => input.dataset.original.length );

	const machineBusy = ( busy ) => {
		machineButton.disabled = busy;
		machineButton.classList.toggle( 'is-busy', busy );
		copyButton.disabled = busy;
		if ( busy ) {
			machineButton.setAttribute( 'aria-busy', 'true' );
		} else {
			machineButton.removeAttribute( 'aria-busy' );
		}
	};

	// The text fields translated in the states asked for, as SwiftLing Pro translates them again:
	// machine, reviewed. Never one from WordPress, a theme or a plugin, one whose original changed,
	// nor one with changes not saved yet.
	const againInputs = ( states ) =>
		[ ...table.querySelectorAll( 'tr[data-key]:not([data-follows]) .swiftling-live-input:not(.is-link):not(.swiftling-code):not([disabled])' ) ].filter( ( input ) => {
			const row = input.closest( 'tr' );
			return (
				states.some( ( state ) => [ 'machine', 'reviewed' ].includes( state ) && row.classList.contains( 'is-' + state ) ) &&
				! dirty.has( input ) &&
				'' !== translationOf( input ).trim() &&
				'' !== ( input.dataset.original || '' ).trim()
			);
		} );

	// Translates text fields with the service and saves each request's translations at once as
	// machine translations, so what the service translated, and charged for, is kept even when a
	// later request fails. Saving them again with the Save button marks them reviewed. By default the
	// empty fields; with again, the fields given, whose translation is replaced unless it was changed
	// meanwhile. Answers with what it did.
	const machineTranslate = async ( given = null, again = false ) => {
		const inputs = given || machineInputs();
		if ( ! inputs.length ) {
			note( again ? strings.noAgain : strings.noMachine, 'info' );
			return { translated: 0, failed: 0, stopped: '' };
		}

		const service = config.machine.service;
		let done = 0;
		let translated = 0;
		let failed = 0;
		let stopped = '';
		machineBusy( true );
		try {
			for ( const batch of machineBatches( inputs ) ) {
				note(
					sprintf(
						/* translators: 1: translation service name, e.g. Google, 2: fields done, 3: fields in all. */
						_n( 'Translating with %1$s: %2$s of %3$s field…', 'Translating with %1$s: %2$s of %3$s fields…', inputs.length, 'swiftling' ),
						service,
						done,
						inputs.length
					),
					'info'
				);
				const result = await api( 'machine', {
					subject: config.subject,
					lang: config.lang,
					items: batch.map( ( input ) => ( { key: input.dataset.key, text: input.dataset.original, html: isRich( input ) } ) ),
				} );
				done += batch.length;

				const filled = [];
				// Says under a field which words of the list on the Translation service screen it kept.
				const keptNote = ( input, words ) => {
					const cell = input.closest( 'td' );
					let note = cell ? cell.querySelector( '.swiftling-kept-note' ) : null;
					if ( ! cell || ! Array.isArray( words ) || ! words.length ) {
						return;
					}
					if ( ! note ) {
						note = document.createElement( 'p' );
						note.className = 'swiftling-kept-note';
						cell.append( note );
					}
					/* translators: %s: words kept as written, e.g. Lavanda, Split. */
					note.textContent = sprintf( __( 'Kept as written: %s', 'swiftling' ), words.join( ', ' ) );
				};
				batch.forEach( ( input ) => {
					const text = result.translations[ input.dataset.key ];
					// A field typed into meanwhile keeps what was typed.
					if ( 'string' !== typeof text || ( again ? dirty.has( input ) : '' !== translationOf( input ).trim() ) ) {
						failed += 'string' === typeof text ? 0 : 1;
						return;
					}
					if ( isRich( input ) ) {
						setRichContent( input, text );
					} else {
						input.value = text;
					}
					markDirty( input );
					filled.push( input );
					keptNote( input, result.kept ? result.kept[ input.dataset.key ] : null );
				} );
				if ( filled.length ) {
					await save( filled, 'machine' );
					// A failed save leaves the fields unsaved; translating more would only add to that.
					if ( filled.some( ( input ) => dirty.has( input ) ) ) {
						stopped = strings.saveFailed;
						break;
					}
					translated += filled.length;
				}
				if ( result.error ) {
					stopped = result.error;
					break;
				}
			}
		} catch ( error ) {
			stopped = error.serverMessage || strings.machineOff;
			// eslint-disable-next-line no-console
			console.error( 'SwiftLing:', error );
		} finally {
			machineBusy( false );
		}

		const parts = [];
		if ( translated ) {
			parts.push(
				sprintf(
					/* translators: 1: number of fields, 2: translation service name, e.g. Google. */
					_n(
						'%1$s field translated with %2$s and saved as a machine translation. Read it, then save to mark it reviewed.',
						'%1$s fields translated with %2$s and saved as machine translations. Read them, then save to mark them reviewed.',
						translated,
						'swiftling'
					),
					translated,
					service
				)
			);
		}
		if ( failed ) {
			/* translators: %s: number of fields. */
			parts.push( sprintf( _n( '%s field could not be translated and stays empty.', '%s fields could not be translated and stay empty.', failed, 'swiftling' ), failed ) );
		}
		if ( stopped ) {
			parts.push( stopped );
		}
		note( parts.join( ' ' ), stopped ? 'error' : 'success' );
		refreshEmpty();
		return { translated, failed, stopped };
	};

	// What another plugin, as SwiftLing Pro, works with in the editor; see docs/hooks.md.
	// texts( states ) lists the fields translated in those states, each with its state and the
	// characters of its original; translateAgain( states ) translates them again with the service in
	// use and saves them as machine translations, answering with what it did. Both only once the
	// rows are there: ready says so, and the event swiftling:editor-ready on document tells it.
	window.swiftlingEditor = {
		ready: false,
		service: config.machine ? config.machine.service : '',
		texts: ( states ) =>
			againInputs( states ).map( ( input ) => ( {
				key: input.dataset.key,
				state: [ 'machine', 'reviewed' ].find( ( state ) => input.closest( 'tr' ).classList.contains( 'is-' + state ) ),
				characters: input.dataset.original.length,
			} ) ),
		translateAgain: async ( states ) => {
			if ( ! config.machine || machineButton.disabled ) {
				return { translated: 0, failed: 0, stopped: strings.machineOff };
			}
			return machineTranslate( againInputs( states ), true );
		},
		dirty: () => dirty.size,
	};
	const editorReady = () => {
		window.swiftlingEditor.ready = true;
		document.dispatchEvent( new CustomEvent( 'swiftling:editor-ready' ) );
	};

	if ( machineButton ) {
		machineButton.addEventListener( 'click', () => {
			if ( config.machine ) {
				machineTranslate();
			}
		} );
	}

	// Every comment of a list without a translation, on every page of the list: the server hands
	// them over in batches, the newest first, each translated and saved before the next, see
	// swiftling_comment_batch(). Once done, the editor opens again on what is left.
	const machineAllButton = document.getElementById( 'swiftling-live-machine-all' );
	const machineTranslateAll = async () => {
		// Typed translations would be lost when the editor opens again.
		if ( dirty.size ) {
			note( strings.leave, 'error' );
			return;
		}
		const service = config.machine.service;
		const total = config.comments.missing;
		let after = 0;
		let done = 0;
		let translated = 0;
		let stopped = '';
		machineBusy( true );
		machineAllButton.disabled = true;
		machineAllButton.classList.add( 'is-busy' );
		try {
			for ( ;; ) {
				note(
					sprintf(
						/* translators: 1: translation service name, e.g. Google, 2: comments done, 3: comments in all. */
						_n( 'Translating with %1$s: %2$s of %3$s comment…', 'Translating with %1$s: %2$s of %3$s comments…', total, 'swiftling' ),
						service,
						Math.min( done, total ),
						total
					),
					'info'
				);
				const batch = await api( 'comment-batch', { subject: config.subject, lang: config.lang, after, for: config.comments.for, find: config.comments.find } );
				if ( ! batch.items.length && ! batch.left ) {
					break;
				}
				after = batch.after;
				const result = await window.swiftlingMachine.translateItems( api, config.subject, config.lang, batch.items, () => {} );
				translated += result.texts;
				done = total - batch.left;
				if ( ! batch.left ) {
					break;
				}
			}
		} catch ( error ) {
			stopped = error.serverMessage || strings.machineOff;
			// eslint-disable-next-line no-console
			console.error( 'SwiftLing:', error );
		}
		if ( ! stopped ) {
			const url = new URL( window.location.href );
			url.searchParams.set( 'translated', String( translated ) );
			url.searchParams.delete( 'paged' );
			window.location.assign( url.toString() );
			return;
		}
		machineBusy( false );
		machineAllButton.disabled = false;
		machineAllButton.classList.remove( 'is-busy' );
		note(
			sprintf(
				/* translators: 1: number of comments, 2: translation service name, e.g. Google. */
				_n( '%1$s comment translated with %2$s before it stopped.', '%1$s comments translated with %2$s before it stopped.', translated, 'swiftling' ),
				translated,
				service
			) +
				' ' +
				stopped,
			'error'
		);
	};
	if ( machineAllButton && config.machine && config.comments ) {
		machineAllButton.disabled = false;
		machineAllButton.addEventListener( 'click', machineTranslateAll );
	}

	/* Images, videos, links and code. */

	// Hidden until the user chooses their tab.
	const showExtras = ( show ) => {
		editorCard.classList.toggle( 'is-showing-extras', show );
		// A visual editor started in a hidden row measures its height again once it shows.
		if ( show && window.tinymce ) {
			window.tinymce.editors.forEach( ( editor ) => {
				try {
					editor.execCommand( 'wpAutoResize' );
				} catch ( error ) {}
			} );
		}
	};
	// One view at a time: the texts, only the empty fields, or only images, links and code. The
	// editor always opens on the texts.
	const showView = () => {
		const extras = extrasToggle.checked;
		showExtras( extras );
		editorCard.classList.toggle( 'is-only-extras', extras );
		table.querySelectorAll( 'tbody.swiftling-live-group' ).forEach( ( group ) => {
			group.classList.toggle( 'has-no-extras', ! group.querySelector( 'tr.is-extra, tr.is-code, .swiftling-live-link' ) );
		} );
		filterEmpty();
	};
	[ textsTab, onlyEmptyToggle, extrasToggle ].forEach( ( tab ) => tab.addEventListener( 'change', showView ) );

	// The image a language shows follows its address as it is typed or chosen; with the field empty,
	// the original's shows, faded.
	table.addEventListener( 'input', ( event ) => {
		const input = event.target.closest( 'input.is-link' );
		const preview = input && table.querySelector( '.swiftling-live-chosen[data-for="' + input.id + '"]' );
		if ( preview ) {
			const address = input.value.trim();
			preview.src = address || input.dataset.original;
			preview.classList.toggle( 'is-same', '' === address );
		}
	} );

	// Another image from the media library: its address goes into the field, as if typed.
	table.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.swiftling-live-pick' );
		if ( ! button || ! window.wp || ! window.wp.media ) {
			return;
		}
		const input = document.getElementById( button.dataset.for );
		const frame = window.wp.media( {
			title: strings.pickImage,
			button: { text: strings.useImage },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first().toJSON();
			input.value = attachment.url;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		frame.open();
	} );

	window.addEventListener( 'beforeunload', ( event ) => {
		if ( dirty.size ) {
			event.preventDefault();
			event.returnValue = strings.leave;
		}
	} );

	// Once everything above is ready, and the scripts of the visual editor, which WordPress prints
	// after this one: rows that come with the screen started their visual editors before those
	// scripts were there, and showed formatted text in a plain field.
	if ( config.rows ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', () => showRows( config.rows ) );
		} else {
			showRows( config.rows );
		}
	}
} )();
