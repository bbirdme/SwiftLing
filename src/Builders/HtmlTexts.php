<?php
/**
 * The texts of HTML a plugin keeps whole, one by one.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * HTML a plugin keeps as one value, with markup a translator should not touch: a booking form with
 * fields of the plugin's own, an email template laid out in tables with inline styles. Each text
 * between its tags is a text of its own, by its words, so it keeps its translation wherever it moves in
 * the HTML; the markup stays as it is. For a form, the texts a visitor reads in its attributes are too,
 * see ATTRIBUTES.
 */
final class HtmlTexts {
	/**
	 * The attributes that hold text a visitor reads or hears, and "value" for a button of a form only:
	 * the value of any other field is what the form sends.
	 */
	const ATTRIBUTES = array( 'placeholder', 'title', 'aria-label', 'alt', 'value' );

	/**
	 * Returns HTML with each of its texts given by a callback.
	 *
	 * @param string   $html       The HTML.
	 * @param callable $translate  Returns a text in the mode of the moment; given the text and its key.
	 * @param bool     $attributes Whether the texts of attributes count too, as in a form.
	 */
	public static function translate( string $html, callable $translate, bool $attributes = false ): string {
		if ( $attributes ) {
			$html = self::translateAttributes( $html, $translate );
		}
		return (string) preg_replace_callback(
			'/(^|>)([^<]+)(?=<|$)/u',
			static function ( $found ) use ( $translate ) {
				$text = trim( $found[2] );
				if ( ! preg_match( '/\p{L}/u', $text ) ) {
					return $found[0];
				}
				$translated = $translate( $text, self::key( $text ) );
				return $found[1] . str_replace( $text, is_string( $translated ) ? $translated : $text, $found[2] );
			},
			$html
		);
	}

	/**
	 * Returns HTML with the texts of the attributes of its tags given by a callback, see ATTRIBUTES.
	 *
	 * @param string   $html      The HTML.
	 * @param callable $translate As for translate().
	 */
	private static function translateAttributes( string $html, callable $translate ): string {
		return (string) preg_replace_callback(
			'/<([a-z][a-z0-9-]*)\b[^>]*>/i',
			static function ( $tag ) use ( $translate ) {
				$button = 'button' === strtolower( $tag[1] ) || ( 'input' === strtolower( $tag[1] ) && preg_match( '/\stype\s*=\s*["\']?(submit|button|reset)\b/i', $tag[0] ) );
				return (string) preg_replace_callback(
					'/(\s(' . implode( '|', self::ATTRIBUTES ) . ')\s*=\s*)(["\'])(.*?)\3/is',
					static function ( $attribute ) use ( $translate, $button ) {
						if ( 'value' === strtolower( $attribute[2] ) && ! $button ) {
							return $attribute[0];
						}
						$text = trim( html_entity_decode( $attribute[4], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
						if ( ! preg_match( '/\p{L}/u', $text ) ) {
							return $attribute[0];
						}
						$translated = $translate( $text, self::key( $text ) );
						if ( ! is_string( $translated ) || $translated === $text ) {
							return $attribute[0];
						}
						return $attribute[1] . $attribute[3] . esc_attr( $translated ) . $attribute[3];
					},
					$tag[0]
				);
			},
			$html
		);
	}

	/**
	 * Returns the key of a text of HTML, for its path: by its words.
	 *
	 * @param string $text The text.
	 */
	public static function key( string $text ): string {
		return substr( md5( $text ), 0, 8 );
	}

	/**
	 * Returns the texts of HTML, by key, as translate() hands them out.
	 *
	 * @param string $html       The HTML.
	 * @param bool   $attributes Whether the texts of attributes count too.
	 * @return array<string, string>
	 */
	public static function texts( string $html, bool $attributes = false ): array {
		$texts = array();
		self::translate(
			$html,
			static function ( $text, $key ) use ( &$texts ) {
				$texts[ $key ] = $text;
				return $text;
			},
			$attributes
		);
		return $texts;
	}
}
