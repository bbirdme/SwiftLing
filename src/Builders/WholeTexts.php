<?php
/**
 * Texts a plugin prints whole, put in the mode of the moment in what it printed.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * For a plugin that prints texts it keeps through no filter SwiftLing can answer: each text is put in
 * the mode of the moment where the plugin printed it whole, as the value of an attribute, as a string
 * of JSON or between tags, each printed the way its place asks. A text inside another value, as a word
 * of an address or of a longer text, stays as it is.
 */
final class WholeTexts {
	/**
	 * Returns what a plugin printed with each of the given texts in the mode of the moment, wherever it
	 * printed one whole. Only the texts found are handed to $own, so a page check notes those the page
	 * shows.
	 *
	 * @param string   $html  What the plugin printed.
	 * @param string[] $texts The texts the plugin keeps.
	 * @param callable $own   Returns a text in the mode of the moment.
	 */
	public static function replace( string $html, array $texts, callable $own ): string {
		$known   = array();
		$printed = array();
		$markup  = array();
		foreach ( $texts as $text ) {
			if ( ! is_string( $text ) || '' === trim( $text ) || isset( $known[ $text ] ) ) {
				continue;
			}
			$known[ $text ] = true;
			if ( false !== strpos( $text, '<' ) ) {
				$markup[] = $text;
				continue;
			}
			// As an attribute's value or between tags, escaped or not.
			foreach ( array( $text, esc_attr( $text ), esc_html( $text ), htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) ) as $form ) {
				$printed[ $form ] = $printed[ $form ] ?? $text;
			}
		}
		if ( ! $known ) {
			return $html;
		}
		$translations = array();
		$translate    = static function ( string $text ) use ( $own, &$translations ): string {
			if ( ! isset( $translations[ $text ] ) ) {
				$translated            = $own( $text );
				$translations[ $text ] = is_string( $translated ) ? $translated : $text;
			}
			return $translations[ $text ];
		};

		// The value of an attribute.
		$html = (string) preg_replace_callback(
			'/(=\s*)(["\'])([^"\'<>]*)\2/u',
			static function ( $found ) use ( $printed, $translate ) {
				if ( ! isset( $printed[ $found[3] ] ) ) {
					return $found[0];
				}
				$translated = $translate( $printed[ $found[3] ] );
				return $translated === $printed[ $found[3] ] ? $found[0] : $found[1] . $found[2] . esc_attr( $translated ) . $found[2];
			},
			$html
		);
		// A string of JSON, as a value of an object or an array.
		$html = (string) preg_replace_callback(
			'/([:\[,]\s*)"((?:[^"\\\\]|\\\\.)*)"/u',
			static function ( $found ) use ( $known, $translate ) {
				$value = json_decode( '"' . $found[2] . '"' );
				if ( ! is_string( $value ) || ! isset( $known[ $value ] ) ) {
					return $found[0];
				}
				$translated = $translate( $value );
				return $translated === $value ? $found[0] : $found[1] . wp_json_encode( $translated );
			},
			$html
		);
		// Between tags.
		$html = (string) preg_replace_callback(
			'/>(\s*)([^<>]*?)(\s*)</u',
			static function ( $found ) use ( $printed, $translate ) {
				if ( '' === $found[2] || ! isset( $printed[ $found[2] ] ) ) {
					return $found[0];
				}
				$text       = $printed[ $found[2] ];
				$translated = $translate( $text );
				if ( $translated === $text ) {
					return $found[0];
				}
				return '>' . $found[1] . ( $found[2] === $text ? $translated : esc_html( $translated ) ) . $found[3] . '<';
			},
			$html
		);
		// A text with tags of its own, whole between tags.
		foreach ( $markup as $text ) {
			if ( false === strpos( $html, $text ) ) {
				continue;
			}
			$translated = $translate( $text );
			if ( $translated !== $text ) {
				$html = (string) preg_replace_callback(
					'/>(\s*)' . preg_quote( $text, '/' ) . '(\s*)</u',
					static function ( $found ) use ( $translated ) {
						return '>' . $found[1] . $translated . $found[2] . '<';
					},
					$html
				);
			}
		}
		return $html;
	}
}
