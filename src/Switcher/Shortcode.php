<?php
/**
 * The [swiftling_switcher] shortcode.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * The [swiftling_switcher] shortcode, for content, page builders and anything else that runs
 * shortcodes:
 *
 *     [swiftling_switcher style="horizontal" flag="yes" name="yes" code="no" current="yes"]
 *     [swiftling_switcher flag="yes" flag_size="large"]
 *
 * Every attribute is optional; the saved settings fill in the rest, and the style is a dropdown.
 */
final class Shortcode {
	/**
	 * Registers the shortcode.
	 */
	public function register(): void {
		add_shortcode( 'swiftling_switcher', array( $this, 'render' ) );
	}

	/**
	 * Returns the switcher.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes; an empty string without any.
	 */
	public function render( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$args = array();
		if ( isset( $atts['style'] ) ) {
			$args['style'] = sanitize_key( $atts['style'] );
		}
		foreach ( array( 'flag', 'name', 'code', 'current' ) as $part ) {
			// "flags", "names" and "codes" read as naturally as the singular, so both work.
			$value = isset( $atts[ $part ] ) ? $atts[ $part ] : ( isset( $atts[ $part . 's' ] ) ? $atts[ $part . 's' ] : null );
			if ( null !== $value ) {
				$args[ $part ] = in_array( strtolower( trim( (string) $value ) ), array( '1', 'yes', 'true', 'on' ), true );
			}
		}
		if ( isset( $atts['flag_size'] ) ) {
			$args['flag_size'] = sanitize_key( $atts['flag_size'] );
		}
		$args['class'] = 'is-shortcode';
		return Switcher::render( $args );
	}
}
