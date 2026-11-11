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
	const { parse, serialize, serializeRawBlock, registerBlockType, createBlock } = wp.blocks;
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
	// Whether a text has nothing but tags and spaces.
	const isBlank = ( html ) => '' === String( html || '' ).replace( /<[^>]*>|&nbsp;|[\s\u200B\uFEFF]/g, '' );

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
	// The attributes of a block's image: an image's and a cover's url, id and alt; Media & Text's
	// mediaUrl, mediaId and mediaAlt.
	const mediaKeys = ( name ) => ( 'core/media-text' === name ? { url: 'mediaUrl', id: 'mediaId', alt: 'mediaAlt' } : { url: 'url', id: 'id', alt: 'alt' } );
	const imageOf = ( name, attributes ) => {
		const keys = mediaKeys( name );
		return { url: attributes[ keys.url ], id: attributes[ keys.id ], alt: attributes[ keys.alt ] };
	};
	const imageAttributes = ( name, image ) => {
		const keys = mediaKeys( name );
		const attributes = {};
		[ 'url', 'id', 'alt' ].filter( ( key ) => key in image ).forEach( ( key ) => {
			attributes[ keys[ key ] ] = image[ key ];
		} );
		return attributes;
	};
	const ownImage = ( name, attributes ) => {
		if ( ! attributes.swl || ! attrUnitOf( attributes.swl, 'src' ) ) {
			return false;
		}
		const now = imageOf( name, attributes ).url;
		const before = imageOf( name, attributes.swl.originalAttrs ).url;
		return !! now && !! before && now !== before;
	};

	/* Filters: before the core blocks are registered. */

	// Translating changes texts and images, never which blocks the page has: no block turns into
	// another as a text is typed ("## " into a heading), and none is added by Enter or a "/".
	addFilter( 'blocks.registerBlockType', 'swiftling/visual-attributes', ( settings ) => ( {
		...settings,
		attributes: { ...( settings.attributes || {} ), swl: { type: 'object' } },
		transforms: settings.transforms ? { ...settings.transforms, from: ( settings.transforms.from || [] ).filter( ( transform ) => ! [ 'prefix', 'enter' ].includes( transform.type ) ) } : settings.transforms,
	} ) );
	addFilter( 'editor.Autocomplete.completers', 'swiftling/visual-no-inserter', ( completers ) => completers.filter( ( completer ) => 'blocks' !== completer.name ) );
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
		if ( ownImage( props.name, props.attributes ) ) {
			classes.push( 'swiftling-visual-own-image' );
			wrapperProps[ 'data-swiftling-visual-image' ] = /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( '%s image', 'swiftling' ), LANG );
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
			title: /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Choose the image for %s', 'swiftling' ), LANG ),
			library: { type: 'image' },
			multiple: false,
			button: { text: /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Use for %s', 'swiftling' ), LANG ) },
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

	// The original of the chosen block, in the panel under the page: for a text, the text as the
	// page shows it; for an image, the original's and this language's, and the alt text.
	const OriginalPanel = ( { name, attributes, setAttributes } ) => {
		const swl = attributes.swl;
		const alt = attrUnitOf( swl, 'alt' );
		const src = attrUnitOf( swl, 'src' );
		const shared = swl.units.some( ( unit ) => unit.object !== PAGE );
		const original = useMemo( () => [ createBlock( name, { ...swl.originalAttrs } ) ], [ name, swl.originalAttrs ] );
		const own = ownImage( name, attributes );
		const image = imageOf( name, attributes );
		const originalImage = imageOf( name, swl.originalAttrs );
		const changed = ! untouched( attributes ) || swl.copied;
		const stateOf = ( unit ) => ( changed ? __( 'changed, not saved yet', 'swiftling' ) : 'src' === unit.attr && MISSING.includes( unit.state ) ? __( 'the original’s', 'swiftling' ) : stateLabel( unit.state ) );
		const head = h(
			'div',
			{ className: 'swiftling-visual-dock__head' },
			h( 'strong', { className: 'swiftling-visual-dock__title' }, src ? /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Image in %s', 'swiftling' ), LANG ) : /* translators: 1: kind of block, e.g. Heading, 2: language of the original. */ sprintf( __( '%1$s · original in %2$s', 'swiftling' ), blockTitle( name ), SOURCE ) ),
			h( 'ul', { className: 'swiftling-visual-unit-states' }, swl.units.map( ( unit ) => h( 'li', { key: unit.key }, h( StatusIcon, { state: changed ? 'edited' : unit.state } ), swl.units.length > 1 ? unitKind( unit ) + ' · ' + stateOf( unit ) : stateOf( unit ) ) ) ),
			shared && h( 'span', { className: 'swiftling-visual-dock__shared', title: __( 'Translated once for every page that uses this pattern.', 'swiftling' ) }, h( 'span', { className: 'dashicons dashicons-controls-repeat', 'aria-hidden': true } ), __( 'Synced pattern', 'swiftling' ) ),
			! src && translated( attributes ) && h( SButton, { variant: 'link', className: 'swiftling-visual-dock__action', onClick: () => setAttributes( { ...swl.originalAttrs } ) }, __( 'Use the original', 'swiftling' ) )
		);
		if ( ! src ) {
			return h( Fragment, null, head, h( 'div', { className: 'swiftling-visual-dock__body swiftling-visual-original-preview' }, h( BlockPreview, { blocks: original, viewportWidth: 900 } ) ) );
		}
		return h(
			Fragment,
			null,
			head,
			h(
				'div',
				{ className: 'swiftling-visual-dock__body swiftling-visual-dock__image' },
				h(
					'div',
					{ className: 'swiftling-visual-image-pair' },
					h( 'figure', null, h( 'img', { src: src.original || originalImage.url, alt: '' } ), h( 'figcaption', null, __( 'Original', 'swiftling' ) ) ),
					h(
						'figure',
						{ className: own ? 'is-own' : 'is-same' },
						own ? h( 'img', { src: image.url, alt: '' } ) : h( 'span', { className: 'swiftling-visual-image-same' }, __( 'Same as the original', 'swiftling' ) ),
						h( 'figcaption', null, LANG )
					)
				),
				h(
					'div',
					{ className: 'swiftling-visual-dock__fields' },
					h(
						'div',
						{ className: 'swiftling-visual-actions' },
						h( SButton, { icon: 'format-image', onClick: () => chooseImage( ( chosen ) => setAttributes( imageAttributes( name, { url: chosen.url, id: chosen.id } ) ) ) }, own ? __( 'Choose another image', 'swiftling' ) : /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Use a different image for %s', 'swiftling' ), LANG ) ),
						own && h( SButton, { variant: 'link', onClick: () => setAttributes( imageAttributes( name, { url: originalImage.url, id: originalImage.id } ) ) }, __( 'Use the original image', 'swiftling' ) )
					),
					alt &&
						h(
							'div',
							{ className: 'swiftling-visual-field' },
							h( 'label', { htmlFor: 'swiftling-visual-alt-' + alt.key }, /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Alt text in %s', 'swiftling' ), LANG ), h( 'span', { className: 'swiftling-visual-field__original' }, SOURCE + ': ' + ( alt.original || '—' ) ) ),
							h( 'input', { type: 'text', id: 'swiftling-visual-alt-' + alt.key, value: ! changed && MISSING.includes( alt.state ) && image.alt === originalImage.alt ? '' : image.alt || '', placeholder: alt.original, onChange: ( event ) => setAttributes( imageAttributes( name, { alt: event.target.value } ) ) } )
						)
				)
			)
		);
	};
	const unitKind = ( unit ) => ( { alt: __( 'Alt text', 'swiftling' ), src: __( 'Image', 'swiftling' ), url: __( 'Image', 'swiftling' ) } )[ unit.attr || unit.override ] || __( 'Text', 'swiftling' );

	addFilter( 'editor.BlockEdit', 'swiftling/visual-original', ( BlockEdit ) => ( props ) => {
		const swl = props.attributes && props.attributes.swl;
		const media = swl && props.isSelected && MEDIA_BLOCKS.includes( props.name ) && attrUnitOf( swl, 'src' );
		if ( ! media ) {
			return h( BlockEdit, props );
		}
		return h(
			Fragment,
			null,
			h( BlockEdit, props ),
			h(
				BlockControls,
				{ group: 'other' },
				h(
					ToolbarGroup,
					{ className: 'swiftling-visual-tools' },
					media && h( ToolbarButton, { icon: 'format-image', label: /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Use a different image for %s', 'swiftling' ), LANG ), onClick: () => chooseImage( ( chosen ) => props.setAttributes( imageAttributes( props.name, { url: chosen.url, id: chosen.id } ) ) ) }, /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'Image for %s', 'swiftling' ), LANG ) ),
					media && ownImage( props.name, props.attributes ) && h( ToolbarButton, { icon: 'undo', label: __( 'Use the original image', 'swiftling' ), onClick: () => props.setAttributes( imageAttributes( props.name, { url: imageOf( props.name, swl.originalAttrs ).url, id: imageOf( props.name, swl.originalAttrs ).id } ) ) } )
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
	const READ_ONLY = [ 'core/html', 'core/shortcode', 'core/freeform', 'core/missing' ];
	// The blocks the texts beside the page are of, where the page shows them as they are.
	const sideAs = {};
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
			// Code, as a whole figure or the HTML of a block, and the blocks whose text the page
			// cannot change as SwiftLing keeps it are fields beside the page; the page shows them
			// as they are.
			if ( 'code' === text.type || READ_ONLY.includes( node.blockName ) ) {
				if ( READ_ONLY.includes( node.blockName ) ) {
					sideAs[ text.key ] = node.blockName;
				}
				return;
			}
			// An address in an attribute of a block, as the link of a plugin's button, has no control
			// on the page.
			if ( where.attrPath && 'url' === text.type ) {
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
		// A block that did not load, as one of a plugin whose script failed, shows as it is saved;
		// its texts are fields beside the page.
		if ( 'core/missing' === block.name || false === block.isValid ) {
			const forget = ( raw ) => {
				( raw.swl || [] ).forEach( ( unit ) => placed.delete( unit.key ) );
				raw.innerBlocks.forEach( forget );
			};
			forget( node );
			return;
		}
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
					attr: unit.attr, override: unit.override, attrPath: unit.attrPath, c: unit.c,
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
	const buildBlocks = () => {
		const blocks = buildTree();
		// Every text is reachable: the texts of blocks on the page, and all others beside it. A text
		// that found no block, as one of a block that did not load or changed its form, is beside it.
		const attached = new Set();
		walkBlocks( blocks, ( block ) => ( block.attributes.swl ? block.attributes.swl.units : [] ).forEach( ( unit ) => attached.add( unit.key ) ) );
		[ ...placed ].filter( ( key ) => ! attached.has( key ) ).forEach( ( key ) => placed.delete( key ) );
		return blocks;
	};
	const buildTree = () => {
		const blocks = [];
		rawTreeOf( PAGE, data.post.content, 0 ).forEach( ( node ) => {
			const parsed = parse( serializeRawBlock( fillRaw( node ) ) );
			const original = parse( serializeRawBlock( cloneRaw( node ) ) );
			// Translating changes texts and images, never the layout: no block moves or goes.
			walkBlocks( parsed, ( block ) => {
				block.attributes.lock = { move: true, remove: true };
			} );
			if ( parsed.length && original.length ) {
				annotate( parsed[ 0 ], original[ 0 ], node );
				blocks.push( parsed[ 0 ] );
			}
		} );
		return blocks;
	};

	const textOf = ( block, unit ) => {
		if ( 'src' === unit.attr ) {
			// The original image again: no translation of the address, rather than a copy of it.
			return ownImage( block.name, block.attributes ) ? imageOf( block.name, block.attributes ).url : '';
		}
		if ( 'alt' === unit.attr ) {
			return imageOf( block.name, block.attributes ).alt || '';
		}
		if ( unit.attr ) {
			return null;
		}
		if ( unit.override ) {
			const text = htmlString( block.attributes[ unit.override ] );
			return isBlank( text ) ? '' : text;
		}
		if ( unit.attrPath ) {
			const text = htmlString( getPath( block.attributes, unit.attrPath ) );
			return isBlank( text ) ? '' : text;
		}
		// The text as the block writes it now, between the original's own tags: a block that writes
		// its element anew as it loads, as one that numbers its styles, changes nothing else.
		// Emptied, it is no translation: visitors read the original, as in the translation editor.
		const [ prefix, , suffix ] = partsOf( unit.original );
		const middle = partsOf( segmentOf( block, unit.c ) )[ 1 ];
		return isBlank( middle ) ? '' : prefix + middle + suffix;
	};
	// The tags around a block's text, as SwiftLing lists it, and the text between them: those of the
	// block's element, as a heading's, and those a part of a block opens or closes around its inner
	// blocks, as a list item's around its list. The machine translation service gets only the text,
	// and a translation keeps the original's tags as they are.
	// The elements of a block's structure: never a link or a format that is part of the text, but
	// the link of a button, which is the button.
	const BLOCK_TAG = '(?:p|h[1-6]|li|ul|ol|div|figure|figcaption|blockquote|cite|summary|details|td|th|dt|dd|pre|a(?=[^>]*\\bwp-block-button__link\\b))';
	const partsOf = ( html ) => {
		let prefix = '';
		let suffix = '';
		let middle = html;
		const wrapper = new RegExp( '^(\\s*<(' + BLOCK_TAG + ')\\b[^>]*>)([\\s\\S]*)(<\\/\\2>\\s*)$', 'i' );
		for ( let found = middle.match( wrapper ); found && ! new RegExp( '<\\/?' + found[ 2 ] + '\\b', 'i' ).test( found[ 3 ] ); found = middle.match( wrapper ) ) {
			prefix += found[ 1 ];
			suffix = found[ 4 ] + suffix;
			middle = found[ 3 ];
		}
		const opened = middle.match( new RegExp( '^(\\s*(?:<' + BLOCK_TAG + '\\b[^>]*>\\s*)+)', 'i' ) );
		if ( opened && ! /<\//.test( opened[ 1 ] ) ) {
			prefix += opened[ 1 ];
			middle = middle.slice( opened[ 1 ].length );
		}
		const closed = middle.match( new RegExp( '((?:\\s*<\\/' + BLOCK_TAG + '>)+\\s*)$', 'i' ) );
		if ( closed ) {
			suffix = closed[ 1 ] + suffix;
			middle = middle.slice( 0, middle.length - closed[ 1 ].length );
		}
		return [ prefix, middle, suffix ];
	};

	// What SwiftLing adds to a block, its texts and the lock, is never part of what is saved.
	const OWN_ATTRIBUTES = [ 'swl', 'lock' ];
	const cleanBlock = ( block ) => ( {
		...block,
		attributes: Object.fromEntries( Object.entries( block.attributes ).filter( ( [ name ] ) => ! OWN_ATTRIBUTES.includes( name ) ) ),
		innerBlocks: block.innerBlocks.map( cleanBlock ),
	} );
	// The part of a block's HTML that is a text, as SwiftLing lists it: the HTML around its inner
	// blocks, without them, as a list item's text without the list inside it.
	const segmentOf = ( block, c ) => {
		const node = rawParse( serialize( [ cleanBlock( block ) ] ) ).find( ( raw ) => raw.blockName );
		const part = node ? node.innerContent[ c || 0 ] : '';
		return 'string' === typeof part ? part : '';
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
					// Empty with no translation, as the original image kept, is nothing to save.
					if ( null !== text && ! seen.has( unit.key ) && ! ( '' === text && '' === unit.translation ) ) {
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
			// A text changed here counts once it holds something; one emptied is empty again.
			const changed = swl.copied || ! untouched( block.attributes );
			done += changed ? counted.filter( ( unit ) => { const text = textOf( block, unit ); return null === text || '' !== text; } ).length : counted.filter( ( unit ) => DONE.includes( unit.state ) ).length;
			if ( untouched( block.attributes ) && ! swl.copied ) {
				machine += counted.filter( ( unit ) => 'machine' === unit.state ).length;
			}
		} );
		if ( title ) {
			total++;
			done += ( title.dirty ? '' !== title.value.trim() : DONE.includes( title.state ) ) ? 1 : 0;
		}
		others.forEach( ( other ) => {
			if ( 'url' === other.unit.type ) {
				return;
			}
			total++;
			done += ( other.dirty ? ! isBlank( other.value ) : DONE.includes( other.unit.state ) ) ? 1 : 0;
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

	// The panel under the page: always the same height, so choosing a block moves nothing.
	const Dock = () => {
		const block = useSelect( ( select ) => {
			const id = select( blockEditorStore ).getSelectedBlockClientId();
			return id ? select( blockEditorStore ).getBlock( id ) : null;
		}, [] );
		const { updateBlockAttributes } = useDispatch( blockEditorStore );
		const swl = block && block.attributes.swl;
		return h(
			'section',
			{ className: 'swiftling-visual-dock' + ( swl ? '' : ' is-empty' ), 'aria-live': 'polite', 'aria-label': __( 'Original', 'swiftling' ) },
			swl
				? h( OriginalPanel, { key: block.clientId, name: block.name, attributes: block.attributes, setAttributes: ( attrs ) => updateBlockAttributes( block.clientId, attrs ) } )
				: h(
						'p',
						{ className: 'swiftling-visual-dock__hint' },
						h( 'span', { className: 'dashicons dashicons-translation', 'aria-hidden': true } ),
						__( 'Click a text on the page and type over it: its original shows here. Greyed texts are not translated yet. Click an image to give this language its own image or alt text.', 'swiftling' )
				  )
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
				way( 'duplicate', __( 'Keep the original', 'swiftling' ), /* translators: 1: language of the visitors, 2: language of the original. */sprintf( __( 'Visitors in %1$s see this page in %2$s, as it is. It counts as done, so Translate everything skips it. The translations made here are kept.', 'swiftling' ), LANG, SOURCE ) )
			)
		);
	};

	// HTML to show as it reads, without what would run: scripts, frames and handlers.
	const safeHtml = ( html ) => {
		const template = document.createElement( 'template' );
		template.innerHTML = html;
		template.content.querySelectorAll( 'script, style, iframe, object, embed' ).forEach( ( element ) => element.remove() );
		template.content.querySelectorAll( '*' ).forEach( ( element ) => {
			[ ...element.attributes ].forEach( ( attribute ) => {
				if ( /^on/i.test( attribute.name ) || /^\s*javascript:/i.test( attribute.value ) ) {
					element.removeAttribute( attribute.name );
				}
			} );
		} );
		return template.innerHTML;
	};

	// A text with formatting, as a product's short description: shown as it reads, and edited so,
	// with its HTML a click away.
	const HtmlField = ( { id, value, original, onChange } ) => {
		const [ code, setCode ] = useState( false );
		const ref = useRef( null );
		useEffect( () => {
			if ( ref.current && document.activeElement !== ref.current ) {
				ref.current.innerHTML = safeHtml( value );
			}
		}, [ value, code ] );
		return h(
			Fragment,
			null,
			code
				? h( 'textarea', { id, rows: 4, className: 'swiftling-mono', value, placeholder: original, onChange: ( event ) => onChange( event.target.value ) } )
				: h( 'div', { id, ref, role: 'textbox', 'aria-multiline': 'true', contentEditable: true, suppressContentEditableWarning: true, className: 'swiftling-visual-html', 'data-placeholder': original.replace( /<[^>]*>/g, '' ), onInput: ( event ) => onChange( event.currentTarget.innerHTML ) } ),
			h( 'button', { type: 'button', className: 'swiftling-visual-html-toggle', 'aria-pressed': code ? 'true' : 'false', onClick: () => setCode( ! code ) }, __( 'HTML', 'swiftling' ) )
		);
	};

	// Texts with no place on the page: link addresses, code, excerpt and SEO fields, and the texts of
	// blocks the page shows as they are.
	const OtherField = ( { other, onChange } ) => {
		const unit = other.unit;
		const isUrl = 'url' === unit.type;
		const isCode = 'code' === unit.type;
		const state = other.dirty ? 'edited' : unit.state;
		const id = 'swiftling-visual-other-' + unit.key;
		let field;
		if ( 'html' === unit.type ) {
			// The text between the tags around it, which stay as they are; emptied, no translation.
			const [ prefix, middle, suffix ] = unit.parts || [ '', unit.original, '' ];
			const value = other.value.startsWith( prefix ) && other.value.endsWith( suffix ) && other.value.length >= prefix.length + suffix.length ? other.value.slice( prefix.length, other.value.length - suffix.length ) : other.value;
			field = h( HtmlField, { id, value, original: middle, onChange: ( text ) => onChange( isBlank( text ) ? '' : prefix + text + suffix ) } );
		} else {
			field = h( isUrl ? 'input' : 'textarea', { id, type: isUrl ? 'text' : undefined, rows: isUrl ? undefined : isCode ? 5 : 2, className: isUrl || isCode ? 'swiftling-mono' : undefined, value: other.value, placeholder: unit.original, onChange: ( event ) => onChange( event.target.value ) } );
		}
		return h(
			'div',
			{ className: 'swiftling-visual-field' + ( ! other.dirty && MISSING.includes( unit.state ) ? ' is-missing' : '' ) },
			h( 'label', { htmlFor: id }, h( StatusIcon, { state } ), unit.label || __( 'Text', 'swiftling' ), unit.place && h( 'span', { className: 'swiftling-muted' }, ' · ' + unit.place ) ),
			isCode
				? h( 'pre', { className: 'swiftling-visual-source swiftling-mono' }, unit.original )
				: h( 'p', { className: 'swiftling-visual-source' }, h( 'span', { className: 'swiftling-visual-source__label' }, SOURCE ), 'html' === unit.type ? h( 'span', { dangerouslySetInnerHTML: { __html: safeHtml( unit.original ) } } ) : unit.original ),
			field,
			isCode && h( 'p', { className: 'swiftling-muted' }, __( 'Change only the text. Keep tags, attributes and shortcode names as they are.', 'swiftling' ) ),
			other.value !== unit.original && h( SButton, { variant: 'link', className: 'swiftling-visual-use-original', onClick: () => onChange( unit.original ) }, __( 'Use the original', 'swiftling' ) )
		);
	};

	const Sidebar = ( { others, setOthers, address, setAddress, note } ) =>
		h(
			'aside',
			{ className: 'swiftling-editor-layout__aside swiftling-visual-aside' },
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
					data.frame.url && h( SButton, { href: data.frame.url }, __( 'Translate the template', 'swiftling' ) )
				),
			data.links.request &&
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

	// The keys saved while the screen is open, with what was saved: machine translation leaves alone
	// what has a translation now.
	const savedNow = new Map();
	// A note to show once the screen loads again, as after machine translation.
	const LATER = 'swiftling-visual-note';
	const noteLater = ( text, status ) => {
		try {
			window.sessionStorage.setItem( LATER, JSON.stringify( { text, status, post: data.post.id } ) );
		} catch ( error ) {}
	};
	const noteNow = () => {
		try {
			const later = JSON.parse( window.sessionStorage.getItem( LATER ) || 'null' );
			window.sessionStorage.removeItem( LATER );
			return later && later.post === data.post.id ? { text: later.text, status: later.status } : null;
		} catch ( error ) {
			return null;
		}
	};

	/* The layout stays as it is. */

	// Which blocks the page has, in which order and inside which: what translating never changes.
	const shapeOf = ( list ) => list.map( ( block ) => block.clientId + ':' + block.name + '(' + shapeOf( block.innerBlocks ) + ')' ).join( ',' );
	const hasTexts = ( block ) => !! block.attributes.swl || block.innerBlocks.some( hasTexts );

	// A block without a text of SwiftLing's, in itself or in a block inside it, cannot be changed
	// here: an embed, a list of posts, a block of code; the page shows it as it is.
	const EditingModes = () => {
		const order = useSelect( ( select ) => select( blockEditorStore ).getClientIdsWithDescendants().length, [] );
		const { getBlocks } = useSelect( ( select ) => ( { getBlocks: select( blockEditorStore ).getBlocks } ), [] );
		const { setBlockEditingMode } = useDispatch( blockEditorStore );
		canvas.registry = wp.data.useRegistry();
		useEffect( () => {
			const walk = ( list ) =>
				list.forEach( ( block ) => {
					if ( ! hasTexts( block ) ) {
						setBlockEditingMode( block.clientId, 'disabled' );
					} else {
						walk( block.innerBlocks );
					}
				} );
			walk( getBlocks() );
		}, [ order ] );
		return null;
	};

	// Keys that would add, copy, move or remove a block.
	const isLayoutShortcut = ( event ) => {
		const key = ( event.key || '' ).toLowerCase();
		const primary = event.ctrlKey || event.metaKey;
		return ( primary && event.shiftKey && 'd' === key ) || ( primary && event.altKey && ( 't' === key || 'y' === key ) ) || ( event.shiftKey && event.altKey && 'z' === key );
	};
	const stop = ( event ) => {
		event.preventDefault();
		event.stopImmediatePropagation();
	};
	// Whether the cursor is at the start or the end of a text, where Backspace or Delete would join
	// it with the block before or after.
	const caretAt = ( editable, edge ) => {
		const doc = editable.ownerDocument;
		const selection = doc.getSelection();
		if ( ! selection || ! selection.rangeCount || ! selection.isCollapsed ) {
			return false;
		}
		const range = doc.createRange();
		if ( 'start' === edge ) {
			range.setStart( editable, 0 );
			range.setEnd( selection.anchorNode, selection.anchorOffset );
		} else {
			range.setStart( selection.anchorNode, selection.anchorOffset );
			range.setEnd( editable, editable.childNodes.length );
		}
		return '' === range.toString().replace( /[\u200B\uFEFF]/g, '' );
	};
	// A line break where the cursor is, as Shift+Enter makes one.
	const lineBreak = ( target ) => {
		const view = target.ownerDocument.defaultView;
		const event = new view.KeyboardEvent( 'keydown', { key: 'Enter', code: 'Enter', shiftKey: true, bubbles: true, cancelable: true } );
		Object.defineProperty( event, 'keyCode', { get: () => 13 } );
		Object.defineProperty( event, 'which', { get: () => 13 } );
		event.swiftlingLineBreak = true;
		target.dispatchEvent( event );
	};
	// In the page: Enter makes a line break, not a new block; Backspace at the start and Delete at
	// the end of a text do not join two blocks; and no key adds, copies, moves or removes one.
	// Undo and redo of the page, which the screen keeps itself, see Screen; and the registry of its
	// block editor, a registry of its own, see EditingModes.
	const history = { step: null };
	const canvas = { registry: null };
	const editorSelect = () => ( canvas.registry || wp.data ).select( blockEditorStore );
	const editorDispatch = () => ( canvas.registry || wp.data ).dispatch( blockEditorStore );
	const guardKeys = ( event ) => {
		if ( event.swiftlingLineBreak ) {
			return;
		}
		const key = ( event.key || '' ).toLowerCase();
		if ( ( event.ctrlKey || event.metaKey ) && ! event.altKey && ( 'z' === key || 'y' === key ) && history.step ) {
			stop( event );
			history.step( 'z' === key && ! event.shiftKey ? -1 : 1 );
			return;
		}
		if ( isLayoutShortcut( event ) || ( [ 'Backspace', 'Delete' ].includes( event.key ) && editorSelect().hasMultiSelection() ) ) {
			stop( event );
			return;
		}
		const editable = event.target.closest && event.target.closest( '[contenteditable="true"]' );
		if ( ! editable ) {
			return;
		}
		if ( 'Enter' === event.key && ! event.shiftKey && ! event.ctrlKey && ! event.metaKey && ! event.altKey ) {
			stop( event );
			if ( ! editable.closest( '.swiftling-visual-title' ) ) {
				lineBreak( event.target );
			}
		} else if ( ( 'Backspace' === event.key && caretAt( editable, 'start' ) ) || ( 'Delete' === event.key && caretAt( editable, 'end' ) ) ) {
			stop( event );
		}
	};
	// Text of several paragraphs, pasted into one, becomes its lines.
	// Pasted text of several paragraphs, lists or lines, as one text: its lines with line breaks
	// between them and their formats kept, without the paragraphs and lists, which would be blocks.
	const INLINE_TAGS = [ 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'del', 'dfn', 'em', 'i', 'ins', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var' ];
	const inlineLines = ( html, text ) => {
		const template = document.createElement( 'template' );
		if ( html ) {
			template.innerHTML = html;
		} else {
			template.content.append( ...text.split( /\r?\n/ ).flatMap( ( line, index ) => ( index ? [ document.createElement( 'br' ), line ] : [ line ] ) ) );
		}
		template.content.querySelectorAll( 'script, style, meta, title' ).forEach( ( element ) => element.remove() );
		// Each element that is no inline format ends a line and gives up its tags.
		[ ...template.content.querySelectorAll( '*' ) ].reverse().forEach( ( element ) => {
			if ( INLINE_TAGS.includes( element.tagName.toLowerCase() ) ) {
				[ ...element.attributes ].filter( ( attribute ) => /^on/i.test( attribute.name ) ).forEach( ( attribute ) => element.removeAttribute( attribute.name ) );
				return;
			}
			element.after( document.createElement( 'br' ) );
			element.replaceWith( ...element.childNodes );
		} );
		return template.innerHTML
			.replace( /\s*(<br\s*\/?>\s*)+/gi, '<br>' )
			.replace( /^(<br>)+|(<br>)+$/g, '' )
			.trim();
	};
	const guardPaste = ( event ) => {
		const editable = event.target.closest && event.target.closest( '[contenteditable="true"]' );
		const text = event.clipboardData ? event.clipboardData.getData( 'text/plain' ) : '';
		const html = event.clipboardData ? event.clipboardData.getData( 'text/html' ) : '';
		if ( ! editable || ( ! /\n/.test( text.trim() ) && ! /<(p|div|li|h[1-6]|ul|ol|blockquote|table|tr|pre)\b/i.test( html ) ) ) {
			return;
		}
		const store = editorSelect();
		const start = store.getSelectionStart();
		const end = store.getSelectionEnd();
		if ( ! start.clientId || start.clientId !== end.clientId || ! start.attributeKey || start.attributeKey !== end.attributeKey ) {
			return;
		}
		stop( event );
		const title = editable.closest( '.swiftling-visual-title' );
		const pasted = wp.richText.create( { html: inlineLines( title ? '' : html, title ? text.replace( /\s*\n\s*/g, ' ' ) : text ) } );
		const current = wp.richText.create( { html: htmlString( store.getBlockAttributes( start.clientId )[ start.attributeKey ] ) } );
		const next = wp.richText.insert( current, pasted, start.offset, end.offset );
		const offset = start.offset + pasted.text.length;
		editorDispatch().updateBlockAttributes( start.clientId, { [ start.attributeKey ]: wp.richText.toHTMLString( { value: next } ) } );
		editorDispatch().selectionChange( start.clientId, start.attributeKey, offset, offset );
	};

	const Screen = () => {
		const [ blocks, setBlocks ] = useState( () => buildBlocks() );
		const shape = useRef( null );
		if ( null === shape.current ) {
			shape.current = shapeOf( blocks );
		}
		const reloading = useRef( false );
		const [ title, setTitle ] = useState( () => ( titleUnit ? { unit: titleUnit, value: '' === titleUnit.translation ? titleUnit.original : titleUnit.translation, state: titleUnit.state, dirty: false } : null ) );
		const [ others, setOthers ] = useState( () =>
			data.texts
				.filter( ( text ) => ! placed.has( text.key ) && text !== titleUnit && ! text.follows )
				.map( ( text ) => ( sideAs[ text.key ] ? { ...text, type: 'code', label: blockTitle( sideAs[ text.key ] ) } : text ) )
				.map( ( unit ) => ( { unit, value: unit.translation, dirty: false } ) )
		);
		const [ address, setAddress ] = useState( data.address );
		const [ saving, setSaving ] = useState( false );
		const [ notice, setNotice ] = useState( noteNow );
		const [ highlight, setHighlight ] = useState( false );
		const [ machining, setMachining ] = useState( false );
		// The sidebar, shown or hidden for more room; remembered in this browser.
		const [ wide, setWide ] = useState( () => {
			try {
				return '1' === window.localStorage.getItem( 'swiftling-visual-wide' );
			} catch ( error ) {
				return false;
			}
		} );
		const toggleWide = () => {
			setWide( ! wide );
			try {
				window.localStorage.setItem( 'swiftling-visual-wide', wide ? '0' : '1' );
			} catch ( error ) {}
		};
		const note = ( text, status ) => setNotice( text ? { text, status } : null );
		// The page, the original under it and the sidebar fit the window as it opens: nothing to
		// scroll to, and nothing that moves. Measured again when the window or the head changes.
		const barRef = useRef( null );
		useEffect( () => {
			const root = document.documentElement;
			const fit = () => {
				const canvas = document.querySelector( '.swiftling-visual-canvas' );
				const layout = document.querySelector( '.swiftling-visual-layout' );
				if ( ! canvas || ! layout ) {
					return;
				}
				const dock = parseFloat( getComputedStyle( canvas.closest( '.swiftling-visual' ) ).getPropertyValue( '--swiftling-visual-dock' ) ) || 164;
				const canvasTop = canvas.getBoundingClientRect().top + window.scrollY;
				const layoutTop = layout.getBoundingClientRect().top + window.scrollY;
				root.style.setProperty( '--swiftling-visual-canvas-h', Math.max( 300, window.innerHeight - canvasTop - dock - 24 ) + 'px' );
				root.style.setProperty( '--swiftling-visual-aside-h', Math.max( 360, window.innerHeight - layoutTop - 20 ) + 'px' );
				if ( barRef.current ) {
					root.style.setProperty( '--swiftling-visual-bar', barRef.current.offsetHeight + 'px' );
				}
			};
			fit();
			window.addEventListener( 'resize', fit );
			const observer = window.ResizeObserver ? new window.ResizeObserver( fit ) : null;
			if ( observer ) {
				[ barRef.current, document.querySelector( '.swiftling-visual-head' ) ].filter( Boolean ).forEach( ( el ) => observer.observe( el ) );
			}
			return () => {
				window.removeEventListener( 'resize', fit );
				if ( observer ) {
					observer.disconnect();
				}
			};
		}, [] );
		// "Saved." no longer holds once something changes, as in the table editor.
		const itemCount = changedItems( blocks, title, others ).length;
		useEffect( () => {
			if ( itemCount && notice && 'success' === notice.status ) {
				setNotice( null );
			}
		}, [ itemCount ] );

		// Whatever changed the layout, a key, a paste or a menu, is undone at once: only texts and
		// images change here.
		const accept = ( next ) => {
			if ( shapeOf( next ) !== shape.current ) {
				setBlocks( ( current ) => [ ...current ] );
				note( __( 'The layout stays as in the original; only texts and images change here.', 'swiftling' ), 'info' );
				return false;
			}
			setBlocks( next );
			return true;
		};
		// Undo and redo: the page as it was after each change the block editor settled, as it
		// reports one once typing pauses; the screen keeps them, as the block editor alone keeps none.
		const steps = useRef( { entries: [ blocks ], at: 0 } );
		const settled = ( next ) => {
			if ( accept( next ) && next !== steps.current.entries[ steps.current.at ] ) {
				const memory = steps.current;
				memory.entries = memory.entries.slice( Math.max( 0, memory.at - 99 ), memory.at + 1 ).concat( [ next ] );
				memory.at = memory.entries.length - 1;
			}
		};
		history.step = ( by ) => {
			const memory = steps.current;
			const at = memory.at + by;
			if ( at >= 0 && at < memory.entries.length ) {
				memory.at = at;
				setBlocks( memory.entries[ at ] );
			}
		};

		const settings = useMemo( () => ( { ...data.settings, allowedBlockTypes: [], templateLock: 'contentOnly', focusMode: false, hasFixedToolbar: false, canLockBlocks: false, codeEditingEnabled: false, mediaUpload: wp.mediaUtils && wp.mediaUtils.uploadMedia ? ( args ) => wp.mediaUtils.uploadMedia( args ) : undefined } ), [] );
		const styles = useMemo( () => [ ...( data.settings.styles || [] ), { css: CANVAS_CSS } ], [] );

		const items = changedItems( blocks, title, others );
		const progress = progressOf( blocks, title, others );

		const save = useCallback( async ( state ) => {
			setSaving( true );
			note( __( 'Saving…', 'swiftling' ), 'info' );
			try {
				const result = await apiFetch( { path: 'swiftling/v1/translations', method: 'POST', data: { subject: PAGE, lang: data.lang, status: state, items } } );
				const states = result.states || {};
				items.forEach( ( item ) => savedNow.set( item.key, item.text ) );
				const saved = settle( blocks, states );
				setBlocks( saved );
				// Undo starts again from what is saved.
				steps.current = { entries: [ saved ], at: 0 };
				if ( title && title.dirty ) {
					setTitle( { ...title, dirty: false, state: states[ title.unit.key ] || state } );
				}
				setOthers( others.map( ( other ) => ( other.dirty ? { ...other, dirty: false, unit: { ...other.unit, state: states[ other.unit.key ] || state, translation: other.value } } : other ) ) );
				const refused = ( result.refused || [] ).length;
				note( refused ? /* translators: %d: number of texts. */ sprintf( _n( 'Saved, but %d text was refused: its placeholders do not fit the original.', 'Saved, but %d texts were refused: their placeholders do not fit the original.', refused, 'swiftling' ), refused ) : __( 'Saved.', 'swiftling' ), refused ? 'warning' : 'success' );
				if ( result.address && result.address.url ) {
					setAddress( ( current ) => ( current ? { ...current, ...result.address, slug: decodeURIComponent( result.address.slug || '' ) } : current ) );
				}
				setSaving( false );
				return true;
			} catch ( error ) {
				note( error && error.message ? error.message : __( 'The translations could not be saved.', 'swiftling' ), 'error' );
			}
			setSaving( false );
			return false;
		}, [ blocks, title, others, items ] );

		// Fills the empty texts with the service, as the translation editor fills its empty fields: in
		// requests of at most 50 texts, each saved at once as machine translations, so what the
		// service translated is kept when a later request fails. Changes not saved yet are saved
		// first; once done, the page loads again with the translations to review.
		const machineFill = async () => {
			if ( items.length ) {
				if ( ! window.confirm( __( 'Your changes are saved first, then the empty fields are translated and the page loads again.', 'swiftling' ) ) ) {
					return;
				}
				if ( ! ( await save( 'reviewed' ) ) ) {
					return;
				}
			}
			// The texts empty now: not those saved since the screen opened, as the changes just saved.
			const empty = ( text ) => ( savedNow.has( text.key ) ? '' === savedNow.get( text.key ) : '' === text.translation );
			const found = data.texts.filter( ( text ) => empty( text ) && ! text.follows && ! [ 'url', 'code' ].includes( text.type ) && '' !== text.original.trim() );
			if ( ! found.length ) {
				note( __( 'Every text field is filled in. Empty a field to translate it again.', 'swiftling' ), 'info' );
				return;
			}
			const service = data.machine.service;
			let done = 0;
			let translated = 0;
			let stopped = '';
			setSaving( true );
			setMachining( true );
			try {
				for ( const batch of window.swiftlingMachine.batches( found, ( text ) => text.original.length ) ) {
					note(
						/* translators: 1: translation service name, e.g. Google, 2: fields done, 3: fields in all. */
						sprintf( _n( 'Translating with %1$s: %2$s of %3$s field…', 'Translating with %1$s: %2$s of %3$s fields…', found.length, 'swiftling' ), service, done, found.length ),
						'info'
					);
					const parts = new Map( batch.map( ( text ) => [ text.key, 'html' === text.type ? partsOf( text.original ) : [ '', text.original, '' ] ] ) );
					const result = await apiFetch( { path: 'swiftling/v1/machine', method: 'POST', data: { subject: PAGE, lang: data.lang, items: batch.map( ( text ) => ( { key: text.key, text: parts.get( text.key )[ 1 ], html: 'html' === text.type } ) ) } } );
					done += batch.length;
					const saveItems = batch
						.filter( ( text ) => 'string' === typeof result.translations[ text.key ] && '' !== result.translations[ text.key ].trim() )
						.map( ( text ) => {
							const [ prefix, , suffix ] = parts.get( text.key );
							return { key: text.key, text: prefix + result.translations[ text.key ] + suffix, original: text.original };
						} );
					if ( saveItems.length ) {
						const saved = await apiFetch( { path: 'swiftling/v1/translations', method: 'POST', data: { subject: PAGE, lang: data.lang, status: 'machine', items: saveItems } } );
						translated += Object.keys( saved.states || {} ).length;
						saveItems.forEach( ( item ) => savedNow.set( item.key, item.text ) );
					}
					if ( result.error ) {
						stopped = result.error;
						break;
					}
				}
			} catch ( error ) {
				stopped = error && error.message ? error.message : __( 'The translation stopped.', 'swiftling' );
			}
			const parts = [];
			if ( translated ) {
				parts.push(
					/* translators: 1: number of fields, 2: translation service name, e.g. Google. */
					sprintf( _n( '%1$s field translated with %2$s and saved as a machine translation. Read it, then save to mark it reviewed.', '%1$s fields translated with %2$s and saved as machine translations. Read them, then save to mark them reviewed.', translated, 'swiftling' ), translated, service )
				);
			}
			if ( stopped ) {
				parts.push( stopped );
			}
			if ( ! translated ) {
				note( parts.join( ' ' ), 'error' );
				setSaving( false );
				setMachining( false );
				return;
			}
			// The page again, with what was saved, also when the service stopped part of the way: the
			// page, the counts and translating again then start from there. Nothing is left unsaved,
			// so leaving asks nothing; what happened shows once it is back.
			noteLater( parts.join( ' ' ), stopped ? 'error' : 'success' );
			reloading.current = true;
			window.location.reload();
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
			note( copied ? /* translators: %s: number of fields. */ sprintf( _n( 'The original is in %s empty field now. Change what needs changing, then save.', 'The original is in %s empty fields now. Change what needs changing, then save.', copied, 'swiftling' ), copied ) : __( 'Every field is filled in.', 'swiftling' ), 'info' );
		};

		useEffect( () => {
			const warn = ( event ) => {
				if ( items.length && ! reloading.current ) {
					event.preventDefault();
					event.returnValue = '';
				}
			};
			window.addEventListener( 'beforeunload', warn );
			return () => window.removeEventListener( 'beforeunload', warn );
		}, [ items.length ] );
		// The page in the canvas: in the language and direction of the translation, and guarded
		// against keys that would change its layout. The canvas is an iframe, which can load again.
		useEffect( () => {
			const guarded = new WeakSet();
			const setUp = () => {
				const frame = document.querySelector( '.swiftling-visual-canvas iframe[name="editor-canvas"]' );
				const doc = frame && frame.contentDocument;
				if ( ! doc || ! doc.body ) {
					return;
				}
				if ( data.target ) {
					doc.documentElement.lang = data.target.lang;
					doc.documentElement.dir = data.target.dir;
					doc.body.dir = data.target.dir;
				}
				if ( ! guarded.has( doc ) ) {
					guarded.add( doc );
					doc.addEventListener( 'keydown', guardKeys, true );
					doc.addEventListener( 'paste', guardPaste, true );
				}
			};
			setUp();
			const timer = setInterval( setUp, 500 );
			// The block's toolbar is outside the canvas.
			const guardOutside = ( event ) => {
				if ( isLayoutShortcut( event ) ) {
					stop( event );
				}
			};
			document.addEventListener( 'keydown', guardOutside, true );
			return () => {
				clearInterval( timer );
				document.removeEventListener( 'keydown', guardOutside, true );
			};
		}, [] );

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
			if ( items.length && ! window.confirm( __( 'Some translations are not saved yet.', 'swiftling' ) ) ) {
				event.preventDefault();
			}
		};

		const percent = ( part ) => ( progress.total ? Math.round( ( 1000 * part ) / progress.total ) / 10 : 0 ) + '%';
		const empty = progress.total - progress.done;
		const viewIn = address && address.url ? address.url : data.post.viewIn;

		const bar = h(
			'div',
			{ className: 'swiftling-editor-bar swiftling-visual-bar', ref: barRef },
			data.links.back && h( SButton, { variant: 'link is-back', href: data.links.back, icon: 'arrow-left-alt2' }, __( 'Translations', 'swiftling' ) ),
			h(
				'div',
				{ className: 'swiftling-editor-bar__title' },
				h( 'h1', null, data.post.title || __( '(no title)', 'swiftling' ) ),
				h( 'span', { className: 'swiftling-muted' }, data.post.type )
			),
			h(
				'div',
				{ className: 'swiftling-editor-bar__actions' },
				data.post.url && h( SButton, { variant: 'ghost', href: data.post.url, target: '_blank', rel: 'noopener', icon: 'visibility' }, __( 'View original', 'swiftling' ) ),
				viewIn && h( SButton, { variant: 'ghost', href: viewIn, target: '_blank', rel: 'noopener', icon: 'visibility' }, /* translators: %s: language name, e.g. Deutsch. */ sprintf( __( 'View in %s', 'swiftling' ), LANG ) ),
				data.links.table && h( SButton, { variant: 'ghost', href: data.links.table, icon: 'editor-table', onClick: leave, title: __( 'The texts of this page as a list: original on the left, translation on the right.', 'swiftling' ) }, __( 'Table view', 'swiftling' ) ),
				h( SButton, { variant: 'accent', className: 'swiftling-live-save', onClick: () => save( 'reviewed' ), disabled: saving || ! items.length, busy: saving }, __( 'Save and mark reviewed', 'swiftling' ) )
			),
			// As in the translation editor: how many changes are not saved, and what the last save or tool did, under Save.
			h(
				'div',
				{ className: 'swiftling-editor-bar__status' },
				h(
					'div',
					{ className: 'swiftling-editor-bar__messages' },
					h( 'p', { className: 'swiftling-live-status', role: 'status' } ),
					h(
						'div',
						{ className: 'swiftling-editor-bar__end' },
						h( 'span', { className: 'swiftling-live-unsaved', 'aria-live': 'polite' }, items.length ? /* translators: %s: number of fields. */ sprintf( _n( '%s unsaved', '%s unsaved', items.length, 'swiftling' ), items.length ) : '' ),
						h( 'p', { className: 'swiftling-live-note' + ( notice ? ' is-' + ( 'warning' === notice.status ? 'error' : notice.status ) : '' ), role: 'status' }, notice ? notice.text : '' )
					)
				)
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
			? h( SButton, { variant: 'secondary is-strong', className: 'swiftling-live-machine', onClick: machineFill, disabled: saving || !! data.machine.off || ! empty, busy: machining, title: data.machine.off || undefined }, /* translators: %s: translation service name, e.g. Google. */ sprintf( __( 'Translate empty fields with %s', 'swiftling' ), data.machine.service ) )
			: data.links.service && h( SButton, { href: data.links.service, icon: 'translation' }, __( 'Set up machine translation', 'swiftling' ) );

		// One row over the page: how far the language is, what fills the empty texts, the views.
		const summary = /* translators: 1: texts translated, 2: all texts. */ sprintf( _n( '%1$s of %2$s text translated', '%1$s of %2$s texts translated', progress.total, 'swiftling' ), progress.done, progress.total ) + ( empty ? ' · ' + /* translators: %s: number of texts. */ sprintf( _n( '%s still empty', '%s still empty', empty, 'swiftling' ), empty ) : '' );
		const head = h(
			'div',
			{ className: 'swiftling-visual-head' },
			h(
				'div',
				{ className: 'swiftling-visual-head__progress' },
				h( 'p', { className: 'swiftling-live-summary', 'aria-live': 'polite' }, summary ),
				h( 'div', { className: 'swiftling-bar swiftling-live-meter', 'aria-hidden': true }, h( 'span', { className: 'is-reviewed', style: { width: percent( progress.done - progress.machine ) } } ), h( 'span', { className: 'is-machine', style: { width: percent( progress.machine ) } } ) )
			),
			h(
				'div',
				{ className: 'swiftling-live-fill', role: 'group', 'aria-labelledby': 'swiftling-visual-fill-label' },
				h( 'span', { className: 'swiftling-live-toolbar__label', id: 'swiftling-visual-fill-label' }, __( 'Fill empty fields', 'swiftling' ) ),
				machineTool,
				h( SButton, { onClick: copyAll, disabled: saving || ! empty }, __( 'Copy the original into empty fields', 'swiftling' ) )
			),
			h(
				'div',
				{ className: 'swiftling-visual-head__end' },
				h( 'button', { type: 'button', className: 'swiftling-live-switch', 'aria-pressed': highlight ? 'true' : 'false', onClick: () => setHighlight( ! highlight ), disabled: ! empty }, __( 'Highlight empty fields', 'swiftling' ) ),
				h( SButton, { variant: 'ghost', className: 'swiftling-visual-panel-toggle', icon: wide ? 'editor-contract' : 'editor-expand', 'aria-pressed': wide ? 'false' : 'true', onClick: toggleWide, title: wide ? __( 'Show the sidebar', 'swiftling' ) : __( 'Hide the sidebar', 'swiftling' ) }, h( 'span', { className: 'screen-reader-text' }, wide ? __( 'Show the sidebar', 'swiftling' ) : __( 'Hide the sidebar', 'swiftling' ) ) )
			)
		);

		return h(
			SlotFillProvider,
			null,
			h(
				BlockEditorProvider,
				{ value: blocks, onInput: accept, onChange: settled, settings },
				h( BlockEditorKeyboardShortcuts.Register ),
				h( EditingModes ),
				bar,
				languages,
				h(
					'div',
					{ className: 'swiftling-editor-layout swiftling-visual-layout' + ( wide ? ' is-wide' : '' ) },
					h(
						'div',
						{ className: 'swiftling-card is-flush swiftling-live-editor swiftling-visual-editor' + ( highlight ? ' is-marking-empty' : '' ) },
						head,
						h( 'div', { className: 'swiftling-visual-canvas' }, h( BlockCanvas, { height: '100%', styles }, h( Title, { title, setTitle } ), h( BlockList ) ) ),
						h( Dock )
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
		// After the scripts of other plugins, which can register their blocks once the page is ready.
		( wp.domReady || ( ( callback ) => callback() ) )( () => createRoot( root ).render( h( Screen ) ) );
		// The toolbar of a block offers the formats of its text and SwiftLing's own controls, as the
		// image of the language; nothing that changes the block itself, as its alignment, its link,
		// its caption or its image for every language. Those come from the block, in groups without
		// a class of their own, so each group is kept by what it holds: the formats of a text, which
		// start with bold and italic, or SwiftLing's.
		const formats = () =>
			new Set(
				[ 'core/bold', 'core/italic' ]
					.map( ( name ) => wp.data.select( 'core/rich-text' ).getFormatType( name ) )
					.filter( Boolean )
					.map( ( format ) => format.title )
			);
		const tidyToolbar = () => {
			root.querySelectorAll( '.block-editor-block-toolbar' ).forEach( ( toolbar ) => {
				const titles = formats();
				const show = ( element, shown ) => {
					const display = shown ? '' : 'none';
					if ( element.style.display !== display ) {
						element.style.display = display;
					}
				};
				[ ...toolbar.children ].forEach( ( group ) => {
					const ours = group.querySelector( '.swiftling-visual-tools' );
					show( group, ours || [ ...group.querySelectorAll( 'button' ) ].some( ( button ) => titles.has( button.getAttribute( 'aria-label' ) ) ) );
					// A block can put its own controls beside SwiftLing's, as a cover its Replace and Reset:
					// of that group, only SwiftLing's show.
					if ( ours ) {
						group.querySelectorAll( '.block-editor-block-toolbar__slot > *' ).forEach( ( item ) => show( item, item.contains( ours ) ) );
					}
				} );
			} );
		};
		new MutationObserver( tidyToolbar ).observe( root, { childList: true, subtree: true } );
	}
} )();
