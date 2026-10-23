<?php
/**
 * The language switcher for themes, in PHP.
 *
 * In a template:
 *
 *     <?php do_action( 'swiftling_language_switcher', array( 'style' => 'horizontal' ) ); ?>
 *
 * The action prints nothing when SwiftLing is not active, so the template keeps working without
 * it. swiftling_language_switcher() and swiftling_get_language_switcher() take the same options.
 * See src/Switcher for the switcher itself and its other places: block, shortcode, widget, menus
 * and footer.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the HTML of a language switcher.
 *
 * @param array $args {
 *     Optional. Anything left out follows the Switcher screen.
 *
 *     @type string $style   dropdown (default), horizontal or vertical.
 *     @type bool   $flag    Whether to show flags.
 *     @type bool   $name    Whether to show language names.
 *     @type bool   $code    Whether to show language codes, e.g. DE.
 *     @type bool   $current Whether a list shows the language the page is shown in.
 *     @type string $class   More classes for the outer element.
 * }
 * @return string Empty when the site has no other language to switch to.
 */
function swiftling_get_language_switcher( $args = array() ) {
	$args          = is_array( $args ) ? $args : array();
	$args['class'] = trim( 'is-php ' . ( isset( $args['class'] ) ? (string) $args['class'] : '' ) );
	return \SwiftLing\Switcher\Switcher::render( $args );
}

/**
 * Prints a language switcher.
 *
 * @param array $args See swiftling_get_language_switcher().
 */
function swiftling_language_switcher( $args = array() ) {
	echo swiftling_get_language_switcher( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped piece by piece in Switcher::render().
}
add_action( 'swiftling_language_switcher', 'swiftling_language_switcher' );
