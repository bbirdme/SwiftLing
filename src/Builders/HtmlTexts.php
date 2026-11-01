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
 * the HTML; the markup stays as it is.
 */
final class HtmlTexts {
	/**
	 * Returns HTML with each of its texts given by a callback.
	 *
	 * @param string   $html      The HTML.
	 * @param callable $translate Returns a text in the mode of the moment; given the text and its key.
	 */
	public static function translate( string $html, callable $translate ): string {
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
	 * @param string $html The HTML.
	 * @return array<string, string>
	 */
	public static function texts( string $html ): array {
		$texts = array();
		self::translate(
			$html,
			static function ( $text, $key ) use ( &$texts ) {
				$texts[ $key ] = $text;
				return $text;
			}
		);
		return $texts;
	}
}
