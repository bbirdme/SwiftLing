<?php
/**
 * A page builder, for the tests of what the editor offers a post a builder built.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Builder;

/**
 * A page builder, active, that built the posts with the meta _fake_builder.
 */
class FakePageBuilder extends Builder {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'fakepagebuilder';
	}

	/**
	 * Returns the builder's name.
	 */
	public function name(): string {
		return 'Fake page builder';
	}

	/**
	 * Whether the builder counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Whether a post is built with it.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool {
		return (bool) get_post_meta( $post_id, '_fake_builder', true );
	}
}
