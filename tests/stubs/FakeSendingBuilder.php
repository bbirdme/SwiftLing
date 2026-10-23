<?php
/**
 * A form plugin that receives its forms at a route of the REST API, for the tests of the language
 * of a form sent.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Builder;

/**
 * A form plugin, active, that names the route Contact Form 7 receives its forms at.
 */
class FakeSendingBuilder extends Builder {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'fakesending';
	}

	/**
	 * Returns the plugin's name.
	 */
	public function name(): string {
		return 'Fake sending';
	}

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Whether a post is built with it: never.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- No post is.
		return false;
	}

	/**
	 * Returns the routes of the REST API it receives forms at.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/contact-form-7/v1/contact-forms/\d+/feedback/?$#' );
	}
}
