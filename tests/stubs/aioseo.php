<?php
/**
 * A stand-in for the parts of All in One SEO that SwiftLing calls, so its handling of the plugin can
 * be tested without it: aioseo() with the helpers that give the post a page shows and cut a
 * description from its content, as AIOSEO 5.0.3 does (app/Common/Traits/Helpers/WpContext.php,
 * without a page builder, custom fields or shortcodes run, its defaults), and the sitemap being
 * built (app/Common/Sitemap). Each test starts from swiftling_test_aioseo_reset().
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- The plugin's own names, in one file for the tests.

/**
 * AIOSEO's helpers.
 */
class SwiftLing_Test_Aioseo_Helpers {
	/**
	 * The post the page shows.
	 *
	 * @var WP_Post|null
	 */
	public $post = null;

	/**
	 * Returns a post, or the one the page shows.
	 *
	 * @param int|false $post_id Post ID.
	 * @return WP_Post|null
	 */
	public function getPost( $post_id = false ) {
		return $post_id ? get_post( $post_id ) : $this->post;
	}

	/**
	 * Returns the content as AIOSEO renders it: as stored, without running shortcodes.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function theContent( $content ) {
		return $content;
	}

	/**
	 * Returns the description AIOSEO cuts from a post's content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function getDescriptionFromContent( $post ) {
		if ( ! empty( $post->post_password ) ) {
			return '';
		}
		$content = $this->theContent( $post->post_content );
		$content = preg_replace( '/(<figure.*?\/figure>|<img.*?\/>|<div.*?class="wp-block-embed__wrapper".*?>.*?<\/div>)/s', '', (string) $content );
		$content = str_replace( ']]>', ']]&gt;', (string) $content );
		$content = trim( wp_strip_all_tags( strip_shortcodes( (string) $content ) ) );
		return wp_trim_words( (string) $content, 55, '' );
	}
}

/**
 * AIOSEO's helpers of sitemaps.
 */
class SwiftLing_Test_Aioseo_Sitemap_Helpers {
	/**
	 * Post types the sitemaps include.
	 *
	 * @var string[]
	 */
	public $types = array( 'post', 'page' );

	/**
	 * Posts left out of the sitemaps, as AIOSEO keeps them: the IDs its settings name and those of
	 * aioseo_sitemap_exclude_posts, read once.
	 *
	 * @var string|null
	 */
	public $excluded = null;

	/**
	 * Returns the name of the sitemaps of a type.
	 *
	 * @param string $type Type, general or rss.
	 * @return string
	 */
	public function filename( $type ) {
		return 'general' === $type ? 'sitemap' : '';
	}

	/**
	 * Returns the post types the sitemaps include.
	 *
	 * @return string[]
	 */
	public function includedPostTypes() {
		return $this->types;
	}

	/**
	 * Returns the posts left out, as a list for SQL.
	 *
	 * @return string
	 */
	public function excludedPosts() {
		if ( null === $this->excluded ) {
			$ids            = apply_filters( 'aioseo_sitemap_exclude_posts', array(), 'general' );
			$this->excluded = $ids ? implode( ', ', array_map( 'intval', $ids ) ) : '';
		}
		return $this->excluded;
	}
}

/**
 * AIOSEO's sitemap being built.
 */
class SwiftLing_Test_Aioseo_Sitemap {
	/**
	 * Name of the sitemaps.
	 *
	 * @var string
	 */
	public $filename = 'sitemap';

	/**
	 * Addresses per sitemap.
	 *
	 * @var int
	 */
	public $linksPerIndex = 1000;

	/**
	 * Helpers.
	 *
	 * @var SwiftLing_Test_Aioseo_Sitemap_Helpers
	 */
	public $helpers;

	/**
	 * Makes the helpers.
	 */
	public function __construct() {
		$this->helpers = new SwiftLing_Test_Aioseo_Sitemap_Helpers();
	}
}

/**
 * AIOSEO itself, as aioseo() gives it.
 */
class SwiftLing_Test_Aioseo {
	/**
	 * Helpers.
	 *
	 * @var SwiftLing_Test_Aioseo_Helpers
	 */
	public $helpers;

	/**
	 * Sitemaps.
	 *
	 * @var SwiftLing_Test_Aioseo_Sitemap
	 */
	public $sitemap;

	/**
	 * Settings: the general sitemap on, with an index.
	 *
	 * @var object
	 */
	public $options;

	/**
	 * Its tags, some of those AIOSEO 5.0.3 registers (Utils\Tags::$tags), each with its ID.
	 *
	 * @var object|null
	 */
	public $tags;

	/**
	 * Makes the parts.
	 */
	public function __construct() {
		$this->helpers = new SwiftLing_Test_Aioseo_Helpers();
		$this->sitemap = new SwiftLing_Test_Aioseo_Sitemap();
		$this->tags    = (object) array(
			'tags' => array_map(
				static function ( $id ) {
					return array( 'id' => $id );
				},
				array( 'post_title', 'separator_sa', 'site_title', 'tagline', 'post_excerpt', 'custom_field', 'tax_name' )
			),
		);
		$this->options = (object) array(
			'sitemap' => (object) array(
				'general' => (object) array(
					'enable'  => true,
					'indexes' => true,
				),
			),
		);
	}
}

/**
 * Returns AIOSEO.
 *
 * @return SwiftLing_Test_Aioseo
 */
function aioseo() {
	if ( ! isset( $GLOBALS['swiftling_test_aioseo'] ) ) {
		swiftling_test_aioseo_reset();
	}
	return $GLOBALS['swiftling_test_aioseo'];
}

/**
 * Starts AIOSEO afresh, as a new request does.
 *
 * @return SwiftLing_Test_Aioseo
 */
function swiftling_test_aioseo_reset() {
	$GLOBALS['swiftling_test_aioseo'] = new SwiftLing_Test_Aioseo();
	return $GLOBALS['swiftling_test_aioseo'];
}
