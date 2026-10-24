<?php
/**
 * Breakdance: its header and footer, which it keeps in posts of their own.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Breakdance. It replaces the theme and renders every page itself, from its data in the post meta
 * "_breakdance_data", which it reads with get_post_meta(), so a page check finds its texts like any
 * others, see sources.php. That holds for its headers, footers, templates, global blocks and popups
 * too: each is a post of its own type, not opened on its own, whose texts the page shows.
 *
 * This class says which of those posts are the header and the footer every page shows, so the
 * Templates screen lists their texts there. A header made with its Header Builder element prints a
 * header tag, but a footer is printed as whatever its elements are, usually a section tag, so its
 * landmark cannot tell. Its other posts are left to the page check as before.
 *
 * Checked on Breakdance 3.0.0-rc.2 with a Free licence. Oxygen 6 runs on the same code with post
 * types of its own and is not covered: it was not checked.
 */
class Breakdance extends Builder {
	/**
	 * Returns the builder's ID.
	 */
	public function id(): string {
		return 'breakdance';
	}

	/**
	 * Returns the builder's name.
	 */
	public function name(): string {
		return 'Breakdance';
	}

	/**
	 * Whether Breakdance is active, and not Oxygen 6 on its code.
	 */
	public function isActive(): bool {
		return defined( 'BREAKDANCE_MODE' ) && 'breakdance' === BREAKDANCE_MODE;
	}

	/**
	 * Whether a post is built with Breakdance. Not said yet: the editor would then offer a separate
	 * copy as "changed by hand in Breakdance", and whether such a copy opens and saves in Breakdance
	 * was not checked.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Not said yet, see above.
		return false;
	}

	/**
	 * Returns which part of every page a post of Breakdance is: a post of its header or footer type.
	 * Which of several headers a page shows is decided by their conditions; each is listed.
	 *
	 * @param string $owner Object key.
	 * @return string header, footer, or empty.
	 */
	public function sitePart( string $owner ): string {
		if ( 0 !== strpos( $owner, 'post:' ) ) {
			return '';
		}
		$parts = array(
			'breakdance_header' => 'header',
			'breakdance_footer' => 'footer',
		);
		$type  = get_post_type( (int) substr( $owner, 5 ) );
		return is_string( $type ) && isset( $parts[ $type ] ) ? $parts[ $type ] : '';
	}
}
