/**
 * The block editor's own text field in the translation editor, for a post made of blocks.
 *
 * A text of a block, such as a heading, a paragraph or a button, gets the toolbar of the block
 * editor with every inline format it has: bold, italic and links, and under More highlight and
 * text colour from the theme's palette, inline code, keyboard input, strikethrough, sub- and
 * superscript. It writes what the block editor writes, so a highlighted word stays highlighted in
 * the translation. The visual editor of WordPress, which the editor gives other texts, has none of
 * these formats.
 *
 * The text area stays where the editor reads and saves the translation: the field writes into it
 * and tells the editor with an input event, and assets/js/editor.js fills it through set(). An
 * empty field shows the original behind it, faded, as on the page, with its formats: what is
 * typed starts the translation, and Use the original puts the original in to change.
 */
( function () {
	const config = window.swiftlingEditorFormats;
	const wp = window.wp;
	if ( ! config || ! wp || ! wp.blockEditor || ! wp.blocks || ! wp.element ) {
		return;
	}
	const { createElement: h, createRoot, createContext, useContext, useState, useEffect, useMemo, useRef, Fragment } = wp.element;
	const { BlockEditorProvider, BlockList, BlockTools, BlockToolbar, BlockControls, RichText, RichTextToolbarButton, useBlockProps, store: blockEditorStore } = wp.blockEditor;
	const { SlotFillProvider, Popover, ToolbarGroup, ToolbarButton } = wp.components;
	const { registerBlockType, createBlock } = wp.blocks;
	const { useDispatch } = wp.data;
	const { __, sprintf } = wp.i18n;

	const NAME = 'swiftling/translation-field';

	// Whether a text has nothing but tags and spaces.
	const isBlank = ( html ) => '' === String( html || '' ).replace( /<[^>]*>|&nbsp;|\s/g, '' );

	// The spaces and line breaks around a text, which the field would show as empty lines: they
	// stay outside it and are put back around what it writes, where it has none of its own.
	const edges = ( html ) => html.match( /^(\s*)[\s\S]*?(\s*)$/ ).slice( 1 );
	const inner = ( html ) => {
		const [ before, after ] = edges( html );
		return html.slice( before.length, html.length - after.length );
	};

	// A link inside a link breaks both, on the page and in the editor: of links one inside another,
	// the outer one stays and the inner ones keep their words; in a text whose own element is a link,
	// as a button's, no link stays at all.
	const tidyLinks = ( html, own ) => {
		let open = 0;
		let dropped = 0;
		return String( html ).replace( /<a\b[^>]*>|<\/a\s*>/gi, ( tag ) => {
			if ( 'a' === own ) {
				return '';
			}
			if ( '/' !== tag[ 1 ] ) {
				if ( open ) {
					++dropped;
					return '';
				}
				++open;
				return tag;
			}
			if ( dropped ) {
				--dropped;
				return '';
			}
			open = Math.max( 0, open - 1 );
			return tag;
		} );
	};

	// The words of a text, without its tags.
	const words = ( html ) => {
		const template = document.createElement( 'template' );
		template.innerHTML = html;
		return template.content.textContent;
	};

	// One block per field: the text, its formats and "Use the original" in its toolbar.
	registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Translation', 'swiftling' ),
		category: 'text',
		attributes: {
			content: { type: 'string', default: '' },
			original: { type: 'string', default: '' },
			// What the field is called, and what describes it while it is empty.
			label: { type: 'string', default: '' },
			note: { type: 'string', default: '' },
			// The element the text is in on the page, which the field is: its tag, classes and style.
			tag: { type: 'string', default: 'p' },
			look: { type: 'object', default: {} },
		},
		supports: { inserter: false, html: false, lock: false, reusable: false, customClassName: false, className: false, multiple: false },
		edit: ( { attributes, setAttributes, clientId, isSelected } ) => {
			const { selectBlock } = useDispatch( blockEditorStore );
			// The toolbar shows while the block is selected: always, in a field of its own.
			useEffect( () => {
				if ( ! isSelected ) {
					selectBlock( clientId );
				}
			}, [] );
			const props = useBlockProps( {
				className: [ 'swiftling-block-field__text', attributes.look.className ].filter( Boolean ).join( ' ' ),
				style: attributes.look.style,
				'aria-describedby': attributes.note || undefined,
			} );
			return h(
				Fragment,
				null,
				h(
					BlockControls,
					{ group: 'other' },
					h(
						ToolbarGroup,
						null,
						h( ToolbarButton, {
							icon: 'admin-page',
							label: __( 'Use the original, with its formatting', 'swiftling' ),
							onClick: () => setAttributes( { content: attributes.original } ),
						} )
					)
				),
				h( RichText, {
					...props,
					'aria-label': attributes.label || undefined,
					tagName: attributes.tag,
					value: attributes.content,
					onChange: ( content ) => setAttributes( { content } ),
					__unstableAllowPrefixTransformations: false,
					// A link in a link would break it: the text of a button or a link gets no links, as
					// in the block editor's own button.
					withoutInteractiveFormatting: 'a' === attributes.tag,
				} )
			);
		},
		save: ( { attributes } ) => h( RichText.Content, { tagName: attributes.tag, value: attributes.content } ),
	} );

	// The field a control of the menu of formats is in: "Edit as HTML" shows its text area.
	const FieldContext = createContext( null );
	wp.richText.registerFormatType( 'swiftling/edit-as-html', {
		title: __( 'Edit as HTML', 'swiftling' ),
		tagName: 'swiftling-edit-as-html',
		className: null,
		edit: () => {
			const field = useContext( FieldContext );
			return field ? h( RichTextToolbarButton, { icon: 'editor-code', title: __( 'Edit as HTML', 'swiftling' ), onClick: field.editAsHtml } ) : null;
		},
	} );

	// The elements a text is in on the page, from the tags before it, as the browser reads them:
	// each with its tag, classes and style, nothing that does something, as the address of a link.
	// The last one is the element the field edits; those around it are drawn around the field.
	const elementsAround = ( prefix ) => {
		const template = document.createElement( 'template' );
		template.innerHTML = prefix + '<i data-swiftling-text></i>';
		const chain = [];
		for ( let element = template.content.querySelector( '[data-swiftling-text]' ).parentElement; element; element = element.parentElement ) {
			const style = {};
			for ( const name of element.style ) {
				style[ name.startsWith( '--' ) ? name : name.replace( /-([a-z])/g, ( match, letter ) => letter.toUpperCase() ) ] = element.style.getPropertyValue( name );
			}
			chain.unshift( { tag: element.tagName.toLowerCase(), look: { className: element.getAttribute( 'class' ) || undefined, style } } );
		}
		// The browser opens and closes details by their summary, and presses a button: inside them,
		// the text is edited in an element of its own.
		const own = chain.length && ! [ 'summary', 'button' ].includes( chain[ chain.length - 1 ].tag ) ? chain.pop() : { tag: 'span', look: {} };
		// A list item shows in a list, with its bullet, as on the page.
		if ( 'li' === own.tag && ! chain.some( ( element ) => [ 'ul', 'ol' ].includes( element.tag ) ) ) {
			chain.push( { tag: 'ul', look: {} } );
		}
		return [ chain, own ];
	};

	const settings = {
		...config.settings,
		hasFixedToolbar: true,
		focusMode: false,
		canLockBlocks: false,
		codeEditingEnabled: false,
		templateLock: 'all',
	};

	// Typing within this time is one step of undo.
	const STEP = 1000;

	// A field and the text area behind it. Empty, it shows the original behind it, faded, as on the
	// page; what is typed starts the translation.
	const Field = ( { input, api, note, label } ) => {
		const original = inner( input.dataset.original || '' );
		const [ before, after ] = edges( input.dataset.original || '' );
		const [ outer, own ] = useMemo( () => elementsAround( input.dataset.prefix || '' ), [] );
		const contentOf = ( value ) => {
			const content = inner( tidyLinks( value, own.tag ) );
			return isBlank( content ) ? '' : content;
		};
		const [ blocks, setBlocks ] = useState( () => {
			const content = contentOf( input.value );
			return [ createBlock( NAME, { content, original, label, tag: own.tag, look: own.look, note: '' === content ? note : '' } ) ];
		} );
		const [ code, setCode ] = useState( false );
		const empty = '' === ( blocks[ 0 ] ? blocks[ 0 ].attributes.content : '' );
		// The values the text area had, for undo and redo, the field's own: the block editor keeps
		// none here, and the browser's own undo does not reach a field the block editor draws.
		const history = useRef( { entries: [ input.value ], at: 0, time: 0 } );

		// The text area, and with it the editor, which saves it and counts it.
		const write = ( value ) => {
			if ( value !== input.value ) {
				input.value = value;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		};
		const remember = ( value, typing ) => {
			const memory = history.current;
			const now = Date.now();
			// The same value again, as the block editor reports a change once as typed and once more
			// when it settles, is no step of its own.
			if ( memory.entries[ memory.at ] === value ) {
				return;
			}
			memory.entries = memory.entries.slice( 0, memory.at + 1 );
			if ( typing && memory.at > 0 && now - memory.time < STEP && '' !== memory.entries[ memory.at ] ) {
				memory.entries[ memory.at ] = value;
			} else {
				memory.entries.push( value );
				memory.at = memory.entries.length - 1;
			}
			memory.time = typing ? now : 0;
		};
		// Shows a value in the field, keeping its block, so the field keeps the cursor.
		const show = ( value ) => {
			const content = contentOf( value );
			setBlocks( ( current ) => [ { ...current[ 0 ], attributes: { ...current[ 0 ].attributes, content, note: '' === content ? note : '' } } ] );
		};

		useEffect( () => {
			// Filled by the editor: copying the original, machine translation, typing as HTML.
			api.set = ( value ) => {
				show( value );
				remember( value, false );
			};
		}, [] );

		const onChange = ( changed ) => {
			// No link in a link, also not one pasted in: its words stay.
			const content = contentOf( changed[ 0 ] ? changed[ 0 ].attributes.content || '' : '' );
			setBlocks( [ { ...changed[ 0 ], attributes: { ...changed[ 0 ].attributes, content, note: '' === content ? note : '' } } ] );
			// The spaces around the original go around the translation, unless it has its own.
			const value = '' === content ? '' : ( /^(\s|&nbsp;)/.test( content ) ? '' : before ) + content + ( /(\s|&nbsp;)$/.test( content ) ? '' : after );
			remember( value, true );
			write( value );
		};

		const step = ( by ) => {
			const memory = history.current;
			const at = memory.at + by;
			if ( at < 0 || at >= memory.entries.length ) {
				return;
			}
			memory.at = at;
			memory.time = 0;
			show( memory.entries[ at ] );
			write( memory.entries[ at ] );
		};

		// Ctrl+K adds a link here, as in the block editor, rather than opening the command palette.
		const onKeyDown = ( event ) => {
			if ( ( event.ctrlKey || event.metaKey ) && 'k' === event.key.toLowerCase() ) {
				event.stopPropagation();
			}
		};
		const onKeyDownCapture = ( event ) => {
			if ( ! ( event.ctrlKey || event.metaKey ) || event.altKey ) {
				return;
			}
			const key = event.key.toLowerCase();
			if ( 'z' === key || 'y' === key ) {
				event.preventDefault();
				event.stopPropagation();
				step( 'z' === key && ! event.shiftKey ? -1 : 1 );
			}
		};

		// As HTML, the text area shows instead of the field; back, the field shows what it holds.
		const editAs = ( html ) => {
			input.hidden = ! html;
			setCode( html );
			if ( ! html ) {
				write( tidyLinks( input.value, own.tag ) );
				show( input.value );
			}
		};
		const context = useMemo( () => ( { editAsHtml: () => editAs( true ) } ), [] );

		// Around the text, the elements it is in on the page, as the field draws them.
		const around = ( inside ) => outer.reduceRight( ( content, element ) => h( element.tag, element.look, content ), inside );

		return h(
			'div',
			{ className: 'swiftling-block-field' + ( empty ? ' is-empty' : '' ), onKeyDown, onKeyDownCapture },
			h(
				FieldContext.Provider,
				{ value: context },
				h(
					SlotFillProvider,
					null,
					h(
						BlockEditorProvider,
						{ value: blocks, onInput: onChange, onChange, settings },
						h(
							'div',
							{ className: 'swiftling-block-field__toolbar' },
							code
								? h( 'button', { type: 'button', className: 'swiftling-block-field__visual', onClick: () => editAs( false ) }, __( 'Edit visually', 'swiftling' ) )
								: h( BlockToolbar, { hideDragHandle: true } )
						),
						// The text with the theme's styles; the toolbar and its menus keep the editor's.
						! code &&
							h(
								'div',
								{ className: 'swiftling-block-field__content', lang: config.lang || undefined, dir: config.dir || undefined },
								// Empty, the original shows behind the field, faded, in the elements of the page.
								empty &&
									'' !== original &&
									h(
										'div',
										{ className: 'swiftling-block-field__original', 'aria-hidden': 'true' },
										around( h( 'div', { className: 'block-editor-block-list__layout' }, h( own.tag, { ...own.look, className: [ 'swiftling-block-field__text', own.look.className ].filter( Boolean ).join( ' ' ), dangerouslySetInnerHTML: { __html: original } } ) ) )
									),
								h( BlockTools, null, around( h( BlockList ) ) )
							),
						h( Popover.Slot )
					)
				)
			),
			// While the field is empty, it says what visitors read: the original.
			h(
				'span',
				{ id: note, className: 'screen-reader-text' },
				/* translators: %s: the original text. */
				empty ? sprintf( __( 'Not translated yet; the original is: %s', 'swiftling' ), words( original ) ) : ''
			)
		);
	};

	// The name of a field for screen readers: what the row is, in which language, as "Heading in
	// Français".
	const labelOf = ( input ) => {
		const heading = input.closest( 'tr' ) && input.closest( 'tr' ).querySelector( 'th' );
		const node = heading ? [ ...heading.childNodes ].find( ( child ) => 3 === child.nodeType && '' !== child.textContent.trim() ) : null;
		const kind = node ? node.textContent.trim() : __( 'Translation', 'swiftling' );
		/* translators: 1: kind of text, e.g. Heading, 2: language name, e.g. Deutsch. */
		return config.language ? sprintf( __( '%1$s in %2$s', 'swiftling' ), kind, config.language ) : kind;
	};

	// Puts a field in place of a text area, which stays underneath: hidden, or shown as HTML.
	const start = ( input ) => {
		const api = { set: null };
		const holder = document.createElement( 'div' );
		holder.className = 'swiftling-block-field__holder';
		input.insertAdjacentElement( 'beforebegin', holder );
		input.hidden = true;
		// Typed into as HTML: the field follows.
		input.addEventListener( 'input', ( event ) => {
			if ( event.isTrusted && api.set ) {
				api.set( input.value );
			}
		} );
		createRoot( holder ).render( h( Field, { input, api, note: input.id + '-faded', label: labelOf( input ) } ) );
		const own = elementsAround( input.dataset.prefix || '' )[ 1 ];
		return {
			set: ( html ) => api.set && api.set( html ),
			// What is saved, whichever way it reached the text area, as typed as HTML.
			tidy: ( html ) => tidyLinks( html, own.tag ),
		};
	};

	// The theme's styles, once for every field: its fonts and the colours of its palette, which the
	// formats name by class.
	if ( wp.blockEditor.__unstableEditorStyles && config.styles ) {
		const styles = document.createElement( 'div' );
		document.body.appendChild( styles );
		// The text of each field and the original beside it, so both look as on the page; not the
		// toolbar and its menus.
		createRoot( styles ).render(
			h(
				Fragment,
				null,
				h( wp.blockEditor.__unstableEditorStyles, { styles: config.styles, scope: '.swiftling-block-field__content' } ),
				h( wp.blockEditor.__unstableEditorStyles, { styles: config.styles, scope: '.swiftling-live-html' } )
			)
		);
	}

	window.swiftlingBlockField = { start };
} )();
