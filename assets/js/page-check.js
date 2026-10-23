/**
 * Checking a page of the site in the browser, for the translation editor and for recording.
 *
 * The page opens in two hidden frames, once with a marker on every value that looks like text and
 * once as visitors see it. This script reads where each marker ended up and which texts a visitor
 * sees; the server decides from that what is text. Nothing on the page the user looks at changes.
 *
 * window.swiftlingPageCheck.check( probes, opened ) opens both addresses of a page check and
 * resolves to what the server's routes take: registry, signature, found, scoped and control.
 */
( function () {
	const MARKER = /zqk(\d+)kqz/gi;
	const HAS_MARKER = /zqk\d+kqz/i;
	const LETTER = /\p{L}/u;

	/* Loading a page in a hidden frame. */

	// Waits until scripts on the page stop changing it: half a second without changes, five seconds at most.
	const settle = ( doc ) =>
		new Promise( ( resolve ) => {
			let quiet;
			let finished = false;
			const observer = new MutationObserver( () => {
				clearTimeout( quiet );
				quiet = setTimeout( finish, 500 );
			} );
			function finish() {
				if ( ! finished ) {
					finished = true;
					observer.disconnect();
					resolve();
				}
			}
			observer.observe( doc.documentElement, { subtree: true, childList: true, characterData: true } );
			quiet = setTimeout( finish, 500 );
			setTimeout( finish, 5000 );
		} );

	const openFrame = ( url ) =>
		new Promise( ( resolve, reject ) => {
			const frame = document.createElement( 'iframe' );
			const timeout = setTimeout( () => {
				frame.remove();
				reject( new Error( 'The page did not load in time.' ) );
			}, 45000 );

			frame.className = 'swiftling-probe-frame';
			frame.setAttribute( 'sandbox', 'allow-scripts allow-same-origin' );
			frame.setAttribute( 'aria-hidden', 'true' );
			frame.tabIndex = -1;
			frame.addEventListener(
				'load',
				() => {
					clearTimeout( timeout );
					settle( frame.contentDocument ).then( () => resolve( frame ) );
				},
				{ once: true }
			);
			frame.src = url;
			document.body.appendChild( frame );
		} );

	/* Reading a page. */

	const LANDMARKS = 'header, nav, footer, aside, main, [role="banner"], [role="navigation"], [role="contentinfo"], [role="complementary"], [role="main"]';
	const REGIONS = {
		header: 'header',
		banner: 'header',
		nav: 'navigation',
		navigation: 'navigation',
		footer: 'footer',
		contentinfo: 'footer',
		aside: 'sidebar',
		complementary: 'sidebar',
		main: 'main',
	};
	// Buttons, and links styled as buttons: builders give such links "button" or "btn" in a class.
	const BUTTONS = 'button, [role="button"], input[type="submit"], input[type="button"], a[class*="button"], a[class*="btn"]';
	// Elements that hold one text a visitor reads as a whole. Links and buttons inside a paragraph belong to the paragraph.
	const BLOCKS = 'p, h1, h2, h3, h4, h5, h6, li, figcaption, blockquote, label, legend, td, th, dt, dd, summary, option';
	const PHP_MESSAGES = /^(Warning|Notice|Deprecated|Fatal error|Parse error)$/;

	const regionOf = ( element ) => {
		if ( element.closest( 'head' ) ) {
			return 'head';
		}
		const landmark = element.closest( LANDMARKS );
		if ( ! landmark ) {
			return 'page';
		}
		return REGIONS[ landmark.getAttribute( 'role' ) ] || REGIONS[ landmark.tagName.toLowerCase() ] || 'page';
	};

	const flatText = ( element ) => element.textContent.replace( /\s+/g, ' ' ).trim();

	const kindOf = ( element ) => {
		if ( element.closest( 'h1, h2, h3, h4, h5, h6' ) ) {
			return 'heading';
		}
		if ( element.closest( BUTTONS ) ) {
			return 'button';
		}
		if ( element.closest( 'figcaption' ) ) {
			return 'caption';
		}
		// A link is the kind of a text only when it is the whole text, as in a menu. A link inside a
		// paragraph, such as a footnote at its end, leaves the text a paragraph.
		const link = element.closest( 'a' );
		const block = element.closest( BLOCKS );
		if ( link && ( ! block || ! block.contains( link ) || flatText( link ) === flatText( block ) ) ) {
			return 'link';
		}
		if ( element.closest( 'li' ) ) {
			return 'list';
		}
		if ( element.closest( 'label, legend' ) ) {
			return 'label';
		}
		if ( element.closest( 'blockquote' ) ) {
			return 'quote';
		}
		return 'paragraph';
	};

	// A PHP message printed into the page, as a site with debugging on prints it: <b>Warning</b>: …
	const isPhpMessage = ( node ) => {
		const label = node.previousSibling;
		return !! label && 'B' === label.nodeName && PHP_MESSAGES.test( label.textContent.trim() );
	};

	// Where a marker in text sits.
	const textPlace = ( element ) => {
		if ( element.closest( 'title' ) ) {
			return 'head-title';
		}
		const script = element.closest( 'script' );
		if ( script ) {
			return 'application/ld+json' === script.type ? 'jsonld' : 'script';
		}
		if ( element.closest( 'style' ) ) {
			return 'style';
		}
		if ( element.closest( 'noscript, template' ) ) {
			return 'hidden';
		}
		return 'text';
	};

	// Where an address shows as an image, a video or audio, or in a frame such as a video player or a map.
	const mediaPlace = ( element, tag, name ) => {
		const source = 'src' === name || 'srcset' === name;
		if ( ( source && 'img' === tag ) || ( 'poster' === name && 'video' === tag ) ) {
			return 'image';
		}
		if ( source && 'source' === tag ) {
			return element.parentElement && 'PICTURE' === element.parentElement.tagName ? 'image' : 'video';
		}
		if ( ( 'src' === name && [ 'video', 'audio', 'embed' ].includes( tag ) ) || ( 'data' === name && 'object' === tag ) ) {
			return 'video';
		}
		return 'src' === name && 'iframe' === tag ? 'frame' : '';
	};

	// Where a marker in an attribute sits.
	const attributePlace = ( element, name ) => {
		const tag = element.tagName.toLowerCase();

		// In the head of the page only the description for search engines and social networks is read
		// by people. Titles of feed and oEmbed links are not.
		if ( element.closest( 'head' ) && ! ( 'meta' === tag && 'content' === name ) ) {
			return 'head-attr';
		}
		const plain = { alt: 'alt', title: 'title', placeholder: 'placeholder', label: 'label', class: 'class', style: 'style' };

		if ( plain[ name ] ) {
			return plain[ name ];
		}
		if ( [ 'aria-label', 'aria-description', 'aria-roledescription', 'aria-valuetext' ].includes( name ) ) {
			return 'aria-label';
		}
		if ( 'value' === name && ( 'button' === tag || 'option' === tag || ( 'input' === tag && [ 'submit', 'button', 'reset' ].includes( element.type ) ) ) ) {
			return 'value';
		}
		if ( 'content' === name && 'meta' === tag ) {
			const key = ( element.getAttribute( 'name' ) || element.getAttribute( 'property' ) || '' ).toLowerCase();
			return /^(description|og:title|og:description|og:site_name|twitter:title|twitter:description)$/.test( key ) ? 'meta' : 'attr';
		}
		if ( 'href' === name && ( 'a' === tag || 'area' === tag ) ) {
			return 'href';
		}
		const media = mediaPlace( element, tag, name );
		if ( media ) {
			return media;
		}
		if ( [ 'href', 'src', 'srcset', 'action', 'formaction', 'poster', 'data' ].includes( name ) ) {
			return 'address';
		}
		if ( [ 'id', 'for', 'name', 'aria-controls', 'aria-labelledby', 'aria-describedby' ].includes( name ) ) {
			return 'id';
		}
		return name.startsWith( 'data-' ) ? 'data' : 'attr';
	};

	const attributeKind = ( element, place ) => {
		if ( 'alt' === place ) {
			return 'alt';
		}
		if ( 'value' === place ) {
			return 'button';
		}
		if ( 'meta' === place ) {
			const key = ( element.getAttribute( 'name' ) || element.getAttribute( 'property' ) || '' ).toLowerCase();
			const title = key.endsWith( 'title' ) || key.endsWith( 'site_name' );
			// What shows when the page is shared, og: and twitter:, is not what search engines show.
			if ( key.startsWith( 'og:' ) || key.startsWith( 'twitter:' ) ) {
				return title ? 'social_title' : 'social_description';
			}
			return title ? 'seo_title' : 'meta_description';
		}
		return 'label';
	};

	// The probe wraps the post's own content in two comments. Text between them is the page's text;
	// text outside them belongs to the header, the footer or the rest of the site.
	const CONTENT_START = 'swiftling:content';
	const CONTENT_END = '/swiftling:content';

	// Finds every marker on the marked page, in document order. Each landing names its element, the
	// link around it, so the server can join a link's address with the text inside the link, and
	// whether it is in the post's content.
	const scanMarked = ( doc ) => {
		const found = {};
		let order = 0;
		let depth = 0;
		let scoped = false;
		const inContent = new Map();
		const numbers = new Map();
		const numberOf = ( element ) => {
			if ( ! element ) {
				return -1;
			}
			if ( ! numbers.has( element ) ) {
				numbers.set( element, numbers.size );
			}
			return numbers.get( element );
		};
		const add = ( text, element, landing ) => {
			for ( const match of text.matchAll( MARKER ) ) {
				( found[ match[ 1 ] ] = found[ match[ 1 ] ] || [] ).push( {
					...landing,
					order: order++,
					el: numberOf( element ),
					link: numberOf( element.closest( 'a' ) ),
					content: inContent.get( element ) ? 1 : 0,
				} );
			}
		};

		const walker = doc.createTreeWalker( doc.documentElement, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT | NodeFilter.SHOW_COMMENT );
		for ( let node = walker.currentNode; node; node = walker.nextNode() ) {
			if ( Node.COMMENT_NODE === node.nodeType ) {
				if ( CONTENT_START === node.nodeValue ) {
					++depth;
					scoped = true;
				} else if ( CONTENT_END === node.nodeValue ) {
					depth = Math.max( 0, depth - 1 );
				}
				continue;
			}
			if ( Node.ELEMENT_NODE === node.nodeType ) {
				inContent.set( node, depth > 0 );
			}
			if ( Node.TEXT_NODE === node.nodeType ) {
				const element = node.parentElement;
				if ( element && 'swiftling-probe' !== element.id && HAS_MARKER.test( node.nodeValue ) ) {
					const where = isPhpMessage( node ) ? 'php-message' : textPlace( element );
					const kinds = { 'head-title': 'seo_title', jsonld: 'structured' };
					add( node.nodeValue, element, { where, kind: kinds[ where ] || kindOf( element ), region: regionOf( element ) } );
				}
				continue;
			}
			for ( const attribute of node.attributes ) {
				if ( HAS_MARKER.test( attribute.value ) ) {
					const where = attributePlace( node, attribute.name );
					add( attribute.value, node, { where, kind: attributeKind( node, where ), region: regionOf( node ) } );
				}
			}
		}

		// A marker split over several elements, as letter-by-letter animations split text.
		const whole = doc.body ? doc.body.textContent : '';
		for ( const match of whole.matchAll( MARKER ) ) {
			if ( found[ match[ 1 ] ] ) {
				continue;
			}
			const needle = match[ 0 ].toLowerCase();
			let deepest = null;
			doc.body.querySelectorAll( '*' ).forEach( ( element ) => {
				if ( element.textContent.toLowerCase().includes( needle ) ) {
					deepest = element;
				}
			} );
			if ( deepest ) {
				add( match[ 0 ], deepest, { where: textPlace( deepest ), kind: kindOf( deepest ), region: regionOf( deepest ) } );
			}
		}

		return { found, scoped };
	};

	// Collects the texts a visitor sees. Entries marked w are wrappers of several texts, such as a
	// text widget with paragraphs; they help match values but are not texts of their own. Text
	// under translate="no" is meant to stay as it is, such as the language names of the switcher.
	const NOT_TRANSLATED = '[translate="no"]';
	const scanTexts = ( doc ) => {
		const texts = [];
		const seen = new Set();
		// The text of an element without the texts of blocks nested in it, such as a submenu in a menu item.
		const ownText = ( element ) => {
			let text = '';
			element.childNodes.forEach( ( child ) => {
				if ( Node.TEXT_NODE === child.nodeType ) {
					text += child.nodeValue;
				} else if ( Node.ELEMENT_NODE === child.nodeType && ! child.matches( BLOCKS + ', script, style, noscript, template' ) ) {
					text += ownText( child );
				}
			} );
			return text;
		};
		let depth = 0;
		const push = ( element, wrapper, text, kind ) => {
			const clean = ( text || ( wrapper ? element.textContent : ownText( element ) ) ).replace( /\s+/g, ' ' ).trim();
			const content = depth > 0 ? 1 : 0;
			if ( clean.length < 2 || ! LETTER.test( clean ) || seen.has( content + clean ) ) {
				return;
			}
			seen.add( content + clean );
			texts.push( { t: clean, k: kind || kindOf( element ), r: regionOf( element ), w: wrapper ? 1 : 0, c: content } );
		};

		const walker = doc.createTreeWalker( doc.body, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_COMMENT | NodeFilter.SHOW_ELEMENT );
		for ( let node = walker.nextNode(); node; node = walker.nextNode() ) {
			if ( Node.COMMENT_NODE === node.nodeType ) {
				if ( CONTENT_START === node.nodeValue ) {
					++depth;
				} else if ( CONTENT_END === node.nodeValue ) {
					depth = Math.max( 0, depth - 1 );
				}
				continue;
			}
			if ( Node.ELEMENT_NODE === node.nodeType ) {
				if ( 'IMG' === node.tagName && node.getAttribute( 'alt' ) && ! node.closest( NOT_TRANSLATED ) ) {
					push( node, false, node.getAttribute( 'alt' ), 'alt' );
				}
				continue;
			}
			const element = node.parentElement;
			if ( ! element || element.closest( 'script, style, noscript, template, ' + NOT_TRANSLATED ) || ! LETTER.test( node.nodeValue ) || isPhpMessage( node ) ) {
				continue;
			}
			const block = element.closest( BLOCKS ) || element;
			push( block, false );
			if ( block.parentElement && block.parentElement !== doc.body ) {
				push( block.parentElement, true );
			}
		}

		return texts;
	};

	/* Both frames of a page check. */

	// Opens the marked page and the page as visitors see it, reads them and closes them again.
	// opened() is called as each frame finishes loading.
	const check = async ( probes, opened = () => {} ) => {
		const view = ( url ) =>
			openFrame( url ).then( ( frame ) => {
				opened();
				return frame;
			} );
		const [ marked, control ] = await Promise.all( [ view( probes.marked ), view( probes.control ) ] );
		try {
			const registry = marked.contentDocument.getElementById( 'swiftling-probe' );
			if ( ! registry ) {
				throw new Error( 'The checked page did not list its values.' );
			}
			const markers = scanMarked( marked.contentDocument );
			return {
				registry: registry.textContent,
				signature: registry.dataset.signature,
				found: markers.found,
				scoped: markers.scoped,
				control: scanTexts( control.contentDocument ),
				// The title of the page as visitors see it, which scanTexts() leaves out: it is in the head.
				title: control.contentDocument.title,
			};
		} finally {
			marked.remove();
			control.remove();
		}
	};

	window.swiftlingPageCheck = { check, openFrame, scanMarked, scanTexts };
} )();
