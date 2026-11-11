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
	const { createElement: h, createRoot, useState, useEffect, useMemo, Fragment } = wp.element;
	const { BlockEditorProvider, BlockList, BlockTools, BlockToolbar, BlockControls, RichText, useBlockProps, store: blockEditorStore } = wp.blockEditor;
	const { SlotFillProvider, Popover, ToolbarGroup, ToolbarButton } = wp.components;
	const { registerBlockType, createBlock } = wp.blocks;
	const { useDispatch } = wp.data;
	const { __ } = wp.i18n;

	const NAME = 'swiftling/translation-field';
	let count = 0;

	// Whether a text has nothing but tags and spaces.
	const isBlank = ( html ) => '' === String( html || '' ).replace( /<[^>]*>|&nbsp;|\s/g, '' );

	// The spaces and line breaks around a text, which the field would show as empty lines: they
	// stay outside it and are put back around what it writes.
	const edges = ( html ) => html.match( /^(\s*)[\s\S]*?(\s*)$/ ).slice( 1 );
	const inner = ( html ) => {
		const [ before, after ] = edges( html );
		return html.slice( before.length, html.length - after.length );
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
			// The element that says, while the field shows the original, that it is not translated.
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
				'aria-describedby': attributes.note,
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
					tagName: attributes.tag,
					value: attributes.content,
					onChange: ( content ) => setAttributes( { content } ),
					placeholder: config.placeholder,
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

	// A field and the text area behind it. An empty text area shows the original, faded.
	const Field = ( { input, api, note } ) => {
		const original = inner( input.dataset.original || '' );
		const [ before, after ] = edges( input.dataset.original || '' );
		const [ outer, own ] = useMemo( () => elementsAround( input.dataset.prefix || '' ), [] );
		const blockOf = ( value ) => createBlock( NAME, { content: inner( '' === value ? original : value ), original, note, tag: own.tag, look: own.look } );
		const [ blocks, setBlocks ] = useState( () => [ blockOf( input.value ) ] );
		const [ faded, setFaded ] = useState( '' === input.value && '' !== original );
		const [ code, setCode ] = useState( false );

		useEffect( () => {
			api.set = ( value ) => {
				setBlocks( [ blockOf( value ) ] );
				setFaded( '' === value && '' !== original );
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
			if ( faded && content === original && ! attributes.chosen ) {
				setBlocks( next );
				return;
			}
			const value = isBlank( content ) ? '' : before + content + after;
			// Emptied, it shows the original again. Changed, it is the translation from now on, also
			// when it is changed back to the original.
			setBlocks( '' === value ? [ blockOf( '' ) ] : next );
			setFaded( '' === value && '' !== original );
			if ( value !== input.value ) {
				input.value = value;
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		};

		return h(
			'div',
			{ className: 'swiftling-block-field' + ( faded ? ' is-original' : '' ) },
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
					! code && h( BlockTools, null, outer.reduceRight( ( inside, element ) => h( element.tag, element.look, inside ), h( BlockList ) ) ),
					h( Popover.Slot )
				)
			),
			faded && h( 'span', { id: note, className: 'screen-reader-text' }, __( 'Not translated yet: the field shows the original.', 'swiftling' ) )
		);
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
		createRoot( holder ).render( h( Field, { input, api, note: input.id + '-faded' } ) );
		return {
			set: ( html ) => api.set && api.set( html ),
		};
	};

	// The theme's styles, once for every field: its fonts and the colours of its palette, which the
	// formats name by class.
	if ( wp.blockEditor.__unstableEditorStyles && config.styles ) {
		const styles = document.createElement( 'div' );
		document.body.appendChild( styles );
		// The original beside each field shows in them too, so both look as on the page.
		createRoot( styles ).render(
			h(
				Fragment,
				null,
				h( wp.blockEditor.__unstableEditorStyles, { styles: config.styles, scope: '.swiftling-block-field' } ),
				h( wp.blockEditor.__unstableEditorStyles, { styles: config.styles, scope: '.swiftling-live-html' } )
			)
		);
	}

	window.swiftlingBlockField = { start };
} )();
