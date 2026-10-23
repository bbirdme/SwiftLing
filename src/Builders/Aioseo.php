<?php
/**
 * All in One SEO: what the editor calls its texts of a post.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * All in One SEO (AIOSEO). It keeps the SEO title, meta description and Facebook and X texts of a
 * post in a table of its own, aioseo_posts, and reads them from there, past the filters of meta.
 * SwiftLing reads them as the copy AIOSEO keeps in the post's meta for translation plugins
 * (_aioseo_title, …) would be read, see swiftling_aioseo_post() in seo.php, so a page check finds
 * them in the head like any other text of the page; its tags, its canonical address and its
 * sitemaps get what they need in seo.php and sitemaps.php.
 *
 * This class names those texts in the editor as AIOSEO names them, its Title as SEO title, apart
 * from the title of the post. It declares none of them as text: a text of AIOSEO may be its tags
 * alone, such as "#post_title #separator_sa #site_title", which the page check leaves alone.
 * AIOSEO's free version keeps no texts of terms.
 *
 * Checked against the code of AIOSEO 5.0.3 and by hand on a site with the plugin.
 */
class Aioseo extends Builder {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'aioseo';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'All in One SEO';
	}

	/**
	 * Whether the plugin is on the site: it defines its version while its main file loads.
	 */
	public function isActive(): bool {
		return defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin. AIOSEO builds no page.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- AIOSEO builds no page.
		return false;
	}

	/**
	 * Returns what the editor calls a text of AIOSEO of a post, as AIOSEO calls it.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty for any other value.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, 'post:' ) || ! preg_match( '~^meta/(_aioseo_[a-z_]+)$~', $path, $found ) ) {
			return '';
		}
		$labels = array(
			'_aioseo_title'               => __( 'SEO title', 'swiftling' ),
			'_aioseo_description'         => __( 'Meta description', 'swiftling' ),
			'_aioseo_og_title'            => __( 'Facebook title', 'swiftling' ),
			'_aioseo_og_description'      => __( 'Facebook description', 'swiftling' ),
			'_aioseo_twitter_title'       => __( 'X title', 'swiftling' ),
			'_aioseo_twitter_description' => __( 'X description', 'swiftling' ),
		);
		return isset( $labels[ $found[1] ] ) ? $labels[ $found[1] ] : '';
	}
}
