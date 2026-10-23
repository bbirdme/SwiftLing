<?php
/**
 * The HTML a machine translation service gets and gives back.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * What every translation service does with the HTML it sends, so link addresses and other
 * attributes stay on the site, the parts a text must keep come back unchanged, and plain text
 * travels as HTML. Google uses it; another plugin's service, as SwiftLing Pro's DeepL, can too. See
 * docs/hooks.md.
 *
 * - protect() and restore(): every tag with attributes goes as a short numbered tag, such as
 *   <a i="0">, and comes back whole; a translation that lost, doubled or renamed one is refused.
 * - keep() and unkeep(): parts to keep, such as "%1$s" or a word that is never translated, go in
 *   <span translate="no">; a translation that changed one is refused.
 * - plainToHtml() and htmlToPlain(): plain text as HTML and back.
 */
final class Markup {
	/**
	 * Replaces every tag that has attributes with a short numbered one, such as <a i="0"> for
	 * <a href="https://example.com/long/address/" class="button">. Closing tags and tags without
	 * attributes stay as they are.
	 *
	 * @param string $html Formatted text.
	 * @return array{0: string, 1: string[]} The text to send, and the tags taken out, by number.
	 */
	public static function protect( string $html ): array {
		$tags = array();
		$text = preg_replace_callback(
			'~<([a-zA-Z][a-zA-Z0-9-]*)(\s[^<>]*?)?(\s*/)?>~',
			static function ( array $found ) use ( &$tags ): string {
				if ( ! isset( $found[2] ) || '' === trim( $found[2] ) ) {
					return $found[0];
				}
				$tags[] = $found[0];
				return '<' . $found[1] . ' i="' . ( count( $tags ) - 1 ) . '"' . ( ! empty( $found[3] ) ? ' /' : '' ) . '>';
			},
			$html
		);
		return array( (string) $text, $tags );
	}

	/**
	 * Puts every part a text must keep, such as a placeholder or a word that is never translated,
	 * inside <span translate="no">, which Google and DeepL leave as it is. An empty tag such as <wbr>
	 * in its place does not work: Google drops it, measured on 2026-10-10. Parts inside a tag are left
	 * alone.
	 *
	 * @param string   $html Text to send, as HTML, its tags already numbered by protect().
	 * @param string[] $keep Parts to keep, such as "%1$s" or "Lavanda".
	 * @return array{0: string, 1: string[]} The text with the parts in spans, and the parts.
	 */
	public static function keep( string $html, array $keep ): array {
		$keep = array_values( array_filter( $keep, 'strlen' ) );
		if ( ! $keep ) {
			return array( $html, array() );
		}
		usort(
			$keep,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$parts = array();
		$html  = preg_replace_callback(
			'~(<[^<>]*>)|(' . implode(
				'|',
				array_map(
					static function ( string $part ): string {
						return preg_quote( $part, '~' );
					},
					$keep
				)
			) . ')~u',
			static function ( array $found ) use ( &$parts ): string {
				if ( '' !== $found[1] ) {
					return $found[1];
				}
				$parts[] = $found[2];
				return '<span translate="no">' . $found[2] . '</span>';
			},
			$html
		);
		return array( (string) $html, $parts );
	}

	/**
	 * Takes the parts keep() put in spans out of their spans again.
	 *
	 * @param string   $html  Translation, with its tags restored.
	 * @param string[] $parts Parts kept.
	 * @return string|null The translation, or null when a part is missing or changed.
	 */
	public static function unkeep( string $html, array $parts ): ?string {
		if ( ! $parts ) {
			return $html;
		}
		$found = array();
		$html  = preg_replace_callback(
			'~<span\s+translate\s*=\s*"?no"?\s*>(.*?)</span>~s',
			static function ( array $span ) use ( &$found ): string {
				$found[] = $span[1];
				return $span[1];
			},
			$html
		);
		sort( $found );
		sort( $parts );
		return $found === $parts ? (string) $html : null;
	}

	/**
	 * Puts the tags protect() took out back into a translation.
	 *
	 * @param string   $text Translation with numbered tags.
	 * @param string[] $tags Tags taken out, by number.
	 * @return string|null The translation, or null when a numbered tag went missing, came twice or
	 *                     changed its name, so the formatting cannot be trusted.
	 */
	public static function restore( string $text, array $tags ): ?string {
		$used   = array();
		$broken = false;
		$text   = preg_replace_callback(
			'~<([a-zA-Z][a-zA-Z0-9-]*)\s+i\s*=\s*"?(\d+)"?\s*/?\s*>~',
			static function ( array $found ) use ( $tags, &$used, &$broken ): string {
				$number = (int) $found[2];
				$name   = isset( $tags[ $number ] ) && preg_match( '~^<([a-zA-Z][a-zA-Z0-9-]*)~', $tags[ $number ], $original ) ? strtolower( $original[1] ) : '';
				if ( '' === $name || isset( $used[ $number ] ) || strtolower( $found[1] ) !== $name ) {
					$broken = true;
					return $found[0];
				}
				$used[ $number ] = true;
				return $tags[ $number ];
			},
			$text
		);
		return $broken || count( $used ) !== count( $tags ) ? null : (string) $text;
	}

	/**
	 * Returns plain text as HTML: special characters as entities, line breaks as <br>. Quotes stay
	 * as they are, since a service may charge the characters of every entity.
	 *
	 * @param string $text Plain text.
	 */
	public static function plainToHtml( string $text ): string {
		return str_replace( array( "\r\n", "\r", "\n" ), '<br>', htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
	}

	/**
	 * Returns a translation of plain text, which a service sends back as HTML, as plain text again.
	 *
	 * @param string $html Translation.
	 */
	public static function htmlToPlain( string $html ): string {
		$text = (string) preg_replace( '~[ \t]*<br\s*/?>[ \t]*~i', "\n", $html );
		return html_entity_decode( wp_strip_all_tags( $text, false ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
