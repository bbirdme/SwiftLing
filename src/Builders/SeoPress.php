<?php
/**
 * SEOPress: which of its fields of posts and terms hold text.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * SEOPress. It keeps the SEO title, meta description, social texts and breadcrumb title of a post or
 * a term in its meta, and reads them with get_post_meta() and get_term_meta() while the page renders,
 * so a page check finds them in the head like any other text of the page, see sources.php. Its
 * variables, its canonical address and its sitemaps get what they need in seo.php and sitemaps.php.
 *
 * This class declares its fields as text, so the editor of a list of categories or tags translates
 * them for every term, not only once it checked the page of a term that shows them, see
 * swiftling_term_fields(), and names them as SEOPress names them, its Title as SEO title, apart
 * from the title of a post. Its settings for robots, the keywords it analyses a page for and its
 * redirect are no text. An address typed into its canonical field is left to the page check, as any
 * other address.
 *
 * Checked against the code of SEOPress 10.3 and by hand on a site with the plugin.
 */
class SeoPress extends Builder {
	/**
	 * Its fields of text, by meta key.
	 */
	private const TEXT_FIELDS = array( '_seopress_titles_title', '_seopress_titles_desc', '_seopress_social_fb_title', '_seopress_social_fb_desc', '_seopress_social_twitter_title', '_seopress_social_twitter_desc', '_seopress_robots_breadcrumbs' );

	/**
	 * Its fields that hold no text: settings for robots, the keywords of its analysis, the redirect.
	 */
	private const SETTINGS = array( '_seopress_robots_index', '_seopress_robots_follow', '_seopress_robots_imageindex', '_seopress_robots_snippet', '_seopress_robots_primary_cat', '_seopress_analysis_target_kw' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'seopress';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'SEOPress';
	}

	/**
	 * Whether the plugin is on the site: it defines its version while its main file loads.
	 */
	public function isActive(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin. SEOPress builds no page.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- SEOPress builds no page.
		return false;
	}

	/**
	 * Whether a field of SEOPress of a post or a term holds text: its titles and descriptions do, its
	 * settings do not.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		$key = self::metaKey( $owner, $path );
		if ( null === $key ) {
			return null;
		}
		if ( in_array( $key, self::TEXT_FIELDS, true ) ) {
			return true;
		}
		return in_array( $key, self::SETTINGS, true ) || 0 === strpos( $key, '_seopress_redirections_' ) ? false : null;
	}

	/**
	 * Returns what the editor calls a field of text of SEOPress, as SEOPress calls it.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty for any other value.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		$key    = self::metaKey( $owner, $path );
		$labels = array(
			'_seopress_titles_title'         => __( 'SEO title', 'swiftling' ),
			'_seopress_titles_desc'          => __( 'Meta description', 'swiftling' ),
			'_seopress_social_fb_title'      => __( 'Facebook title', 'swiftling' ),
			'_seopress_social_fb_desc'       => __( 'Facebook description', 'swiftling' ),
			'_seopress_social_twitter_title' => __( 'X title', 'swiftling' ),
			'_seopress_social_twitter_desc'  => __( 'X description', 'swiftling' ),
			'_seopress_robots_breadcrumbs'   => __( 'Breadcrumb title', 'swiftling' ),
		);
		return null !== $key && isset( $labels[ $key ] ) ? $labels[ $key ] : '';
	}

	/**
	 * Returns the meta key of a value of a post or a term, as SEOPress keeps it: the whole value of
	 * the key, not a part of it.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string|null Null for a value of anything else.
	 */
	private static function metaKey( string $owner, string $path ): ?string {
		if ( 0 !== strpos( $owner, 'post:' ) && 0 !== strpos( $owner, 'term:' ) ) {
			return null;
		}
		if ( ! preg_match( '~^meta/(_seopress_[a-z_]+)$~', $path, $found ) ) {
			return null;
		}
		return $found[1];
	}
}
