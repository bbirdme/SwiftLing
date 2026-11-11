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
 * and tells the editor with an input event, and assets/js/editor.js fills it through set(). While
 * the text area is empty, the field shows the original, faded, with its formats: a start to type
 * over, as on the page. Nothing is saved until the field is changed; emptied, it shows the
 * original again.
 */
( function () {
	const config = window.swiftlingEditorFormats;
	const wp = window.wp;
	if ( ! config || ! wp || ! wp.blockEditor || ! wp.blocks || ! wp.element ) {
		return;
	}
	const { createElement: h, createRoot, useState, useEffect, useMemo, useRef, Fragment } = wp.element;
	const { BlockEditorProvider, BlockList, BlockTools, BlockToolbar, BlockControls, RichText, useBlockProps, store: blockEditorStore } = wp.blockEditor;
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
			// Whether "Use the original" put the original in, which makes it the translation.
			chosen: { type: 'boolean', default: false },
			// What the field is called, what describes it, and what it shows while it is empty.
			label: { type: 'string', default: '' },
			note: { type: 'string', default: '' },
			placeholder: { type: 'string', default: '' },
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
							onClick: () => setAttributes( { content: attributes.original, chosen: true } ),
						} )
					)
				),
				h( RichText, {
					...props,
					'aria-label': attributes.label || undefined,
					tagName: attributes.tag,
					value: attributes.content,
					onChange: ( content ) => setAttributes( { content } ),
					placeholder: attributes.placeholder,
					__unstableAllowPrefixTransformations: false,
					// A link in a link would break it: the text of a button or a link gets no links, as
					// in the block editor's own button.
					withoutInteractiveFormatting: 'a' === attributes.tag,
				} )
			);
		},
		save: ( { attributes } ) => h( RichText.Content, { tagName: attributes.tag, value: attributes.content } ),
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

	// A field and the text area behind it. A field is in one of three states:
	// - faded: the text area is empty and the field shows the original, faded, as a start to type
	//   over, as on the page; nothing is saved until it changes;
	// - emptied: the user emptied it, so the text area is empty and the original shows only as its
	//   placeholder: typing starts a new text;
	// - translated: the field and the text area hold the translation.
	const Field = ( { input, api, note, label } ) => {
		const original = inner( input.dataset.original || '' );
		const [ before, after ] = edges( input.dataset.original || '' );
		const [ outer, own ] = useMemo( () => elementsAround( input.dataset.prefix || '' ), [] );
		const pointer = useRef( false );
		const initial = '' === input.value && '' !== original ? 'faded' : 'translated';

		const contentOf = ( value, state ) => ( '' === value ? ( 'faded' === state ? original : '' ) : inner( value ) );
		const placeholderOf = ( state ) => ( 'emptied' === state ? words( original ) : config.placeholder );
		const [ blocks, setBlocks ] = useState( () => [
			createBlock( NAME, { content: contentOf( input.value, initial ), original, label, note, placeholder: placeholderOf( initial ), tag: own.tag, look: own.look } ),
		] );
		const [ state, setState ] = useState( initial );
		const [ code, setCode ] = useState( false );
		// The values the text area had, for undo and redo, the field's own: the block editor keeps
		// none here, and the browser's own undo does not reach a field the block editor draws.
		const history = useRef( { entries: [ { value: input.value, state: initial } ], at: 0, time: 0 } );

		// The text area, and with it the editor, which saves it and counts it.
		const write = ( value ) => {
			if ( value !== input.value ) {
				input.value = value;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		};
		const remember = ( value, next, typing ) => {
			const memory = history.current;
			const now = Date.now();
			memory.entries = memory.entries.slice( 0, memory.at + 1 );
			if ( typing && now - memory.time < STEP && 'translated' === memory.entries[ memory.at ].state ) {
				memory.entries[ memory.at ] = { value, state: next };
			} else {
				memory.entries.push( { value, state: next } );
				memory.at = memory.entries.length - 1;
			}
			memory.time = typing ? now : 0;
		};
		// Shows a value in the field, keeping its block, so the field keeps the cursor.
		const show = ( value, next ) => {
			setBlocks( ( current ) => [ { ...current[ 0 ], attributes: { ...current[ 0 ].attributes, content: contentOf( value, next ), placeholder: placeholderOf( next ), chosen: false } } ] );
			setState( next );
		};

		useEffect( () => {
			// Filled by the editor: copying the original, machine translation, typing as HTML.
			api.set = ( value ) => {
				const next = '' === value && '' !== original ? 'faded' : 'translated';
				show( value, next );
				remember( value, next, false );
			};
		}, [] );

		const onChange = ( changed ) => {
			let next = changed;
			let attributes = next[ 0 ] ? next[ 0 ].attributes : { content: '' };
			// The text of a link gets no link of its own, also not one pasted in: its words stay.
			if ( 'a' === own.tag && /<a\b/i.test( String( attributes.content || '' ) ) ) {
				attributes = { ...attributes, content: String( attributes.content ).replace( /<\/?a\b[^>]*>/gi, '' ) };
				next = [ { ...next[ 0 ], attributes } ];
			}
			const content = String( attributes.content || '' );
			// Untouched, the faded original is no translation.
			if ( 'faded' === state && content === original && ! attributes.chosen ) {
				setBlocks( next );
				return;
			}
			if ( isBlank( content ) ) {
				// Emptied: the original shows as the placeholder, and typing starts a new text.
				setBlocks( [ { ...next[ 0 ], attributes: { ...attributes, content: '', placeholder: placeholderOf( 'emptied' ), chosen: false } } ] );
				setState( 'emptied' );
				remember( '', 'emptied', false );
				write( '' );
				return;
			}
			// The translation from now on, also when it is changed back to the original. The spaces
			// around the original go around it, unless it has its own.
			const value = ( /^(\s|&nbsp;)/.test( content ) ? '' : before ) + content + ( /(\s|&nbsp;)$/.test( content ) ? '' : after );
			setBlocks( next );
			setState( 'translated' );
			remember( value, 'translated', true );
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
			const entry = memory.entries[ at ];
			show( entry.value, entry.state );
			write( entry.value );
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

		// Reached with the keyboard, a field that shows the original has it chosen, so the first key
		// typed replaces it; a click puts the cursor where it was, to change a word of it.
		const onFocusCapture = ( event ) => {
			const editable = event.target.closest && event.target.closest( '[contenteditable]' );
			if ( pointer.current || 'faded' !== state || ! editable || Date.now() - tabbed > 500 ) {
				return;
			}
			setTimeout( () => {
				if ( editable.ownerDocument.activeElement !== editable ) {
					return;
				}
				const range = document.createRange();
				range.selectNodeContents( editable );
				const selection = editable.ownerDocument.defaultView.getSelection();
				selection.removeAllRanges();
				selection.addRange( range );
			} );
		};
		const onPointerDownCapture = () => {
			pointer.current = true;
			setTimeout( () => {
				pointer.current = false;
			}, 300 );
		};

		return h(
			'div',
			{ className: 'swiftling-block-field' + ( 'faded' === state ? ' is-original' : '' ), onKeyDown, onKeyDownCapture, onFocusCapture, onPointerDownCapture },
			h(
				SlotFillProvider,
				null,
				h(
					BlockEditorProvider,
					{ value: blocks, onInput: onChange, onChange, settings },
					h(
						'div',
						{ className: 'swiftling-block-field__toolbar' },
						h( BlockToolbar, { hideDragHandle: true } ),
						h(
							'button',
							{
								type: 'button',
								className: 'swiftling-block-field__code',
								'aria-pressed': code ? 'true' : 'false',
								onClick: () => {
									input.hidden = code;
									setCode( ! code );
								},
							},
							__( 'HTML', 'swiftling' )
						)
					),
					// The text with the theme's styles; the toolbar and its menus keep the editor's.
					! code && h( 'div', { className: 'swiftling-block-field__content', lang: config.lang || undefined, dir: config.dir || undefined }, h( BlockTools, null, outer.reduceRight( ( inside, element ) => h( element.tag, element.look, inside ), h( BlockList ) ) ) ),
					h( Popover.Slot )
				)
			),
			// While the field shows the original, it says so; read by screen readers with its name.
			h( 'span', { id: note, className: 'screen-reader-text' }, 'translated' === state ? '' : __( 'Not translated yet: the field shows the original.', 'swiftling' ) )
		);
	};

	// When the Tab key was last pressed: a field it reaches gets its faded original chosen.
	let tabbed = 0;
	document.addEventListener(
		'keydown',
		( event ) => {
			if ( 'Tab' === event.key ) {
				tabbed = Date.now();
			}
		},
		true
	);

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
		return {
			set: ( html ) => api.set && api.set( html ),
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
