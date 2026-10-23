<?php
/**
 * Reading a checked page on the server, as assets/js/page-check.js reads it in the browser.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\PageCheck;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use SplObjectStorage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The DOM's own properties, as nodeValue and parentNode.

/**
 * Reads the two pages of a page check, the page with a marker on every value that looks like text
 * and the page as visitors see it, as assets/js/page-check.js reads them in the browser, and
 * returns what it returns: where each marker landed, and the texts a visitor sees. The two are
 * kept alike rule by rule: a change to one belongs in the other.
 *
 * One difference: the browser reads the pages after their scripts ran, this reads them as the
 * server sends them. A text a script builds after the page loads is found only in the browser.
 */
final class Reader {
	/**
	 * A marker of a value, with its number.
	 */
	private const MARKER = '/zqk(\d+)kqz/i';

	/**
	 * Comments the probe wraps the post's own content in.
	 */
	private const CONTENT_START = 'swiftling:content';
	private const CONTENT_END   = '/swiftling:content';

	/**
	 * Regions of a page by landmark: tag or role => region.
	 */
	private const REGIONS = array(
		'header'        => 'header',
		'banner'        => 'header',
		'nav'           => 'navigation',
		'navigation'    => 'navigation',
		'footer'        => 'footer',
		'contentinfo'   => 'footer',
		'aside'         => 'sidebar',
		'complementary' => 'sidebar',
		'main'          => 'main',
	);

	/**
	 * Elements that hold one text a visitor reads as a whole.
	 */
	private const BLOCKS = array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'figcaption', 'blockquote', 'label', 'legend', 'td', 'th', 'dt', 'dd', 'summary', 'option' );

	/**
	 * Labels of messages PHP prints into a page with debugging on.
	 */
	private const PHP_MESSAGES = '/^(Warning|Notice|Deprecated|Fatal error|Parse error)$/';

	/**
	 * Numbers given to elements in the order they hold markers.
	 *
	 * @var SplObjectStorage
	 */
	private $numbers;

	/**
	 * Whether each element is in the post's own content.
	 *
	 * @var SplObjectStorage
	 */
	private $in_content;

	/**
	 * Reads the page with markers: the signed list of marked values, where each marker landed, and
	 * whether the page showed where the post's content is.
	 *
	 * @param string $html The page.
	 * @return array{registry: string, signature: string, found: array, scoped: bool}|null Null when the
	 *         page has no list of marked values.
	 */
	public function marked( string $html ): ?array {
		$doc      = self::parse( $html );
		$registry = $doc ? self::registry( $doc ) : null;
		if ( ! $doc || null === $registry ) {
			return null;
		}

		$this->numbers    = new SplObjectStorage();
		$this->in_content = new SplObjectStorage();
		$found            = array();
		$order            = 0;
		$depth            = 0;
		$scoped           = false;
		$add              = function ( string $text, DOMElement $element, array $landing ) use ( &$found, &$order ) {
			if ( ! preg_match_all( self::MARKER, $text, $matches ) ) {
				return;
			}
			foreach ( $matches[1] as $number ) {
				$found[ (string) (int) $number ][] = $landing + array(
					'order'   => $order++,
					'el'      => $this->numberOf( $element ),
					'link'    => $this->numberOf( self::closest( $element, array( 'a' ) ) ),
					'content' => isset( $this->in_content[ $element ] ) && $this->in_content[ $element ] ? 1 : 0,
				);
			}
		};

		self::walk(
			$doc->documentElement,
			function ( DOMNode $node ) use ( &$depth, &$scoped, $add ) {
				if ( $node instanceof DOMComment ) {
					if ( self::CONTENT_START === $node->nodeValue ) {
						++$depth;
						$scoped = true;
					} elseif ( self::CONTENT_END === $node->nodeValue ) {
						$depth = max( 0, $depth - 1 );
					}
					return;
				}
				if ( $node instanceof DOMElement ) {
					$this->in_content[ $node ] = $depth > 0;
					foreach ( $node->attributes as $attribute ) {
						if ( preg_match( self::MARKER, (string) $attribute->value ) ) {
							$where = self::attributePlace( $node, strtolower( $attribute->name ) );
							$add(
								(string) $attribute->value,
								$node,
								array(
									'where'  => $where,
									'kind'   => self::attributeKind( $node, $where ),
									'region' => self::regionOf( $node ),
								)
							);
						}
					}
					return;
				}
				if ( $node instanceof DOMText ) {
					$element = $node->parentNode;
					if ( ! $element instanceof DOMElement || 'swiftling-probe' === $element->getAttribute( 'id' ) || ! preg_match( self::MARKER, (string) $node->nodeValue ) ) {
						return;
					}
					$where = self::isPhpMessage( $node ) ? 'php-message' : self::textPlace( $element );
					$kinds = array(
						'head-title' => 'seo_title',
						'jsonld'     => 'structured',
					);
					$add(
						(string) $node->nodeValue,
						$element,
						array(
							'where'  => $where,
							'kind'   => $kinds[ $where ] ?? self::kindOf( $element ),
							'region' => self::regionOf( $element ),
						)
					);
				}
			}
		);

		// A marker split over several elements, as letter-by-letter animations split text.
		$body = $doc->getElementsByTagName( 'body' )->item( 0 );
		if ( $body instanceof DOMElement && preg_match_all( self::MARKER, (string) $body->textContent, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( isset( $found[ (string) (int) $match[1] ] ) ) {
					continue;
				}
				$needle  = strtolower( $match[0] );
				$deepest = null;
				foreach ( $body->getElementsByTagName( '*' ) as $element ) {
					if ( false !== strpos( strtolower( (string) $element->textContent ), $needle ) ) {
						$deepest = $element;
					}
				}
				if ( $deepest instanceof DOMElement ) {
					$add(
						$match[0],
						$deepest,
						array(
							'where'  => self::textPlace( $deepest ),
							'kind'   => self::kindOf( $deepest ),
							'region' => self::regionOf( $deepest ),
						)
					);
				}
			}
		}

		return array(
			'registry'  => $registry['payload'],
			'signature' => $registry['signature'],
			'found'     => $found,
			'scoped'    => $scoped,
		);
	}

	/**
	 * Reads the page as visitors see it: the texts a visitor reads, each with its kind, region,
	 * whether it wraps several texts and whether it is in the post's content; and the page's title.
	 *
	 * @param string $html The page.
	 * @return array{texts: array<int, array{t: string, k: string, r: string, w: int, c: int}>, title: string}
	 */
	public function control( string $html ): array {
		$doc  = self::parse( $html );
		$body = $doc ? $doc->getElementsByTagName( 'body' )->item( 0 ) : null;
		if ( ! $body instanceof DOMElement ) {
			return array(
				'texts' => array(),
				'title' => '',
			);
		}

		$texts = array();
		$seen  = array();
		$depth = 0;
		$push  = function ( DOMElement $element, bool $wrapper, string $text = '', string $kind = '' ) use ( &$texts, &$seen, &$depth ) {
			$clean   = self::flat( '' !== $text ? $text : ( $wrapper ? (string) $element->textContent : self::ownText( $element ) ) );
			$content = $depth > 0 ? 1 : 0;
			if ( mb_strlen( $clean ) < 2 || ! preg_match( '/\p{L}/u', $clean ) || isset( $seen[ $content . $clean ] ) ) {
				return;
			}
			$seen[ $content . $clean ] = true;
			$texts[]                   = array(
				't' => $clean,
				'k' => '' !== $kind ? $kind : self::kindOf( $element ),
				'r' => self::regionOf( $element ),
				'w' => $wrapper ? 1 : 0,
				'c' => $content,
			);
		};

		foreach ( $body->childNodes as $child ) {
			self::walk(
				$child,
				function ( DOMNode $node ) use ( &$depth, $push, $body ) {
					if ( $node instanceof DOMComment ) {
						if ( self::CONTENT_START === $node->nodeValue ) {
							++$depth;
						} elseif ( self::CONTENT_END === $node->nodeValue ) {
							$depth = max( 0, $depth - 1 );
						}
						return;
					}
					if ( $node instanceof DOMElement ) {
						if ( 'img' === strtolower( $node->tagName ) && '' !== $node->getAttribute( 'alt' ) && ! self::closestUntranslated( $node ) ) {
							$push( $node, false, $node->getAttribute( 'alt' ), 'alt' );
						}
						return;
					}
					if ( ! $node instanceof DOMText ) {
						return;
					}
					$element = $node->parentNode;
					if ( ! $element instanceof DOMElement || self::closest( $element, array( 'script', 'style', 'noscript', 'template' ) ) || self::closestUntranslated( $element ) || ! preg_match( '/\p{L}/u', (string) $node->nodeValue ) || self::isPhpMessage( $node ) ) {
						return;
					}
					$block = self::closest( $element, self::BLOCKS );
					$block = $block ? $block : $element;
					$push( $block, false );
					if ( $block->parentNode instanceof DOMElement && ! $block->parentNode->isSameNode( $body ) ) {
						$push( $block->parentNode, true );
					}
				}
			);
		}

		$title = $doc->getElementsByTagName( 'title' )->item( 0 );
		return array(
			'texts' => $texts,
			'title' => $title ? trim( (string) $title->textContent ) : '',
		);
	}

	/**
	 * Parses a page as HTML, its text as UTF-8.
	 *
	 * @param string $html The page.
	 */
	private static function parse( string $html ): ?DOMDocument {
		if ( '' === trim( $html ) ) {
			return null;
		}
		$doc      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The declaration makes libxml read the page as UTF-8, whatever its meta tags say.
		$loaded = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $loaded && $doc->documentElement ? $doc : null;
	}

	/**
	 * Returns the signed list of marked values the probe printed: its JSON and its signature.
	 *
	 * @param DOMDocument $doc The page with markers.
	 * @return array{payload: string, signature: string}|null
	 */
	private static function registry( DOMDocument $doc ): ?array {
		foreach ( $doc->getElementsByTagName( 'script' ) as $script ) {
			if ( 'swiftling-probe' === $script->getAttribute( 'id' ) ) {
				return array(
					'payload'   => (string) $script->textContent,
					'signature' => $script->getAttribute( 'data-signature' ),
				);
			}
		}
		return null;
	}

	/**
	 * Visits a node and everything in it, in document order, as the browser's tree walker does: the
	 * content of a template is not part of the page, so it is not visited.
	 *
	 * @param DOMNode  $node  Node.
	 * @param callable $visit Called with each node.
	 */
	private static function walk( DOMNode $node, callable $visit ): void {
		$visit( $node );
		if ( $node instanceof DOMElement && 'template' === strtolower( $node->tagName ) ) {
			return;
		}
		if ( $node->hasChildNodes() ) {
			foreach ( iterator_to_array( $node->childNodes ) as $child ) {
				self::walk( $child, $visit );
			}
		}
	}

	/**
	 * Returns the number of an element, given in the order elements come up; -1 for none.
	 *
	 * @param DOMElement|null $element Element.
	 */
	private function numberOf( ?DOMElement $element ): int {
		if ( ! $element ) {
			return -1;
		}
		if ( ! isset( $this->numbers[ $element ] ) ) {
			$this->numbers[ $element ] = count( $this->numbers );
		}
		return (int) $this->numbers[ $element ];
	}

	/**
	 * Returns the element or its nearest ancestor of one of the tags, as Element.closest() does.
	 *
	 * @param DOMNode|null $node Node.
	 * @param string[]     $tags Tags, in lower case.
	 */
	private static function closest( ?DOMNode $node, array $tags ): ?DOMElement {
		for ( ; $node; $node = $node->parentNode ) {
			if ( $node instanceof DOMElement && in_array( strtolower( $node->tagName ), $tags, true ) ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Returns the element or its nearest ancestor that matches a test.
	 *
	 * @param DOMNode|null $node Node.
	 * @param callable     $test Test of an element.
	 */
	private static function closestWhere( ?DOMNode $node, callable $test ): ?DOMElement {
		for ( ; $node; $node = $node->parentNode ) {
			if ( $node instanceof DOMElement && $test( $node ) ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Returns the element or its nearest ancestor that is a button, or a link styled as one.
	 *
	 * @param DOMNode $node Node.
	 */
	private static function closestButton( DOMNode $node ): ?DOMElement {
		return self::closestWhere(
			$node,
			static function ( DOMElement $element ) {
				$tag = strtolower( $element->tagName );
				if ( 'button' === $tag || 'button' === strtolower( $element->getAttribute( 'role' ) ) ) {
					return true;
				}
				if ( 'input' === $tag && in_array( strtolower( $element->getAttribute( 'type' ) ), array( 'submit', 'button' ), true ) ) {
					return true;
				}
				return 'a' === $tag && ( false !== strpos( $element->getAttribute( 'class' ), 'button' ) || false !== strpos( $element->getAttribute( 'class' ), 'btn' ) );
			}
		);
	}

	/**
	 * Whether a node is in an element marked translate="no".
	 *
	 * @param DOMNode $node Node.
	 */
	private static function closestUntranslated( DOMNode $node ): bool {
		return null !== self::closestWhere(
			$node,
			static function ( DOMElement $element ) {
				return $element->hasAttribute( 'translate' ) && 'no' === $element->getAttribute( 'translate' );
			}
		);
	}

	/**
	 * Returns the region of the page an element is in.
	 *
	 * @param DOMElement $element Element.
	 */
	private static function regionOf( DOMElement $element ): string {
		if ( self::closest( $element, array( 'head' ) ) ) {
			return 'head';
		}
		$landmark = self::closestWhere(
			$element,
			static function ( DOMElement $candidate ) {
				return in_array( strtolower( $candidate->tagName ), array( 'header', 'nav', 'footer', 'aside', 'main' ), true )
					|| in_array( $candidate->getAttribute( 'role' ), array( 'banner', 'navigation', 'contentinfo', 'complementary', 'main' ), true );
			}
		);
		if ( ! $landmark ) {
			return 'page';
		}
		return self::REGIONS[ $landmark->getAttribute( 'role' ) ] ?? self::REGIONS[ strtolower( $landmark->tagName ) ] ?? 'page';
	}

	/**
	 * Returns a text with its white space as one space, trimmed.
	 *
	 * @param string $text Text.
	 */
	private static function flat( string $text ): string {
		return trim( (string) preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) );
	}

	/**
	 * Returns the kind of text an element holds.
	 *
	 * @param DOMElement $element Element.
	 */
	private static function kindOf( DOMElement $element ): string {
		if ( self::closest( $element, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ) ) {
			return 'heading';
		}
		if ( self::closestButton( $element ) ) {
			return 'button';
		}
		if ( self::closest( $element, array( 'figcaption' ) ) ) {
			return 'caption';
		}
		// A link is the kind of a text only when it is the whole text, as in a menu. A link inside a
		// paragraph, such as a footnote at its end, leaves the text a paragraph.
		$link  = self::closest( $element, array( 'a' ) );
		$block = self::closest( $element, self::BLOCKS );
		if ( $link && ( ! $block || ! self::contains( $block, $link ) || self::flat( (string) $link->textContent ) === self::flat( (string) $block->textContent ) ) ) {
			return 'link';
		}
		if ( self::closest( $element, array( 'li' ) ) ) {
			return 'list';
		}
		if ( self::closest( $element, array( 'label', 'legend' ) ) ) {
			return 'label';
		}
		if ( self::closest( $element, array( 'blockquote' ) ) ) {
			return 'quote';
		}
		return 'paragraph';
	}

	/**
	 * Whether a node is an element or is inside it, as Node.contains() says.
	 *
	 * @param DOMNode $outer Element.
	 * @param DOMNode $node  Node.
	 */
	private static function contains( DOMNode $outer, DOMNode $node ): bool {
		for ( ; $node; $node = $node->parentNode ) {
			if ( $node->isSameNode( $outer ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a text is a message PHP printed into the page, as "<b>Warning</b>: …".
	 *
	 * @param DOMText $node Text.
	 */
	private static function isPhpMessage( DOMText $node ): bool {
		$label = $node->previousSibling;
		return $label instanceof DOMElement && 'b' === strtolower( $label->tagName ) && (bool) preg_match( self::PHP_MESSAGES, trim( (string) $label->textContent ) );
	}

	/**
	 * Returns where a marker in text sits.
	 *
	 * @param DOMElement $element The text's element.
	 */
	private static function textPlace( DOMElement $element ): string {
		if ( self::closest( $element, array( 'title' ) ) ) {
			return 'head-title';
		}
		$script = self::closest( $element, array( 'script' ) );
		if ( $script ) {
			return 'application/ld+json' === $script->getAttribute( 'type' ) ? 'jsonld' : 'script';
		}
		if ( self::closest( $element, array( 'style' ) ) ) {
			return 'style';
		}
		if ( self::closest( $element, array( 'noscript', 'template' ) ) ) {
			return 'hidden';
		}
		return 'text';
	}

	/**
	 * Returns where an address shows as an image, a video or audio, or in a frame.
	 *
	 * @param DOMElement $element Element.
	 * @param string     $tag     Its tag.
	 * @param string     $name    Attribute.
	 */
	private static function mediaPlace( DOMElement $element, string $tag, string $name ): string {
		$source = 'src' === $name || 'srcset' === $name;
		if ( ( $source && 'img' === $tag ) || ( 'poster' === $name && 'video' === $tag ) ) {
			return 'image';
		}
		if ( $source && 'source' === $tag ) {
			$parent = $element->parentNode;
			return $parent instanceof DOMElement && 'picture' === strtolower( $parent->tagName ) ? 'image' : 'video';
		}
		if ( ( 'src' === $name && in_array( $tag, array( 'video', 'audio', 'embed' ), true ) ) || ( 'data' === $name && 'object' === $tag ) ) {
			return 'video';
		}
		return 'src' === $name && 'iframe' === $tag ? 'frame' : '';
	}

	/**
	 * Returns where a marker in an attribute sits.
	 *
	 * @param DOMElement $element Element.
	 * @param string     $name    Attribute, in lower case.
	 */
	private static function attributePlace( DOMElement $element, string $name ): string {
		$tag = strtolower( $element->tagName );

		// In the head only the description for search engines and social networks is read by people.
		if ( self::closest( $element, array( 'head' ) ) && ! ( 'meta' === $tag && 'content' === $name ) ) {
			return 'head-attr';
		}
		$plain = array( 'alt', 'title', 'placeholder', 'label', 'class', 'style' );
		if ( in_array( $name, $plain, true ) ) {
			return $name;
		}
		if ( in_array( $name, array( 'aria-label', 'aria-description', 'aria-roledescription', 'aria-valuetext' ), true ) ) {
			return 'aria-label';
		}
		$type = strtolower( '' !== $element->getAttribute( 'type' ) ? $element->getAttribute( 'type' ) : 'text' );
		if ( 'value' === $name && ( 'button' === $tag || 'option' === $tag || ( 'input' === $tag && in_array( $type, array( 'submit', 'button', 'reset' ), true ) ) ) ) {
			return 'value';
		}
		if ( 'content' === $name && 'meta' === $tag ) {
			$key = strtolower( '' !== $element->getAttribute( 'name' ) ? $element->getAttribute( 'name' ) : $element->getAttribute( 'property' ) );
			return preg_match( '/^(description|og:title|og:description|og:site_name|twitter:title|twitter:description)$/', $key ) ? 'meta' : 'attr';
		}
		if ( 'href' === $name && ( 'a' === $tag || 'area' === $tag ) ) {
			return 'href';
		}
		$media = self::mediaPlace( $element, $tag, $name );
		if ( '' !== $media ) {
			return $media;
		}
		if ( in_array( $name, array( 'href', 'src', 'srcset', 'action', 'formaction', 'poster', 'data' ), true ) ) {
			return 'address';
		}
		if ( in_array( $name, array( 'id', 'for', 'name', 'aria-controls', 'aria-labelledby', 'aria-describedby' ), true ) ) {
			return 'id';
		}
		return 0 === strpos( $name, 'data-' ) ? 'data' : 'attr';
	}

	/**
	 * Returns the kind of text in an attribute.
	 *
	 * @param DOMElement $element Element.
	 * @param string     $place   Where the marker sits, see attributePlace().
	 */
	private static function attributeKind( DOMElement $element, string $place ): string {
		if ( 'alt' === $place ) {
			return 'alt';
		}
		if ( 'value' === $place ) {
			return 'button';
		}
		if ( 'meta' === $place ) {
			$key   = strtolower( '' !== $element->getAttribute( 'name' ) ? $element->getAttribute( 'name' ) : $element->getAttribute( 'property' ) );
			$title = '' !== $key && ( 'title' === substr( $key, -5 ) || 'site_name' === substr( $key, -9 ) );
			// What shows when the page is shared, og: and twitter:, is not what search engines show.
			if ( 0 === strpos( $key, 'og:' ) || 0 === strpos( $key, 'twitter:' ) ) {
				return $title ? 'social_title' : 'social_description';
			}
			return $title ? 'seo_title' : 'meta_description';
		}
		return 'label';
	}

	/**
	 * Returns the text of an element without the texts of blocks nested in it, such as a submenu in
	 * a menu item.
	 *
	 * @param DOMElement $element Element.
	 */
	private static function ownText( DOMElement $element ): string {
		$text = '';
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$text .= (string) $child->nodeValue;
			} elseif ( $child instanceof DOMElement && ! in_array( strtolower( $child->tagName ), array_merge( self::BLOCKS, array( 'script', 'style', 'noscript', 'template' ) ), true ) ) {
				$text .= self::ownText( $child );
			}
		}
		return $text;
	}
}
