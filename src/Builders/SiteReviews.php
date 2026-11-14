<?php
/**
 * Site Reviews: the title and text of each review in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Site Reviews. A review is a post of the type "site-review" with a row of the plugin's own table; the
 * plugin reads both with a query of its own and prints a review from its own object, past
 * get_the_title() and the_content. Its title and text are put in the mode of the moment where the
 * plugin starts to print a review, see translateReview(), as the fields of the review's post, so a
 * review is translated once, as a comment is, and shows translated on every page that lists it. The
 * reviewer's name stays as it is. The words of the plugin's forms and summaries come from its language
 * pack and its settings, which SwiftLing finds on the page. The form and the pages of reviews send to
 * admin-ajax.php or the plugin's REST API from the page: answered in the language of that page, see
 * sendingActions() and sendingRoutes(). The plugin
 * notifies the site's owner from its queue, in a request of its own, in the original.
 *
 * Checked against the code of Site Reviews 8.3 (the free plugin), in tests/ReviewsRecipesSportsTest.php,
 * and by hand on a site with the plugin. Its paid add-ons were not checked.
 */
class SiteReviews extends Builder {
	/**
	 * The plugin's type of post.
	 */
	const POST_TYPE = 'site-review';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'site-reviews';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Site Reviews';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return function_exists( 'glsr' );
	}

	/**
	 * Whether a post is built with the plugin: a review needs nothing of a builder.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A review needs nothing of a builder.
		return false;
	}

	/**
	 * Returns the action of admin-ajax.php the plugin's forms send to from a page: answered in the
	 * language of that page, as the message after a review is sent and the next page of reviews.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'glsr_public_action' );
	}

	/**
	 * Returns the routes of the REST API the plugin's forms and lists send to from a page: the form, the
	 * next page of reviews and a list made anew.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/site-reviews/v1/(submissions|render/reviews|shortcode/[a-z_]+)(/|$)#' );
	}

	/**
	 * Hooks where the plugin starts to print a review.
	 */
	public function register(): void {
		add_action( 'site-reviews/review/build/before', array( $this, 'translateReview' ), 10, 1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The plugin's own action.
	}

	/**
	 * Puts the title and text of a review the plugin is about to print in the mode of the moment, when
	 * they are what the review's post holds.
	 *
	 * @param mixed $review The plugin's object of the review.
	 */
	public function translateReview( $review ): void {
		if ( ! is_object( $review ) || ! method_exists( $review, 'set' ) || empty( $review->ID ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return;
		}
		$post = get_post( (int) $review->ID );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( is_string( $review->title ) && '' !== trim( $review->title ) && $review->title === $post->post_title ) {
			$review->set( 'title', (string) swiftling_filter_title( $post->post_title, $post->ID ) );
		}
		if ( is_string( $review->content ) && '' !== trim( $review->content ) && $review->content === $post->post_content ) {
			$review->set( 'content', swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $post->post_content ) );
		}
	}
}
