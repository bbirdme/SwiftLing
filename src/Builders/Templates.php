<?php
/**
 * Texts a plugin writes from templates with placeholders, such as its emails.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * What the classes of plugins that write emails from templates share: a template's placeholders kept as
 * they are when it goes to machine translation, and an email the plugin already wrote from the original
 * template written again from the template's translation, where the plugin offers no place to do it
 * before.
 */
final class Templates {
	/**
	 * Writes a text from the translation of its template, given the text the template wrote: the values
	 * of the placeholders are read from the text by the original template, and put into the translation.
	 *
	 * @param string        $original    The template, as stored.
	 * @param string        $translated  The template's translation.
	 * @param string        $written     The text the plugin wrote from the template.
	 * @param string        $placeholder Pattern of one placeholder, as '/\{[a-z_]+\}/i'.
	 * @param callable|null $value       Changes the value of a placeholder for the translation, as an
	 *                                   address to the page in the language; gets the value.
	 * @return string|null The text in the language; null when the text was not written from the template.
	 */
	public static function refill( string $original, string $translated, string $written, string $placeholder, ?callable $value = null ): ?string {
		$parts   = preg_split( $placeholder, $original, -1, PREG_SPLIT_OFFSET_CAPTURE );
		$found   = preg_match_all( $placeholder, $original, $names );
		$pattern = '';
		$seen    = array();
		foreach ( (array) $parts as $index => $part ) {
			$pattern .= preg_quote( $part[0], '#' );
			if ( $index >= (int) $found ) {
				continue;
			}
			$name = self::group( $names[0][ $index ] );
			if ( isset( $seen[ $name ] ) ) {
				$pattern .= '(?P=' . $name . ')';
			} else {
				$pattern      .= '(?P<' . $name . '>.*?)';
				$seen[ $name ] = true;
			}
		}
		if ( ! preg_match( '#^' . $pattern . '$#s', $written, $values ) ) {
			return null;
		}
		if ( $translated === $original && null === $value ) {
			return $written;
		}
		return (string) preg_replace_callback(
			$placeholder,
			static function ( $hit ) use ( $values, $value ) {
				$name = self::group( $hit[0] );
				if ( ! isset( $values[ $name ] ) ) {
					return $hit[0];
				}
				return null === $value ? $values[ $name ] : (string) $value( $values[ $name ] );
			},
			$translated
		);
	}

	/**
	 * Returns the name of the group a placeholder's value is read into: the same for the same
	 * placeholder in any case.
	 *
	 * @param string $placeholder The placeholder, as the template has it.
	 */
	private static function group( string $placeholder ): string {
		return 'p' . substr( md5( strtolower( $placeholder ) ), 0, 16 );
	}

	/**
	 * Keeps the placeholders of a plugin's templates as they are when a text goes to machine
	 * translation, for the filter swiftling_machine_items.
	 *
	 * @param mixed  $items       Texts sent, by key.
	 * @param string $placeholder Pattern of one placeholder.
	 * @return mixed
	 */
	public static function keepPlaceholders( $items, string $placeholder ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) && preg_match_all( $placeholder, $item['text'], $found ) ) {
				$keep                  = isset( $item['keep'] ) && is_array( $item['keep'] ) ? $item['keep'] : array();
				$items[ $key ]['keep'] = array_values( array_unique( array_merge( $keep, $found[0] ) ) );
			}
		}
		return $items;
	}
}
