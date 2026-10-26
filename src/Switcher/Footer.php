<?php
/**
 * The switcher at the end of every page, or floating in a corner.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * The switcher at the end of every page, after the footer of the theme, or floating in a corner.
 * Switched on and set up on the Switcher screen.
 */
final class Footer {
	/**
	 * Hooks the switcher into the end of the page, and what it needs into the head.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
	}

	/**
	 * Enqueues the stylesheet and script before the page renders. The switcher itself prints after
	 * WordPress has printed the stylesheets and scripts of the footer, at the same priority of
	 * wp_footer, so what it enqueued then never reached the page: without another switcher on the
	 * page, it showed as a bare list, and the floating one at the end of the page.
	 */
	public function enqueue(): void {
		$args = $this->args();
		if ( $args && count( Switcher::languages() ) > 1 ) {
			Switcher::enqueue( $args['style'] );
		}
	}

	/**
	 * Prints the switcher when the Switcher screen asks for it.
	 */
	public function render(): void {
		$args = $this->args();
		if ( ! $args ) {
			return;
		}
		echo wp_kses( Switcher::render( $args ), Switcher::allowedHtml() );
	}

	/**
	 * Returns the style and class of the switcher, as the Switcher screen sets it.
	 *
	 * @return array{place: string, style: string, class: string}|null Null when the screen does not ask for it.
	 */
	private function args(): ?array {
		$footer = Settings::get()['footer'];
		if ( ! $footer['enabled'] ) {
			return null;
		}

		$floating = 'end' !== $footer['position'];
		// A list floating in a corner would cover the page; there it opens as a dropdown.
		return array(
			'place' => 'footer',
			'style' => $floating && 'dropdown' !== $footer['style'] ? 'dropdown' : $footer['style'],
			'class' => 'is-footer' . ( $floating ? ' is-floating is-' . $footer['position'] : '' ),
		);
	}
}
