/**
 * Visual editor: a page made of blocks laid out as its blocks, translated in place. See
 * includes/visual-editor.php; the configuration comes from admin/views/visual.php.
 *
 * SwiftLing lists the texts of the page with a path into the post's blocks: "b/0/0/1/0/c0" is
 * blocks[0].innerBlocks[0].innerBlocks[1].innerBlocks[0], innerContent[0]; "…/c0/@alt0" the first
 * alt attribute inside that HTML; "b/4/a/content/Intro/content" a block attribute, such as an
 * override of a synced pattern. The texts of a synced pattern are listed under the pattern's own
 * object (post:<id>).
 *
 * The blocks are parsed with WordPress's own parser, synced patterns are laid out in place, the
 * translations are put into the blocks' HTML, and the result is parsed into blocks as the block
 * editor does. Saving goes the other way: the HTML of a changed block is the translation of its
 * text, an image's address and alt text the translations of its attributes, an override the value
 * of the attribute; all saved by key with the translation editor's own route. Texts with no place
 * on the page (link addresses, code, the excerpt, SEO fields) are fields beside it.
 */
( function () {
	const configElement = document.getElementById( 'swiftling-visual-config' );
	if ( ! configElement || ! window.wp || ! window.wp.blockEditor ) {
		return;
	}
	const wp = window.wp;
	const data = JSON.parse( configElement.textContent );
	const { createElement: h, createRoot, useState, useEffect, useMemo, useCallback, useRef, Fragment } = wp.element;
	const { BlockEditorProvider, BlockCanvas, BlockList, BlockControls, BlockEditorKeyboardShortcuts, BlockPreview, PlainText, InnerBlocks, useBlockProps, useInnerBlocksProps, store: blockEditorStore } = wp.blockEditor;
	const { SlotFillProvider, Popover, Button, ToolbarGroup, ToolbarButton, Dropdown, Notice, TextareaControl, TextControl, ToggleControl } = wp.components;
	const { parse, serializeRawBlock, getBlockContent, registerBlockType, createBlock } = wp.blocks;
	const rawParse = wp.blockSerializationDefaultParser.parse;
	const { addFilter } = wp.hooks;
	const { __, _n, sprintf } = wp.i18n;
	const { useSelect, useDispatch } = wp.data;
	const apiFetch = wp.apiFetch;

	const MISSING = [ 'missing', 'outdated' ];
	const DONE = [ 'reviewed', 'machine', 'official' ];
	const MEDIA_BLOCKS = [ 'core/image', 'core/cover', 'core/media-text', 'core/video', 'core/audio', 'core/file' ];
	const PAGE = data.subject.key;
	const LANG = data.language ? data.language.name : data.lang;
	const LABELS = data.labels || {};

	/* Helpers. */

	const htmlString = ( value ) => ( value && 'object' === typeof value && 'function' === typeof value.toHTMLString ? value.toHTMLString() : null === value || undefined === value ? '' : String( value ) );
	const escapeAttr = ( text ) => text.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	const getPath = ( object, parts ) => parts.reduce( ( value, part ) => ( value && 'object' === typeof value ? value[ part ] : undefined ), object );
	const setPath = ( object, parts, value ) => {
		const copy = Array.isArray( object ) ? [ ...object ] : { ...( object || {} ) };
		copy[ parts[ 0 ] ] = 1 === parts.length ? value : setPath( copy[ parts[ 0 ] ], parts.slice( 1 ), value );
		return copy;
	};
	const stateLabel = ( state ) => LABELS[ state ] || state;

	// Attributes as text, to see whether a block changed since it was loaded or saved.
	const snapshot = ( attributes ) => {
		const out = {};
		Object.keys( attributes ).filter( ( key ) => 'swl' !== key ).sort().forEach( ( key ) => {
			const value = attributes[ key ];
			out[ key ] = value && 'object' === typeof value && 'function' === typeof value.toHTMLString ? value.toHTMLString() : value;
		} );
		return JSON.stringify( out );
	};
	const textUnitOf = ( swl ) => swl.units.find( ( unit ) => ! unit.attr && ! unit.override && ! unit.attrPath );
	const attrUnitOf = ( swl, attr ) => swl.units.find( ( unit ) => unit.attr === attr || ( 'src' === attr && 'url' === unit.override ) || ( 'alt' === attr && 'alt' === unit.override ) );
	const untouched = ( attributes ) => snapshot( attributes ) === attributes.swl.loadedSnap;
	// Whether a block counts as translated now: saved so, changed here, or the original copied.
	const translated = ( attributes ) => {
		const swl = attributes.swl;
		return ! swl || swl.copied || ! untouched( attributes ) || ! swl.units.some( ( unit ) => MISSING.includes( unit.state ) );
	};
	// The worst state among a block's texts, as the table shows it: outdated, missing, machine, reviewed.
	const blockState = ( attributes ) => {
		const swl = attributes.swl;
		if ( ! swl ) {
			return '';
		}
		if ( swl.copied || ! untouched( attributes ) ) {
			return 'edited';
		}
		const states = swl.units.map( ( unit ) => unit.state );
		return [ 'outdated', 'missing', 'machine', 'official', 'reviewed' ].find( ( state ) => states.includes( state ) ) || states[ 0 ] || '';
	};
	const ownImage = ( attributes ) => attributes.swl && attrUnitOf( attributes.swl, 'src' ) && attributes.url && attributes.swl.originalAttrs.url && attributes.url !== attributes.swl.originalAttrs.url;

	/* Filters: before the core blocks are registered. */

	addFilter( 'blocks.registerBlockType', 'swiftling/visual-attributes', ( settings ) => ( {
		...settings,
		attributes: { ...( settings.attributes || {} ), swl: { type: 'object' } },
	} ) );
	if ( wp.mediaUtils && wp.mediaUtils.MediaUpload ) {
		addFilter( 'editor.MediaUpload', 'swiftling/visual-media-upload', () => wp.mediaUtils.MediaUpload );
	}

	// A text not translated yet is greyed out, as a placeholder; its state and an own image are marked.
	addFilter( 'editor.BlockListBlock', 'swiftling/visual-placeholder', ( BlockListBlock ) => ( props ) => {
		const swl = props.attributes && props.attributes.swl;
		if ( ! swl ) {
			return h( BlockListBlock, props );
		}
		const state = blockState( props.attributes );
		const classes = [ props.className, 'swiftling-visual-unit', 'swiftling-visual-state-' + state ];
		if ( ! translated( props.attributes ) ) {
			classes.push( textUnitOf( swl ) || swl.units.some( ( unit ) => unit.override || unit.attrPath ) ? 'swiftling-visual-missing' : 'swiftling-visual-missing-media' );
		}
		const wrapperProps = { ...( props.wrapperProps || {} ), 'data-swiftling-visual-state': 'edited' === state ? __( 'changed, not saved', 'swiftling' ) : stateLabel( state ) };
		if ( ownImage( props.attributes ) ) {
			classes.push( 'swiftling-visual-own-image' );
			/* translators: %s: language name */
			wrapperProps[ 'data-swiftling-visual-image' ] = sprintf( __( '%s image', 'swiftling' ), LANG );
		}
		return h( BlockListBlock, { ...props, className: classes.filter( Boolean ).join( ' ' ), wrapperProps } );
	} );

	/* The original of a block, and the language's image. */

	// The media library, for this language only.
	const chooseImage = ( onChoose ) => {
		if ( ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			/* translators: %s: language name */
			title: sprintf( __( 'Choose the image for %s', 'swiftling' ), LANG ),
			library: { type: 'image' },
			multiple: false,
			/* translators: %s: language name */
			button: { text: sprintf( __( 'Use for %s', 'swiftling' ), LANG ) },
		} );
		frame.on( 'select', () => {
			const chosen = frame.state().get( 'selection' ).first().toJSON();
			onChoose( chosen );
		} );
		frame.open();
	};

	// SwiftLing's buttons, so the screen reads as one of its own.
	const SButton = ( { variant = 'secondary', icon, busy, className, children, ...rest } ) =>
		h(
			rest.href ? 'a' : 'button',
			{ type: rest.href ? undefined : 'button', ...rest, className: [ 'swiftling-button', 'is-' + variant, busy ? 'is-busy' : '', className ].filter( Boolean ).join( ' ' ) },
			busy && h( 'span', { className: 'swiftling-spinner', 'aria-hidden': true } ),
			icon && h( 'span', { className: 'dashicons dashicons-' + icon, 'aria-hidden': true } ),
			children
		);
	const StatusIcon = ( { state } ) => h( 'span', { className: 'swiftling-status is-' + ( 'edited' === state ? 'partial' : state ), title: stateLabel( state ), 'aria-hidden': true } );
	const SOURCE = data.source ? data.source.name : __( 'the original language', 'swiftling' );

	// What a block is, in words: "Heading", "Paragraph", "Button".
	const blockTitle = ( name ) => {
		const type = wp.blocks.getBlockType( name );
		return type ? type.title : __( 'Text', 'swiftling' );
	};

	// The original of the chosen block beside the page (in the sidebar, or the toolbar's popover);
	// for an image, the image and alt text of this language next to the original's.
	const OriginalPanel = ( { name, attributes, setAttributes, width } ) => {
		const swl = attributes.swl;
		const alt = attrUnitOf( swl, 'alt' );
		const src = attrUnitOf( swl, 'src' );
		const shared = swl.units.some( ( unit ) => unit.object !== PAGE );
		const original = useMemo( () => [ createBlock( name, { ...swl.originalAttrs } ) ], [ name, swl.originalAttrs ] );
		const own = ownImage( attributes );
		const changed = ! untouched( attributes ) || swl.copied;
		const states = h(
			'ul',
			{ className: 'swiftling-visual-unit-states' },
			swl.units.map( ( unit ) =>
				h( 'li', { key: unit.key }, h( StatusIcon, { state: changed ? 'edited' : unit.state } ), h( 'span', null, unitKind( unit ), ' · ', changed ? __( 'changed, not saved yet', 'swiftling' ) : 'src' === unit.attr && MISSING.includes( unit.state ) ? __( 'the original’s', 'swiftling' ) : stateLabel( unit.state ) ) )
			)
		);
		const sharedNote = shared && h( 'p', { className: 'swiftling-visual-note' }, h( 'span', { className: 'dashicons dashicons-controls-repeat', 'aria-hidden': true } ), __( 'Part of a synced pattern: translated once for every page that uses it.', 'swiftling' ) );
		if ( src ) {
			return h(
				'div',
				{ className: 'swiftling-visual-original is-image' },
				h(
					'div',
					{ className: 'swiftling-visual-image-pair' + ( own ? '' : ' is-single' ) },
					h( 'figure', null, h( 'img', { src: src.original || swl.originalAttrs.url, alt: '' } ), h( 'figcaption', null, own ? __( 'Original', 'swiftling' ) : /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'The original, also in %s', 'swiftling' ), LANG ) ) ),
					own && h( 'figure', { className: 'is-own' }, h( 'img', { src: attributes.url, alt: '' } ), h( 'figcaption', null, LANG ) )
				),
				h(
					'div',
					{ className: 'swiftling-visual-actions' },
					h( SButton, { icon: 'format-image', onClick: () => chooseImage( ( chosen ) => setAttributes( { url: chosen.url, id: chosen.id } ) ) }, own ? __( 'Choose another image', 'swiftling' ) : /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Use a different image for %s', 'swiftling' ), LANG ) ),
					own && h( SButton, { variant: 'link', onClick: () => setAttributes( { url: swl.originalAttrs.url, id: swl.originalAttrs.id } ) }, __( 'Use the original image', 'swiftling' ) )
				),
				alt &&
					h(
						'div',
						{ className: 'swiftling-visual-field' },
						h( 'label', { htmlFor: 'swiftling-visual-alt-' + alt.key }, /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Alt text in %s', 'swiftling' ), LANG ) ),
						h( 'p', { className: 'swiftling-visual-source' }, h( 'span', { className: 'swiftling-visual-source__label' }, SOURCE ), alt.original || '—' ),
						h( 'textarea', { id: 'swiftling-visual-alt-' + alt.key, rows: 2, value: ! changed && MISSING.includes( alt.state ) && attributes.alt === swl.originalAttrs.alt ? '' : attributes.alt || '', placeholder: alt.original, onChange: ( event ) => setAttributes( { alt: event.target.value } ) } )
					),
				states,
				sharedNote
			);
		}
		return h(
			'div',
			{ className: 'swiftling-visual-original' },
			h( 'p', { className: 'swiftling-visual-source__label' }, /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Original in %s', 'swiftling' ), SOURCE ) ),
			h( 'div', { className: 'swiftling-visual-original-preview' }, h( BlockPreview, { blocks: original, viewportWidth: width || 480 } ) ),
			states,
			sharedNote,
			translated( attributes ) && h( 'div', { className: 'swiftling-visual-actions' }, h( SButton, { icon: 'undo', onClick: () => setAttributes( { ...swl.originalAttrs } ), title: __( 'Puts the original text back into this block, with its formats. Change what needs changing, then save.', 'swiftling' ) }, __( 'Use the original', 'swiftling' ) ) )
		);
	};
	const unitKind = ( unit ) => ( { alt: __( 'Alt text', 'swiftling' ), src: __( 'Image', 'swiftling' ), url: __( 'Image', 'swiftling' ) } )[ unit.attr || unit.override ] || __( 'Text', 'swiftling' );

	addFilter( 'editor.BlockEdit', 'swiftling/visual-original', ( BlockEdit ) => ( props ) => {
		const swl = props.attributes && props.attributes.swl;
		if ( ! swl || ! props.isSelected ) {
			return h( BlockEdit, props );
		}
		const media = MEDIA_BLOCKS.includes( props.name ) && attrUnitOf( swl, 'src' );
		return h(
			Fragment,
			null,
			h( BlockEdit, props ),
			h(
				BlockControls,
				{ group: 'other' },
				h(
					ToolbarGroup,
					null,
					media && h( ToolbarButton, { icon: 'format-image', label: /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Use a different image for %s', 'swiftling' ), LANG ), onClick: () => chooseImage( ( chosen ) => props.setAttributes( { url: chosen.url, id: chosen.id } ) ) }, /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Image for %s', 'swiftling' ), LANG ) ),
					media && ownImage( props.attributes ) && h( ToolbarButton, { icon: 'undo', label: __( 'Use the original image', 'swiftling' ), onClick: () => props.setAttributes( { url: swl.originalAttrs.url, id: swl.originalAttrs.id } ) } ),
					h( Dropdown, {
						popoverProps: { placement: 'bottom-start' },
						renderToggle: ( { isOpen, onToggle } ) => h( ToolbarButton, { icon: 'translation', label: __( 'Show the original', 'swiftling' ), onClick: onToggle, isPressed: isOpen } ),
						renderContent: () => h( OriginalPanel, { name: props.name, attributes: props.attributes, setAttributes: props.setAttributes, width: 600 } ),
					} )
				)
			)
		);
	} );

	wp.blockLibrary.registerCoreBlocks();

	registerBlockType( 'swiftling/visual-pattern', {
		apiVersion: 3,
		title: __( 'Synced pattern', 'swiftling' ),
		category: 'reusable',
		icon: 'controls-repeat',
		attributes: { ref: { type: 'number' }, title: { type: 'string' } },
		supports: { inserter: false, html: false, lock: false, reusable: false, customClassName: false, className: false },
		edit: ( { attributes } ) => {
			const props = useBlockProps( { className: 'swiftling-visual-pattern', 'data-swiftling-visual-title': /* translators: %s: name of a synced pattern. */ sprintf( __( 'Synced pattern: %s', 'swiftling' ), attributes.title || '' ) } );
			return h( 'div', useInnerBlocksProps( props, { renderAppender: false } ) );
		},
		save: () => h( InnerBlocks.Content ),
	} );

	/* The page's blocks, with SwiftLing's texts mapped onto them. */

	const textsBy = {};
	const placed = new Set();
	data.texts.forEach( ( text ) => {
		( textsBy[ text.object ] = textsBy[ text.object ] || [] ).push( text );
	} );

	const parsePath = ( path ) => {
		const parts = path.split( '/' );
		if ( 'b' !== parts.shift() ) {
			return null;
		}
		const indices = [];
		while ( parts.length && /^\d+$/.test( parts[ 0 ] ) ) {
			indices.push( Number( parts.shift() ) );
		}
		if ( ! parts.length ) {
			return null;
		}
		if ( 'a' === parts[ 0 ] ) {
			return parts.length > 1 ? { indices, attrPath: parts.slice( 1 ) } : null;
		}
		const c = parts.shift().match( /^c(\d+)$/ );
		if ( ! c ) {
			return null;
		}
		const where = { indices, c: Number( c[ 1 ] ), attr: null, n: 0 };
		const attr = parts.length ? parts[ 0 ].match( /^@([a-z-]+)(\d+)$/ ) : null;
		if ( attr ) {
			where.attr = attr[ 1 ];
			where.n = Number( attr[ 2 ] );
		}
		return where;
	};

	const replaceNthAttribute = ( html, attr, n, value ) => {
		let seen = -1;
		return html.replace( new RegExp( '(\\s' + attr + '=)("[^"]*"|\'[^\']*\')', 'g' ), ( match, before ) => {
			seen++;
			return seen === n ? before + '"' + escapeAttr( value ) + '"' : match;
		} );
	};

	const rawTreeOf = ( object, content, depth ) => {
		const raw = rawParse( content );
		( textsBy[ object ] || [] ).forEach( ( text ) => {
			const where = parsePath( text.path );
			if ( ! where ) {
				return;
			}
			let node = raw[ where.indices[ 0 ] ];
			for ( let i = 1; node && i < where.indices.length; i++ ) {
				node = node.innerBlocks[ where.indices[ i ] ];
			}
			if ( ! node || ( undefined !== where.c && 'string' !== typeof node.innerContent[ where.c ] ) ) {
				return;
			}
			// A link address inside the HTML (href) has no control on the page: it stays a sidebar field.
			if ( where.attr && ! [ 'src', 'alt' ].includes( where.attr ) ) {
				return;
			}
			node.swl = node.swl || [];
			node.swl.push( { ...text, c: where.c, attr: where.attr, n: where.n, attrPath: where.attrPath || null, override: null } );
			placed.add( text.key );
		} );
		return raw.filter( ( node ) => node.blockName ).map( ( node ) => expand( node, depth ) );
	};

	const expand = ( node, depth ) => {
		node.innerBlocks = node.innerBlocks.map( ( inner ) => expand( inner, depth ) );
		const ref = 'core/block' === node.blockName ? Number( node.attrs.ref ) : 0;
		if ( ! ref || ! data.patterns[ ref ] || depth > 5 ) {
			return node;
		}
		const inner = rawTreeOf( 'post:' + ref, data.patterns[ ref ].content, depth + 1 );
		const overrides = node.attrs.content && 'object' === typeof node.attrs.content ? node.attrs.content : {};
		const units = ( node.swl || [] ).filter( ( unit ) => unit.attrPath && 'content' === unit.attrPath[ 0 ] );
		const apply = ( child ) => {
			const name = child.attrs && child.attrs.metadata && child.attrs.metadata.name;
			if ( name && overrides[ name ] && 'object' === typeof overrides[ name ] ) {
				child.swlOverride = overrides[ name ];
				child.swl = ( child.swl || [] ).filter( ( unit ) => ! replaced( unit, overrides[ name ] ) );
				Object.keys( overrides[ name ] ).forEach( ( attrName ) => {
					const unit = units.find( ( u ) => u.attrPath.length === 3 && u.attrPath[ 1 ] === name && u.attrPath[ 2 ] === attrName );
					if ( unit ) {
						child.swl.push( { ...unit, attrPath: null, override: attrName } );
					}
				} );
			}
			child.innerBlocks.forEach( apply );
		};
		inner.forEach( apply );
		return { blockName: 'swiftling/visual-pattern', attrs: { ref, title: data.patterns[ ref ].title }, innerBlocks: inner, innerHTML: '', innerContent: inner.map( () => null ) };
	};
	const replaced = ( unit, override ) => {
		if ( unit.attr ) {
			return ( 'src' === unit.attr && 'url' in override ) || ( 'alt' === unit.attr && 'alt' in override );
		}
		return ! unit.attrPath && ( 'content' in override || 'text' in override );
	};
	const cloneRaw = ( node ) => ( { blockName: node.blockName, attrs: { ...node.attrs }, innerBlocks: node.innerBlocks.map( cloneRaw ), innerHTML: node.innerHTML, innerContent: [ ...node.innerContent ] } );
	const fillRaw = ( node ) => {
		const copy = cloneRaw( node );
		( node.swl || [] ).forEach( ( unit ) => {
			if ( '' === unit.translation || unit.override || unit.attrPath ) {
				return;
			}
			copy.innerContent[ unit.c ] = unit.attr ? replaceNthAttribute( copy.innerContent[ unit.c ], unit.attr, unit.n, unit.translation ) : unit.translation;
		} );
		copy.innerBlocks = node.innerBlocks.map( fillRaw );
		return copy;
	};
	const annotate = ( block, original, node ) => {
		if ( node.swlOverride ) {
			Object.keys( node.swlOverride ).forEach( ( attrName ) => {
				block.attributes[ attrName ] = node.swlOverride[ attrName ];
				original.attributes[ attrName ] = node.swlOverride[ attrName ];
			} );
		}
		if ( node.swl && node.swl.length ) {
			node.swl.forEach( ( unit ) => {
				if ( '' === unit.translation ) {
					return;
				}
				if ( unit.override ) {
					block.attributes[ unit.override ] = unit.translation;
				} else if ( unit.attrPath ) {
					block.attributes = setPath( block.attributes, unit.attrPath, unit.translation );
				}
			} );
			const originalAttrs = { ...original.attributes };
			delete originalAttrs.swl;
			block.attributes.swl = {
				units: node.swl.map( ( unit ) => ( {
					key: unit.key, object: unit.object, path: unit.path, kind: unit.kind, type: unit.type, state: unit.state,
					attr: unit.attr, override: unit.override, attrPath: unit.attrPath,
					original: unit.attr || unit.override || unit.attrPath ? unit.original : node.innerContent[ unit.c ],
					translation: unit.translation,
					loadedRaw: unit.attr || unit.override || unit.attrPath ? null : '' === unit.translation ? node.innerContent[ unit.c ] : unit.translation,
				} ) ),
				originalAttrs,
				loadedSnap: '',
				copied: false,
			};
			block.attributes.swl.loadedSnap = snapshot( block.attributes );
		}
		block.innerBlocks.forEach( ( inner, i ) => {
			if ( original.innerBlocks[ i ] && node.innerBlocks[ i ] ) {
				annotate( inner, original.innerBlocks[ i ], node.innerBlocks[ i ] );
			}
		} );
	};
	const lockBlocks = ( blocks ) =>
		blocks.forEach( ( block ) => {
			block.attributes.lock = { move: true, remove: true };
			lockBlocks( block.innerBlocks );
		} );
	const buildBlocks = () => {
		const blocks = [];
		rawTreeOf( PAGE, data.post.content, 0 ).forEach( ( node ) => {
			const parsed = parse( serializeRawBlock( fillRaw( node ) ) );
			const original = parse( serializeRawBlock( cloneRaw( node ) ) );
			if ( parsed.length && original.length ) {
				// Translating never moves or removes a block.
				lockBlocks( parsed );
				annotate( parsed[ 0 ], original[ 0 ], node );
				blocks.push( parsed[ 0 ] );
			}
		} );
		return blocks;
	};

	const textOf = ( block, unit ) => {
		if ( 'src' === unit.attr ) {
			// The original image again: no translation of the address, rather than a copy of it.
			return block.attributes.url && block.attributes.url !== block.attributes.swl.originalAttrs.url ? block.attributes.url : '';
		}
		if ( 'alt' === unit.attr ) {
			return block.attributes.alt || '';
		}
		if ( unit.attr ) {
			return null;
		}
		if ( unit.override ) {
			return htmlString( block.attributes[ unit.override ] );
		}
		if ( unit.attrPath ) {
			return htmlString( getPath( block.attributes, unit.attrPath ) );
		}
		const around = unit.loadedRaw.match( /^(\s*)[\s\S]*?(\s*)$/ );
		return around[ 1 ] + getBlockContent( block ) + around[ 2 ];
	};
	const walkBlocks = ( blocks, callback ) => {
		const walk = ( block ) => {
			callback( block );
			block.innerBlocks.forEach( walk );
		};
		blocks.forEach( walk );
	};

	// The translations to save: every block whose text, picture or alt changed, or whose original
	// was copied; the title and the other texts that changed.
	const changedItems = ( blocks, title, others ) => {
		const items = [];
		const seen = new Set();
		walkBlocks( blocks, ( block ) => {
			const swl = block.attributes.swl;
			if ( swl && ( swl.copied || ! untouched( block.attributes ) ) ) {
				swl.units.forEach( ( unit ) => {
					const text = textOf( block, unit );
					if ( null !== text && ! seen.has( unit.key ) && ( ! swl.copied || untouched( block.attributes ) ? true : true ) ) {
						seen.add( unit.key );
						items.push( { key: unit.key, text, original: unit.original } );
					}
				} );
			}
		} );
		if ( title && title.dirty ) {
			items.push( { key: title.unit.key, text: title.value, original: title.unit.original } );
		}
		others.forEach( ( other ) => {
			if ( other.dirty ) {
				items.push( { key: other.unit.key, text: other.value, original: other.unit.original } );
			}
		} );
		return items;
	};

	// After a save: the blocks count as loaded again, with the states the server gave; a text of a
	// synced pattern saved from one instance shows in the other instances too.
	const settle = ( blocks, states ) => {
		const savedAttrs = {};
		walkBlocks( blocks, ( block ) => {
			const swl = block.attributes.swl;
			if ( swl && ( swl.copied || ! untouched( block.attributes ) ) ) {
				swl.units.forEach( ( unit ) => {
					if ( states[ unit.key ] ) {
						savedAttrs[ unit.key ] = block.attributes;
					}
				} );
			}
		} );
		walkBlocks( blocks, ( block ) => {
			const swl = block.attributes.swl;
			if ( ! swl ) {
				return;
			}
			if ( untouched( block.attributes ) && ! swl.copied ) {
				const saved = swl.units.map( ( unit ) => savedAttrs[ unit.key ] ).find( ( attrs ) => attrs && attrs !== block.attributes );
				if ( saved ) {
					const copy = { ...saved };
					delete copy.swl;
					block.attributes = { ...block.attributes, ...copy };
				}
			}
			swl.units.forEach( ( unit ) => {
				if ( states[ unit.key ] ) {
					unit.state = states[ unit.key ];
					const text = textOf( block, unit );
					if ( null !== text ) {
						unit.translation = text;
						if ( null !== unit.loadedRaw ) {
							unit.loadedRaw = text;
						}
					}
				}
			} );
			block.attributes = { ...block.attributes, swl: { ...swl, copied: false, loadedSnap: snapshot( block.attributes ) } };
		} );
		return blocks.map( ( block ) => ( { ...block } ) );
	};

	// Copies the original into every text without a translation: the blocks show it already; they
	// count as translated and are saved as they are.
	const copyOriginals = ( blocks ) => {
		let copied = 0;
		walkBlocks( blocks, ( block ) => {
			const swl = block.attributes.swl;
			if ( swl && ! translated( block.attributes ) ) {
				block.attributes = { ...block.attributes, swl: { ...swl, copied: true } };
				copied++;
			}
		} );
		return { blocks: blocks.map( ( block ) => ( { ...block } ) ), copied };
	};

	/* The screen. */

	const titleUnit = data.texts.find( ( text ) => 'field/post_title' === text.path ) || null;
	const progressOf = ( blocks, title, others ) => {
		let total = 0;
		let done = 0;
		let machine = 0;
		walkBlocks( blocks, ( block ) => {
			const swl = block.attributes.swl;
			if ( ! swl ) {
				return;
			}
			const counted = swl.units.filter( ( unit ) => 'url' !== unit.type );
			total += counted.length;
			done += translated( block.attributes ) ? counted.length : counted.filter( ( unit ) => DONE.includes( unit.state ) ).length;
			if ( untouched( block.attributes ) && ! swl.copied ) {
				machine += counted.filter( ( unit ) => 'machine' === unit.state ).length;
			}
		} );
		if ( title ) {
			total++;
			done += title.dirty || DONE.includes( title.state ) ? 1 : 0;
		}
		others.forEach( ( other ) => {
			if ( 'url' === other.unit.type ) {
				return;
			}
			total++;
			done += other.dirty || DONE.includes( other.unit.state ) ? 1 : 0;
		} );
		return { total, done, machine };
	};

	const Title = ( { title, setTitle } ) => {
		if ( ! title ) {
			return null;
		}
		const showsOriginal = ! title.dirty && MISSING.includes( title.state );
		return h(
			'div',
			{ className: 'swiftling-visual-title' + ( showsOriginal ? ' swiftling-visual-missing' : '' ) + ( title.dirty ? ' swiftling-visual-state-edited' : ' swiftling-visual-state-' + title.state ), 'data-swiftling-visual-state': title.dirty ? __( 'Changed, not saved', 'swiftling' ) : __( 'Page title', 'swiftling' ) + ' · ' + stateLabel( title.state ) },
			h( PlainText, { className: 'swiftling-visual-title-input', value: title.value, onChange: ( value ) => setTitle( { ...title, value, dirty: true } ), __experimentalVersion: 2, tagName: 'h1', 'aria-label': /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Page title in %s', 'swiftling' ), LANG ) } )
		);
	};

	const Card = ( { title, className, children } ) => h( 'section', { className: 'swiftling-card swiftling-side' + ( className ? ' ' + className : '' ) }, title && h( 'h2', null, title ), children );

	// The card at the top of the sidebar: the chosen block's original, or how the screen works.
	const SelectedCard = () => {
		const block = useSelect( ( select ) => {
			const id = select( blockEditorStore ).getSelectedBlockClientId();
			return id ? select( blockEditorStore ).getBlock( id ) : null;
		}, [] );
		const { updateBlockAttributes } = useDispatch( blockEditorStore );
		const swl = block && block.attributes.swl;
		if ( ! swl ) {
			return h(
				Card,
				{ className: 'swiftling-visual-selected is-empty' },
				h( 'span', { className: 'swiftling-visual-selected__icon dashicons dashicons-translation', 'aria-hidden': true } ),
				h( 'h2', null, __( 'Translate on the page', 'swiftling' ) ),
				h(
					'ol',
					{ className: 'swiftling-visual-steps' },
					h( 'li', null, __( 'Click a text on the page and type over it. Greyed texts still show the original.', 'swiftling' ) ),
					h( 'li', null, __( 'Its original shows here while you type. Bold, links, highlights and colours work as in the block editor.', 'swiftling' ) ),
					h( 'li', null, __( 'Click an image to give this language its own image or alt text.', 'swiftling' ) )
				)
			);
		}
		const isImage = !! attrUnitOf( swl, 'src' );
		return h(
			Card,
			{ className: 'swiftling-visual-selected', title: isImage ? /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Image in %s', 'swiftling' ), LANG ) : blockTitle( block.name ) },
			h( OriginalPanel, { key: block.clientId, name: block.name, attributes: block.attributes, setAttributes: ( attrs ) => updateBlockAttributes( block.clientId, attrs ), width: 340 } )
		);
	};

	const AddressCard = ( { address, setAddress, note } ) => {
		const [ slug, setSlug ] = useState( address ? address.slug : '' );
		const [ saving, setSaving ] = useState( false );
		if ( ! address ) {
			return null;
		}
		const saveSlug = async ( event ) => {
			event.preventDefault();
			setSaving( true );
			try {
				const result = await apiFetch( { path: 'swiftling/v1/slug', method: 'POST', data: { subject: PAGE, lang: data.lang, slug } } );
				setAddress( { ...address, ...result, slug: decodeURIComponent( result.slug || '' ) } );
				setSlug( decodeURIComponent( result.slug || '' ) );
				note( __( 'The address is saved.', 'swiftling' ), 'success' );
			} catch ( error ) {
				note( error && error.message ? error.message : __( 'The address could not be saved.', 'swiftling' ), 'error' );
			}
			setSaving( false );
		};
		let body;
		if ( ! address.directories ) {
			body = h( 'p', { className: 'swiftling-live-frame' }, __( 'Translated addresses need pretty permalinks: with plain ones, addresses name pages by their number.', 'swiftling' ) );
		} else if ( '' === address.original ) {
			body = h( 'p', { className: 'swiftling-live-frame' }, __( 'This page gets its address when it is published. Then it can have one in every language.', 'swiftling' ) );
		} else {
			body = h(
				'form',
				{ className: 'swiftling-address__form', onSubmit: saveSlug },
				h( 'label', { htmlFor: 'swiftling-visual-slug' }, __( 'Slug', 'swiftling' ) ),
				h( 'code', { className: 'swiftling-address__prefix swiftling-mono' }, address.prefix ),
				h(
					'span',
					{ className: 'swiftling-address__field' },
					h( 'input', { type: 'text', id: 'swiftling-visual-slug', className: 'swiftling-mono', value: slug, placeholder: address.original, autoComplete: 'off', spellCheck: false, onChange: ( event ) => setSlug( event.target.value ) } ),
					h( SButton, { type: 'submit', busy: saving, disabled: saving || slug === address.slug }, __( 'Save', 'swiftling' ) )
				),
				h( 'p', { className: 'swiftling-muted' }, 'machine' === address.state ? __( 'Made from the translated title. Change it if you like.', 'swiftling' ) : __( 'Empty: the original slug. A changed address keeps leading here from the old one.', 'swiftling' ) ),
				address.url && h( 'a', { className: 'swiftling-address__url swiftling-mono', href: address.url, target: '_blank', rel: 'noopener' }, decodeURIComponent( address.url ) )
			);
		}
		return h( Card, { className: 'swiftling-address', title: /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Address in %s', 'swiftling' ), LANG ) }, body );
	};

	const WaysCard = () => {
		if ( ! data.mode || ! data.mode.canChoose ) {
			return null;
		}
		const way = ( mode, title, text ) =>
			h(
				'form',
				{
					method: 'post',
					action: data.mode.action,
					onSubmit: ( event ) => {
						if ( ! window.confirm( __( 'Change how this language shows the page? Translations that are not saved are lost.', 'swiftling' ) ) ) {
							event.preventDefault();
						}
					},
				},
				h( 'input', { type: 'hidden', name: 'action', value: 'swiftling_language_mode' } ),
				h( 'input', { type: 'hidden', name: 'post', value: data.post.id } ),
				h( 'input', { type: 'hidden', name: 'lang', value: data.lang } ),
				h( 'input', { type: 'hidden', name: 'mode', value: mode } ),
				h( 'input', { type: 'hidden', name: '_wpnonce', value: data.mode.nonce } ),
				h( 'button', { type: 'submit', className: 'swiftling-way' }, h( 'strong', null, title ), h( 'span', null, text ) )
			);
		return h(
			Card,
			{ title: /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'How %s shows this page', 'swiftling' ), LANG ) },
			h(
				'div',
				{ className: 'swiftling-ways' },
				h( 'p', { className: 'swiftling-way is-current', 'aria-current': 'true' }, h( 'strong', null, __( 'Translated here', 'swiftling' ) ), h( 'span', null, __( 'The texts of this page, in this editor.', 'swiftling' ) ) ),
				data.mode.canCopy && way( 'manual', __( 'Separate copy', 'swiftling' ), __( 'A different layout, changed by hand in its own editor. It starts with the translations made here.', 'swiftling' ) ),
				way( 'duplicate', __( 'Keep the original', 'swiftling' ), /* translators: 1: language of the visitors, 2: language of the original. */ sprintf( __( 'Visitors in %1$s see this page in %2$s, as it is. It counts as done, so Translate everything skips it. The translations made here are kept.', 'swiftling' ), LANG, SOURCE ) )
			)
		);
	};

	// Texts with no place on the page: link addresses, code, excerpt and SEO fields.
	const OtherField = ( { other, onChange } ) => {
		const unit = other.unit;
		const isUrl = 'url' === unit.type;
		const state = other.dirty ? 'edited' : unit.state;
		const id = 'swiftling-visual-other-' + unit.key;
		return h(
			'div',
			{ className: 'swiftling-visual-field' + ( ! other.dirty && MISSING.includes( unit.state ) ? ' is-missing' : '' ) },
			h( 'label', { htmlFor: id }, h( StatusIcon, { state } ), unit.kind || unit.type || __( 'Text', 'swiftling' ) ),
			h( 'p', { className: 'swiftling-visual-source' }, h( 'span', { className: 'swiftling-visual-source__label' }, SOURCE ), unit.original ),
			h( isUrl ? 'input' : 'textarea', { id, type: isUrl ? 'text' : undefined, rows: isUrl ? undefined : 2, className: isUrl ? 'swiftling-mono' : undefined, value: other.value, placeholder: unit.original, onChange: ( event ) => onChange( event.target.value ) } ),
			other.value !== unit.original && h( SButton, { variant: 'link', className: 'swiftling-visual-use-original', onClick: () => onChange( unit.original ) }, __( 'Use the original', 'swiftling' ) )
		);
	};

	const Sidebar = ( { others, setOthers, address, setAddress, note } ) =>
		h(
			'aside',
			{ className: 'swiftling-editor-layout__aside swiftling-visual-aside' },
			h( SelectedCard ),
			others.length > 0 &&
				h(
					Card,
					{ title: __( 'Texts outside the page', 'swiftling' ) },
					h( 'p', { className: 'swiftling-live-frame' }, __( 'They have no place on the page itself: link addresses, code, the excerpt and SEO fields.', 'swiftling' ) ),
					others.map( ( other, index ) => h( OtherField, { key: other.unit.key, other, onChange: ( value ) => setOthers( others.map( ( o, i ) => ( i === index ? { ...o, value, dirty: true } : o ) ) ) } ) )
				),
			h( AddressCard, { address, setAddress, note } ),
			h( WaysCard ),
			data.frame &&
				h(
					Card,
					{ title: __( 'Around this page', 'swiftling' ) },
					h( 'p', { className: 'swiftling-live-frame' }, /* translators: %s: name of a template, e.g. Pages. */ sprintf( __( 'The header, footer, menu and site title around this page are translated once for the whole site, in the template “%s”.', 'swiftling' ), data.frame.title ) ),
					h( SButton, { href: data.frame.url }, __( 'Translate the template', 'swiftling' ) )
				),
			h(
				Card,
				{ title: __( 'A text is missing?', 'swiftling' ) },
				h( 'p', { className: 'swiftling-live-frame' }, __( 'When a plugin or the theme shows it, tell us which one: we test it and add it to SwiftLing.', 'swiftling' ) ),
				h( SButton, { href: data.links.request }, __( 'Request support', 'swiftling' ) )
			),
			h(
				Card,
				{ title: __( 'States', 'swiftling' ) },
				h(
					'ul',
					{ className: 'swiftling-side__states' },
					[
						[ 'reviewed', __( 'Reviewed', 'swiftling' ) ],
						[ 'machine', __( 'Machine translated, not reviewed yet', 'swiftling' ) ],
						[ 'official', __( 'Translated by WordPress, the theme or the plugin', 'swiftling' ) ],
						[ 'outdated', __( 'The original changed since it was translated', 'swiftling' ) ],
						[ 'missing', __( 'Not translated yet: visitors read the original', 'swiftling' ) ],
					].map( ( [ state, label ] ) => h( 'li', { key: state }, h( StatusIcon, { state } ), label ) )
				)
			)
		);

	const Screen = () => {
		const [ blocks, setBlocks ] = useState( () => buildBlocks() );
		const [ title, setTitle ] = useState( () => ( titleUnit ? { unit: titleUnit, value: '' === titleUnit.translation ? titleUnit.original : titleUnit.translation, state: titleUnit.state, dirty: false } : null ) );
		const [ others, setOthers ] = useState( () => data.texts.filter( ( text ) => ! placed.has( text.key ) && text !== titleUnit && ! text.follows ).map( ( unit ) => ( { unit, value: unit.translation, dirty: false } ) ) );
		const [ address, setAddress ] = useState( data.address );
		const [ saving, setSaving ] = useState( false );
		const [ notice, setNotice ] = useState( null );
		const [ highlight, setHighlight ] = useState( false );
		const [ machining, setMachining ] = useState( false );
		const note = ( text, status ) => setNotice( text ? { text, status } : null );
		// The bar stays at the top; the canvas and the sidebar fit under it.
		const barRef = useRef( null );
		useEffect( () => {
			const el = barRef.current;
			if ( ! el || ! window.ResizeObserver ) {
				return;
			}
			const observer = new window.ResizeObserver( () => document.documentElement.style.setProperty( '--swiftling-visual-bar', el.offsetHeight + 'px' ) );
			observer.observe( el );
			return () => observer.disconnect();
		}, [] );
		// A success message goes after a while; an error stays until the next action.
		useEffect( () => {
			if ( ! notice || 'error' === notice.status || 'warning' === notice.status ) {
				return;
			}
			const timer = setTimeout( () => setNotice( null ), 6000 );
			return () => clearTimeout( timer );
		}, [ notice ] );

		const settings = useMemo( () => ( { ...data.settings, templateLock: 'contentOnly', focusMode: false, hasFixedToolbar: false, canLockBlocks: false, codeEditingEnabled: false, mediaUpload: wp.mediaUtils && wp.mediaUtils.uploadMedia ? ( args ) => wp.mediaUtils.uploadMedia( args ) : undefined } ), [] );
		const styles = useMemo( () => [ ...( data.settings.styles || [] ), { css: CANVAS_CSS } ], [] );

		const items = changedItems( blocks, title, others );
		const progress = progressOf( blocks, title, others );

		const save = useCallback( async ( state ) => {
			setSaving( true );
			setNotice( null );
			try {
				const result = await apiFetch( { path: 'swiftling/v1/translations', method: 'POST', data: { subject: PAGE, lang: data.lang, status: state, items } } );
				const states = result.states || {};
				setBlocks( settle( blocks, states ) );
				if ( title && title.dirty ) {
					setTitle( { ...title, dirty: false, state: states[ title.unit.key ] || state } );
				}
				setOthers( others.map( ( other ) => ( other.dirty ? { ...other, dirty: false, unit: { ...other.unit, state: states[ other.unit.key ] || state, translation: other.value } } : other ) ) );
				const refused = ( result.refused || [] ).length;
				note( refused ? /* translators: %d: number of texts. */ sprintf( _n( 'Saved, but %d text was refused: its placeholders do not fit the original.', 'Saved, but %d texts were refused: their placeholders do not fit the original.', refused, 'swiftling' ), refused ) : /* translators: %d: number of texts. */ sprintf( _n( 'Saved %d text.', 'Saved %d texts.', items.length, 'swiftling' ), items.length ), refused ? 'warning' : 'success' );
				if ( result.address && result.address.url ) {
					setAddress( ( current ) => ( current ? { ...current, ...result.address, slug: decodeURIComponent( result.address.slug || '' ) } : current ) );
				}
			} catch ( error ) {
				note( error && error.message ? error.message : __( 'Saving failed.', 'swiftling' ), 'error' );
			}
			setSaving( false );
		}, [ blocks, title, others, items ] );

		const machineFill = async () => {
			if ( items.length && ! window.confirm( __( 'Unsaved changes are kept in the browser, but the page loads again after the translation. Save first?', 'swiftling' ) ) ) {
				return;
			}
			setSaving( true );
			setMachining( true );
			note( /* translators: %s: translation service name, e.g. Google. */ sprintf( __( 'Translating the empty texts with %s…', 'swiftling' ), data.machine.service ), 'info' );
			try {
				const result = await apiFetch( { path: 'swiftling/v1/fill', method: 'POST', data: { subject: PAGE, lang: data.lang } } );
				if ( result.error ) {
					note( result.error, 'error' );
					setSaving( false );
					setMachining( false );
					return;
				}
				window.onbeforeunload = null;
				window.location.reload();
			} catch ( error ) {
				note( error && error.message ? error.message : __( 'The translation stopped.', 'swiftling' ), 'error' );
				setSaving( false );
				setMachining( false );
			}
		};
		const copyAll = () => {
			const result = copyOriginals( blocks );
			let copied = result.copied;
			setBlocks( result.blocks );
			if ( title && ! title.dirty && MISSING.includes( title.state ) ) {
				setTitle( { ...title, dirty: true } );
				copied++;
			}
			setOthers( others.map( ( other ) => {
				if ( ! other.dirty && MISSING.includes( other.unit.state ) && '' === other.value ) {
					copied++;
					return { ...other, value: other.unit.original, dirty: true };
				}
				return other;
			} ) );
			note( copied ? /* translators: %d: number of texts. */ sprintf( _n( 'The original is in %d empty text now. Change what needs changing, then save.', 'The original is in %d empty texts now. Change what needs changing, then save.', copied, 'swiftling' ), copied ) : __( 'Every text is filled in.', 'swiftling' ), 'info' );
		};

		useEffect( () => {
			const warn = ( event ) => {
				if ( items.length ) {
					event.preventDefault();
					event.returnValue = '';
				}
			};
			window.addEventListener( 'beforeunload', warn );
			return () => window.removeEventListener( 'beforeunload', warn );
		}, [ items.length ] );
		// "Highlight empty fields": a stylesheet in the canvas.
		useEffect( () => {
			const frame = document.querySelector( '.swiftling-visual-canvas iframe[name="editor-canvas"]' );
			const doc = frame && frame.contentDocument;
			if ( ! doc ) {
				return;
			}
			let style = doc.getElementById( 'swiftling-visual-highlight' );
			if ( highlight && ! style ) {
				style = doc.createElement( 'style' );
				style.id = 'swiftling-visual-highlight';
				style.textContent = '.swiftling-visual-missing, .swiftling-visual-missing-media { outline: 2px solid #e8552d !important; outline-offset: 4px; border-radius: 2px; opacity: .85; }';
				doc.head.appendChild( style );
			} else if ( ! highlight && style ) {
				style.remove();
			}
		}, [ highlight ] );

		// Leaving with unsaved changes: ask first.
		const leave = ( event ) => {
			if ( items.length && ! window.confirm( __( 'Some translations are not saved yet. Leave anyway?', 'swiftling' ) ) ) {
				event.preventDefault();
			}
		};

		const percent = ( part ) => ( progress.total ? Math.round( ( 1000 * part ) / progress.total ) / 10 : 0 ) + '%';
		const empty = progress.total - progress.done;
		const viewIn = address && address.url ? address.url : data.post.viewIn;

		const bar = h(
			'div',
			{ className: 'swiftling-editor-bar swiftling-visual-bar', ref: barRef },
			h( SButton, { variant: 'link is-back', href: data.links.back, icon: 'arrow-left-alt2' }, __( 'Translations', 'swiftling' ) ),
			h(
				'div',
				{ className: 'swiftling-editor-bar__title' },
				h( 'h1', null, data.post.title || __( '(no title)', 'swiftling' ) ),
				h( 'span', { className: 'swiftling-muted' }, data.post.type ? /* translators: %s: kind of content, e.g. Page. */ sprintf( __( '%s · visual editor', 'swiftling' ), data.post.type ) : __( 'Visual editor', 'swiftling' ) )
			),
			h(
				'div',
				{ className: 'swiftling-editor-bar__actions' },
				data.post.url && h( SButton, { variant: 'ghost', href: data.post.url, target: '_blank', rel: 'noopener', icon: 'visibility' }, __( 'View original', 'swiftling' ) ),
				viewIn && h( SButton, { variant: 'ghost', href: viewIn, target: '_blank', rel: 'noopener', icon: 'visibility' }, /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'View in %s', 'swiftling' ), LANG ) ),
				data.links.table && h( SButton, { variant: 'ghost', href: data.links.table, icon: 'editor-table', onClick: leave, title: __( 'The texts of this page as a list: original on the left, translation on the right.', 'swiftling' ) }, __( 'Table view', 'swiftling' ) ),
				h( 'p', { className: 'swiftling-live-note' + ( notice ? ' is-' + ( 'warning' === notice.status ? 'error' : notice.status ) : '' ), role: 'status' }, notice ? notice.text : saving ? __( 'Saving…', 'swiftling' ) : '' ),
				! notice && h( 'span', { className: 'swiftling-live-unsaved', 'aria-live': 'polite' }, items.length ? /* translators: %d: number of texts. */ sprintf( _n( '%d change', '%d changes', items.length, 'swiftling' ), items.length ) : '' ),
				h( SButton, { onClick: () => save( 'machine' ), disabled: saving || ! items.length, title: __( 'Visitors read it, and it stays marked for review, as a machine translation does.', 'swiftling' ) }, __( 'Save, review later', 'swiftling' ) ),
				h( SButton, { variant: 'accent', className: 'swiftling-live-save', onClick: () => save( 'reviewed' ), disabled: saving || ! items.length, busy: saving }, __( 'Save and mark reviewed', 'swiftling' ) )
			)
		);

		const languages = h(
			'nav',
			{ className: 'swiftling-segmented swiftling-editor-languages', 'aria-label': __( 'Language', 'swiftling' ) },
			data.targets.map( ( target ) => {
				const current = target.slug === data.lang;
				// The open language counts as it is now, unsaved changes included.
				const counts = current ? { translated: progress.done, total: progress.total } : target.counts;
				return h(
					'a',
					{ key: target.slug, href: target.url, className: current ? 'is-current' : '', 'aria-current': current ? 'page' : undefined, title: target.name, onClick: current ? ( event ) => event.preventDefault() : leave },
					h( StatusIcon, { state: target.state } ),
					h( 'span', { className: 'swiftling-segmented__code' }, target.code ),
					counts && counts.total
						? h(
								'span',
								{ className: 'swiftling-segmented__progress' },
								h( 'span', { className: 'swiftling-segmented__count' }, counts.translated + '/' + counts.total ),
								h( 'span', { className: 'swiftling-bar swiftling-segmented__meter', 'aria-hidden': true }, h( 'span', { className: 'is-reviewed', style: { width: Math.round( ( 100 * counts.translated ) / counts.total ) + '%' } } ) )
						  )
						: 'translation' !== target.mode && h( 'span', { className: 'swiftling-segmented__count' }, 'duplicate' === target.mode ? __( 'keeps the original', 'swiftling' ) : __( 'separate copy', 'swiftling' ) )
				);
			} )
		);

		const machineTool = data.machine
			? h( SButton, { variant: 'secondary is-strong', className: 'swiftling-live-machine', onClick: machineFill, disabled: saving || !! data.machine.off || ! empty, busy: machining, title: data.machine.off || undefined }, /* translators: %s: translation service name, e.g. Google. */ sprintf( __( 'Translate empty texts with %s', 'swiftling' ), data.machine.service ) )
			: h( SButton, { href: data.links.service, icon: 'translation' }, __( 'Set up machine translation', 'swiftling' ) );

		const head = h(
			'div',
			{ className: 'swiftling-live-head' },
			h(
				'div',
				{ className: 'swiftling-live-head__title' },
				h( 'h2', null, LANG ),
				h( 'p', { className: 'swiftling-live-summary', 'aria-live': 'polite' }, empty ? /* translators: 1: texts translated, 2: all texts, 3: texts still empty. */ sprintf( _n( '%1$d of %2$d text translated · %3$d still empty', '%1$d of %2$d texts translated · %3$d still empty', progress.total, 'swiftling' ), progress.done, progress.total, empty ) : __( 'Every text is translated.', 'swiftling' ) )
			),
			h( 'div', { className: 'swiftling-bar swiftling-live-meter', 'aria-hidden': true }, h( 'span', { className: 'is-reviewed', style: { width: percent( progress.done - progress.machine ) } } ), h( 'span', { className: 'is-machine', style: { width: percent( progress.machine ) } } ) ),
			h(
				'div',
				{ className: 'swiftling-visual-head-tools' },
				h(
					'div',
					{ className: 'swiftling-live-fill', role: 'group', 'aria-labelledby': 'swiftling-visual-fill-label' },
					h( 'span', { className: 'swiftling-live-toolbar__label', id: 'swiftling-visual-fill-label' }, __( 'Fill empty texts', 'swiftling' ) ),
					machineTool,
					h( SButton, { onClick: copyAll, disabled: saving || ! empty }, __( 'Copy the original into empty texts', 'swiftling' ) )
				),
				h( 'button', { type: 'button', className: 'swiftling-live-switch', 'aria-pressed': highlight ? 'true' : 'false', onClick: () => setHighlight( ! highlight ), disabled: ! empty }, __( 'Highlight empty texts', 'swiftling' ) )
			)
		);

		return h(
			SlotFillProvider,
			null,
			h(
				BlockEditorProvider,
				{ value: blocks, onInput: setBlocks, onChange: setBlocks, settings },
				h( BlockEditorKeyboardShortcuts.Register ),
				h( BlockEditorKeyboardShortcuts ),
				bar,
				languages,
				h(
					'div',
					{ className: 'swiftling-editor-layout swiftling-visual-layout' },
					h(
						'div',
						{ className: 'swiftling-card is-flush swiftling-live-editor swiftling-visual-editor' + ( highlight ? ' is-marking-empty' : '' ) },
						head,
						h( 'div', { className: 'swiftling-visual-canvas' }, h( BlockCanvas, { height: '100%', styles }, h( Title, { title, setTitle } ), h( BlockList ) ) )
					),
					h( Sidebar, { others, setOthers, address, setAddress, note } )
				),
				h( Popover.Slot )
			)
		);
	};

	// Inside the canvas (an iframe with the theme's styles): placeholders, states and markers, in
	// SwiftLing's colours.
	const CANVAS_CSS = `
		.swiftling-visual-missing { opacity: .42; transition: opacity .15s ease; }
		.swiftling-visual-missing:hover, .swiftling-visual-missing.is-selected, .swiftling-visual-missing:focus-within { opacity: .85; }
		.swiftling-visual-missing-media { outline: 2px dashed #ddd5c7; outline-offset: 3px; }
		.swiftling-visual-unit, .swiftling-visual-title { position: relative; }
		.swiftling-visual-unit.is-hovered:not(.is-selected) { outline: 1px solid rgba(18,24,38,.18); outline-offset: 4px; border-radius: 2px; }
		.swiftling-visual-unit::after, .swiftling-visual-title::after { content: attr(data-swiftling-visual-state); position: absolute; right: 0; bottom: 0; transform: translateY(calc(100% + 6px)); z-index: 3; font: 600 11px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; letter-spacing: normal; text-transform: none; white-space: nowrap; padding: 2px 9px; border-radius: 999px; background: #efeae1; color: #5b6272; box-shadow: 0 1px 2px rgba(18,24,38,.08); opacity: 0; pointer-events: none; transition: opacity .15s ease; }
		/* The block editor draws a selected image's frame with ::after, stretched over the whole block:
		   the label must not take that geometry, or it becomes a disc over the image. */
		.swiftling-visual-unit::after, .swiftling-visual-title::after, .swiftling-visual-own-image::before { top: auto !important; left: auto !important; right: auto !important; bottom: auto !important; width: auto !important; height: auto !important; min-width: 0 !important; min-height: 0 !important; max-width: 90%; overflow: hidden; text-overflow: ellipsis; border: 0 !important; outline: 0 !important; }
		.swiftling-visual-unit::after, .swiftling-visual-title::after { content: attr(data-swiftling-visual-state) !important; padding: 2px 9px !important; border-radius: 999px !important; font: 600 11px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important; right: 0 !important; bottom: 0 !important; box-shadow: 0 1px 2px rgba(18,24,38,.08) !important; }
		.swiftling-visual-own-image::before { left: 10px !important; top: 10px !important; }
		.swiftling-visual-unit.is-selected:not(.has-editable-outline) { outline: 2px solid var(--wp-admin-theme-color, #3858e9); outline-offset: 2px; }
		.swiftling-visual-unit:hover::after, .swiftling-visual-unit.is-selected::after, .swiftling-visual-title:hover::after, .swiftling-visual-title:focus-within::after { opacity: 1; }
		.swiftling-visual-state-machine::after { background: #e8edfd; color: #3b5bdb; }
		.swiftling-visual-state-outdated::after { background: #fbebd3; color: #8a5608; }
		.swiftling-visual-state-missing::after { background: #fdeee8; color: #b4441f; }
		.swiftling-visual-state-reviewed::after, .swiftling-visual-state-official::after { background: #e3f4ea; color: #1e8e5a; }
		.swiftling-visual-state-edited::after { background: #121826; color: #fff; }
		.swiftling-visual-own-image::before { content: attr(data-swiftling-visual-image); position: absolute; left: 10px; top: 10px; z-index: 2; font: 600 11px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; padding: 2px 10px; border-radius: 999px; background: #1e8e5a; color: #fff; box-shadow: 0 1px 2px rgba(18,24,38,.2); pointer-events: none; }
		.swiftling-visual-pattern { position: relative; outline: 1px dashed rgba(91,98,114,.35); outline-offset: 4px; border-radius: 2px; margin-top: 28px; }
		.swiftling-visual-pattern::before { content: attr(data-swiftling-visual-title); position: absolute; top: -4px; left: -4px; transform: translateY(-100%); z-index: 2; font: 600 10px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; letter-spacing: .04em; text-transform: uppercase; color: #5b6272; background: #efeae1; padding: 1px 8px; border-radius: 6px 6px 0 0; }
		.swiftling-visual-title { margin: 0 auto 1.5em; max-width: var(--wp--style--global--content-size, 760px); }
		.swiftling-visual-title .swiftling-visual-title-input { width: 100%; font: inherit; font-size: var(--wp--preset--font-size--xx-large, 2.4em); font-weight: inherit; line-height: 1.15; margin: 0; border: 0; background: transparent; padding: .2em 0; box-shadow: none; resize: none; color: inherit; }
		.swiftling-visual-title.swiftling-visual-missing .swiftling-visual-title-input { opacity: .42; }
	`;

	const root = document.getElementById( 'swiftling-visual' );
	if ( root ) {
		createRoot( root ).render( h( Screen ) );
		// The core image block's own "Replace" (and its Upload) would read as a change of the page
		// itself; the language's image has its own, named control. The core control has no class
		// of its own, so it is hidden by its label whenever a toolbar renders.
		const replaceLabel = __( 'Replace' ) /* WordPress's own label. */;
		const hideCoreReplace = () => {
			root.querySelectorAll( '.block-editor-block-toolbar button' ).forEach( ( button ) => {
				if ( button.textContent.trim() === replaceLabel && ! button.closest( '.swiftling-visual-image' ) ) {
					const dropdown = button.closest( '.components-dropdown' ) || button;
					dropdown.style.display = 'none';
				}
			} );
		};
		new MutationObserver( hideCoreReplace ).observe( root, { childList: true, subtree: true } );
	}
} )();
