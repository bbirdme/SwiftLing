/**
 * What the screens that machine translate share: the translation editor, which fills the empty
 * fields of one page, and the Translate everything screen, which fills the whole site.
 *
 * window.swiftlingMachine.batches( items, size ) cuts the texts into requests of at most 50 texts
 * and 4,000 characters, well within what the machine route accepts (100 texts, 20,000 characters,
 * see SWIFTLING_MACHINE_MAX_TEXTS in includes/api.php), so each request answers quickly and the
 * screen can count what is done. size( item ) gives the length of one text. The server cuts the
 * same batches when it translates without a browser, see swiftling_machine_batches();
 * tests/ApiTest.php holds the two equal.
 *
 * window.swiftlingMachine.translateItems( api, subject, lang, items, sent ) translates texts the
 * server lists as a run sends them, see swiftling_translate_all_items(), and saves them: the
 * Translate everything screen does it for each page, the editor of comments for each batch of them.
 */
( function () {
	const MAX_TEXTS = 50;
	const MAX_CHARACTERS = 4000;

	const batches = ( items, size ) => {
		const all = [];
		let batch = [];
		let length = 0;
		items.forEach( ( item ) => {
			const characters = size( item );
			if ( batch.length && ( batch.length >= MAX_TEXTS || length + characters > MAX_CHARACTERS ) ) {
				all.push( batch );
				batch = [];
				length = 0;
			}
			batch.push( item );
			length += characters;
		} );
		if ( batch.length ) {
			all.push( batch );
		}
		return all;
	};

	// Puts the tags of a text back around its translation, as the translation editor does: plain
	// text inside a heading or a paragraph is escaped first, formatted text keeps its HTML.
	const escapeText = ( text ) => text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

	const wrapped = ( item, text ) => {
		if ( '' === text.trim() ) {
			return '';
		}
		if ( item.escape ) {
			return item.prefix + escapeText( text ) + item.suffix;
		}
		return item.prefix + text + item.suffix;
	};

	// Translates texts in batches and saves each batch at once as machine translations, so what the
	// service translated, and charged for, is kept if a later batch fails. sent( texts, characters )
	// hears after every batch how far it has come. api( route, body ) talks to SwiftLing's routes.
	const translateItems = async ( api, subject, lang, found, sent ) => {
		let texts = 0;
		let characters = 0;
		for ( const batch of batches( found, ( item ) => item.text.length ) ) {
			const result = await api( 'machine', {
				subject,
				lang,
				items: batch.map( ( item ) => ( { key: item.key, text: item.text, html: item.html } ) ),
			} );
			const items = [];
			batch.forEach( ( item ) => {
				const text = result.translations[ item.key ];
				if ( 'string' !== typeof text ) {
					return;
				}
				items.push( { key: item.key, text: wrapped( item, text ), original: item.text } );
				characters += item.text.length;
			} );
			if ( items.length ) {
				await api( 'translations', { subject, lang, status: 'machine', items } );
				texts += items.length;
			}
			sent( texts, characters );
			if ( result.error ) {
				// The service stopped, e.g. because the key was refused: what is saved stays saved.
				const failure = new Error( result.error );
				failure.serverMessage = result.error;
				throw failure;
			}
		}
		return { texts, characters };
	};

	window.swiftlingMachine = { batches, translateItems, MAX_TEXTS, MAX_CHARACTERS };
} )();
