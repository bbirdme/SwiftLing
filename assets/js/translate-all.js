/**
 * Translate everything: fills every empty text of the site with the machine translation service,
 * for one language in one go.
 *
 * The texts of a page are known only after a page check in the browser, so the run is a loop in
 * this tab: for each page it opens the page in hidden frames (assets/js/page-check.js), saves what
 * the check found with the probe route, which answers with the empty texts, sends those to the
 * machine route in batches (assets/js/machine.js) and saves what comes back as machine
 * translations. What it did is recorded after every page, so a run that stops can go on.
 */
( function () {
	const configElement = document.getElementById( 'swiftling-all-config' );
	if ( ! configElement ) {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	const config = JSON.parse( configElement.textContent );
	const pageCheck = window.swiftlingPageCheck;
	const machine = window.swiftlingMachine;

	const before = document.getElementById( 'swiftling-all-before' );
	const runningBox = document.getElementById( 'swiftling-all-running' );
	const finishedBox = document.getElementById( 'swiftling-all-finished' );
	const startButton = document.getElementById( 'swiftling-all-start' );
	const stopButton = document.getElementById( 'swiftling-all-stop' );
	const agree = document.getElementById( 'swiftling-all-agree' );
	const agreeText = document.getElementById( 'swiftling-all-agree-text' );
	const about = document.getElementById( 'swiftling-all-about' );
	const startNote = document.getElementById( 'swiftling-all-start-note' );
	const heading = document.getElementById( 'swiftling-all-step-what' );
	const counts = {
		pages: document.getElementById( 'swiftling-all-count-pages' ),
		texts: document.getElementById( 'swiftling-all-count-texts' ),
		characters: document.getElementById( 'swiftling-all-count-characters' ),
	};
	const bar = document.getElementById( 'swiftling-all-bar' );
	const pagesLine = document.getElementById( 'swiftling-all-pages' );
	const currentLine = document.getElementById( 'swiftling-all-current' );
	const rows = document.getElementById( 'swiftling-all-rows' );
	const counters = {
		texts: document.getElementById( 'swiftling-all-done-texts' ),
		characters: document.getElementById( 'swiftling-all-sent' ),
		skipped: document.getElementById( 'swiftling-all-skipped-count' ),
	};
	const skippedBox = document.getElementById( 'swiftling-all-skipped' );
	const skippedRows = document.getElementById( 'swiftling-all-skipped-rows' );
	const status = document.getElementById( 'swiftling-all-status' );

	let running = false;
	let stopping = false;

	/* Talking to the server. */

	const api = async ( route, body ) => {
		const response = await fetch( config.rest + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( body ),
		} );
		if ( ! response.ok ) {
			// The server says what went wrong, e.g. that the service refused the key.
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

	const number = ( value ) => new Intl.NumberFormat( document.documentElement.lang || undefined ).format( value );

	/* Before the run: the steps follow the language chosen. */

	const chosen = () => document.querySelector( 'input[name="swiftling-all-language"]:checked' );

	const showLanguage = () => {
		const input = chosen();
		if ( ! input ) {
			startButton.disabled = true;
			return;
		}
		const language = config.languages[ input.value ];
		/* translators: %s: language name, e.g. Deutsch. */
		heading.textContent = sprintf( __( 'What it will do in %s', 'swiftling' ), language.name );
		counts.pages.textContent = number( language.pages );
		counts.texts.textContent = number( language.texts );
		counts.characters.textContent = number( language.characters );
		about.textContent = language.estimated
			? __( 'Counted from what SwiftLing knows. Pages it has never checked are guessed, so these numbers are about right.', 'swiftling' )
			: __( 'Counted from the texts SwiftLing found on these pages.', 'swiftling' );
		agreeText.textContent = sprintf(
			/* translators: 1: number of characters, 2: translation service name, e.g. Google, 3: language name, e.g. Deutsch. */
			__( 'I know it sends about %1$s characters to %2$s, and that %3$s can be done this way only once.', 'swiftling' ),
			number( language.characters ),
			config.service,
			language.name
		);
		startButton.textContent =
			'running' === language.status
				? /* translators: %s: language name, e.g. Deutsch. */
				  sprintf( __( 'Continue translating %s', 'swiftling' ), language.name )
				: /* translators: %s: language name, e.g. Deutsch. */
				  sprintf( __( 'Translate everything into %s', 'swiftling' ), language.name );
		startButton.disabled = ! agree.checked;
		startNote.textContent = language.pages ? '' : __( 'Every text of this language is filled in already.', 'swiftling' );
	};

	document.querySelectorAll( 'input[name="swiftling-all-language"]' ).forEach( ( input ) => {
		input.addEventListener( 'change', showLanguage );
	} );
	agree.addEventListener( 'change', () => {
		startButton.disabled = ! agree.checked || ! chosen();
	} );
	showLanguage();

	/* While it runs. */

	const stateLabels = () => ( {
		waiting: __( 'Waiting', 'swiftling' ),
		working: __( 'Working…', 'swiftling' ),
		done: __( 'Done', 'swiftling' ),
		skipped: __( 'Skipped', 'swiftling' ),
	} );

	// One row per page, so the user sees where the run is and what it left behind.
	const pageRows = ( pages ) => {
		const labels = stateLabels();
		rows.textContent = '';
		const made = new Map();
		pages.forEach( ( page ) => {
			const row = document.createElement( 'tr' );
			const title = document.createElement( 'th' );
			const state = document.createElement( 'td' );
			title.scope = 'row';
			title.textContent = page.title;
			state.textContent = labels.waiting;
			row.append( title, state );
			rows.append( row );
			made.set( page.key, state );
		} );
		return made;
	};

	const showProgress = ( done, total, text ) => {
		const percent = total ? Math.round( ( 100 * done ) / total ) : 0;
		bar.querySelector( '.is-progress' ).style.width = percent + '%';
		bar.setAttribute( 'aria-valuenow', String( percent ) );
		pagesLine.textContent = sprintf(
			/* translators: 1: pages done, 2: pages in all. */
			_n( '%1$s of %2$s page', '%1$s of %2$s pages', total, 'swiftling' ),
			number( done ),
			number( total )
		);
		currentLine.textContent = text;
	};

	const showCounters = ( texts, characters, skipped ) => {
		counters.texts.textContent = number( texts );
		counters.characters.textContent = number( characters );
		counters.skipped.textContent = number( skipped );
	};

	const finish = ( language, state ) => {
		runningBox.hidden = true;
		before.hidden = false;
		finishedBox.hidden = false;
		status.querySelector( '.swiftling-notice__title' ).textContent = sprintf(
			/* translators: %s: language name, e.g. Deutsch. */
			__( '%s is translated.', 'swiftling' ),
			language.name
		);
		status.querySelector( '.swiftling-notice__text' ).textContent = sprintf(
			/* translators: 1: number of pages, 2: number of texts, 3: number of characters, 4: translation service name, e.g. Google. */
			__( '%1$s pages done, %2$s texts translated, %3$s characters sent to %4$s. Read the machine translations and mark them reviewed.', 'swiftling' ),
			number( state.pages.length ),
			number( state.texts ),
			number( state.characters ),
			config.service
		);

		skippedRows.textContent = '';
		state.skipped.forEach( ( page ) => {
			const row = document.createElement( 'tr' );
			const title = document.createElement( 'th' );
			const reason = document.createElement( 'td' );
			const open = document.createElement( 'td' );
			const link = document.createElement( 'a' );
			title.scope = 'row';
			title.textContent = page.title;
			reason.textContent = page.reason;
			link.href = page.editor;
			link.textContent = __( 'Open in the editor', 'swiftling' );
			open.append( link );
			row.append( title, reason, open );
			skippedRows.append( row );
		} );
		skippedBox.hidden = 0 === state.skipped.length;
	};

	// What a tile says under the name of its language: what is left, where a run stopped, or the
	// date the language was done on.
	const tileNote = ( lang, text ) => {
		const note = document.querySelector( '.swiftling-all-tile[data-lang="' + lang + '"] .swiftling-all-tile__note' );
		if ( note ) {
			note.textContent = text;
		}
	};

	// The tile of a language that is done says so and cannot be chosen again; the next language can.
	const tileDone = ( lang, date ) => {
		const tile = document.querySelector( '.swiftling-all-tile[data-lang="' + lang + '"]' );
		if ( ! tile ) {
			return;
		}
		tile.classList.add( 'is-done' );
		tile.querySelector( 'input' ).checked = false;
		tile.querySelector( 'input' ).disabled = true;
		/* translators: %s: date, e.g. 3 April 2025. */
		tileNote( lang, sprintf( __( '✓ Done on %s', 'swiftling' ), date ) );
		const next = document.querySelector( 'input[name="swiftling-all-language"]:not([disabled])' );
		if ( next ) {
			next.checked = true;
		}
		agree.checked = false;
		showLanguage();
	};

	const run = async ( lang ) => {
		const language = config.languages[ lang ];
		running = true;
		stopping = false;
		before.hidden = true;
		finishedBox.hidden = true;
		runningBox.hidden = false;
		stopButton.disabled = false;

		let state = null;
		let stopped = '';
		let texts = 0;
		let characters = 0;
		let skipped = 0;
		let done = 0;

		try {
			const plan = await api( 'translate-all/plan', { lang } );
			// A run that stopped before counts the pages it did already.
			done = plan.run.pages.length;
			skipped = plan.run.skipped.length;
			texts = plan.run.texts;
			characters = plan.run.characters;
			const total = done + skipped + plan.pages.length;
			const states = pageRows( plan.pages );
			const labels = stateLabels();
			showCounters( texts, characters, skipped );
			showProgress( done + skipped, total, '' );

			for ( const page of plan.pages ) {
				if ( stopping ) {
					break;
				}
				states.get( page.key ).textContent = labels.working;
				/* translators: %s: title of a page. */
				showProgress( done + skipped, total, sprintf( __( 'Checking %s…', 'swiftling' ), page.title ) );

				let found = null;
				try {
					const check = await pageCheck.check( page.probes );
					found = ( await api( 'probe', { subject: page.key, lang, fill: true, ...check } ) ).items;
				} catch ( error ) {
					// A page that cannot be checked is skipped; the run goes on with the next one.
					++skipped;
					states.get( page.key ).textContent = labels.skipped;
					await api( 'translate-all/progress', {
						lang,
						page: page.key,
						outcome: 'skipped',
						reason: error.serverMessage || __( 'The page could not be checked in the browser.', 'swiftling' ),
						total,
					} );
					showCounters( texts, characters, skipped );
					showProgress( done + skipped, total, '' );
					continue;
				}

				/* translators: %s: title of a page. */
				showProgress( done + skipped, total, sprintf( __( 'Translating %s…', 'swiftling' ), page.title ) );
				try {
					const pageDone = await machine.translateItems( api, page.key, lang, found, ( batchTexts, batchCharacters ) =>
						showCounters( texts + batchTexts, characters + batchCharacters, skipped )
					);
					texts += pageDone.texts;
					characters += pageDone.characters;
					++done;
					states.get( page.key ).textContent = labels.done;
					await api( 'translate-all/progress', {
						lang,
						page: page.key,
						outcome: 'done',
						texts: pageDone.texts,
						characters: pageDone.characters,
						total,
					} );
				} catch ( error ) {
					// The service stopped: nothing more is sent, so the key is not spent on failures.
					++skipped;
					states.get( page.key ).textContent = labels.skipped;
					stopped = error.serverMessage || __( 'The translation stopped.', 'swiftling' );
					await api( 'translate-all/progress', {
						lang,
						page: page.key,
						outcome: 'skipped',
						reason: stopped,
						total,
					} );
					break;
				}
				showCounters( texts, characters, skipped );
				showProgress( done + skipped, total, '' );
			}

			if ( ! stopping && '' === stopped ) {
				state = await api( 'translate-all/progress', { lang, finish: true, total } );
			} else {
				// The tab stopped: the language is free at once, for this tab or the server.
				state = await api( 'translate-all/progress', { lang, total, stopped: true } );
			}
		} catch ( error ) {
			stopped = error.serverMessage || __( 'The run stopped.', 'swiftling' );
			// eslint-disable-next-line no-console
			console.error( 'SwiftLing:', error );
		} finally {
			running = false;
		}

		if ( state && 'done' === state.status ) {
			language.status = 'done';
			language.pages = 0;
			language.texts = 0;
			language.characters = 0;
			finish( language, state );
			tileDone( lang, config.languages[ lang ].done || '' );
			return;
		}

		// Stopped, by the Stop button or because something went wrong: the language stays open, so
		// the screen offers to go on with the pages that are left.
		runningBox.hidden = true;
		before.hidden = false;
		language.status = 'running';
		if ( state ) {
			language.pages = Math.max( 0, state.total - state.pages.length - state.skipped.length );
			tileNote(
				lang,
				sprintf(
					/* translators: 1: pages done, 2: pages in all. */
					_n( 'Stopped: %1$s of %2$s page', 'Stopped: %1$s of %2$s pages', state.total, 'swiftling' ),
					number( state.pages.length ),
					number( state.total )
				)
			);
		}
		showLanguage();
		startNote.textContent =
			'' === stopped ? __( 'Stopped. Press Continue to go on with the pages that are left.', 'swiftling' ) : stopped;
	};

	startButton.addEventListener( 'click', () => {
		const input = chosen();
		if ( ! input || ! agree.checked || running ) {
			return;
		}
		startNote.textContent = '';
		run( input.value );
	} );

	stopButton.addEventListener( 'click', () => {
		stopping = true;
		stopButton.disabled = true;
		currentLine.textContent = __( 'Stopping after this page…', 'swiftling' );
	} );

	// The run lives in this tab: closing it stops after the page being worked on.
	window.addEventListener( 'beforeunload', ( event ) => {
		if ( running ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );
} )();
