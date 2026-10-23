<?php
/**
 * Tests of the sitemaps of every language.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The hooks and globals of WordPress, Yoast SEO, Rank Math, SEOPress and All in One SEO, used as they use them.

namespace SwiftLing\Tests;

/**
 * WordPress's sitemaps in each language and their index, and what Yoast SEO's, Rank Math's,
 * SEOPress's and All in One SEO's sitemaps get from SwiftLing.
 */
class SitemapsTest extends TestCase {

	/**
	 * Pages "kontakt", "o-nama" and its child "tim", and a category "vijesti".
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * Makes the content, on a site with language folders.
	 */
	public function set_up() {
		parent::set_up();
		$this->use_pretty_permalinks();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		foreach ( array( 'kontakt', 'o-nama' ) as $name ) {
			$this->ids[ $name ] = self::factory()->post->create(
				array(
					'post_type' => 'page',
					'post_name' => $name,
				)
			);
		}
		$this->ids['tim']     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'tim',
				'post_parent' => $this->ids['o-nama'],
			)
		);
		$this->ids['vijesti'] = self::factory()->category->create( array( 'slug' => 'vijesti' ) );
		wp_set_post_categories( self::factory()->post->create(), array( $this->ids['vijesti'] ) );
	}

	/**
	 * Puts back WordPress's sitemaps for the next test.
	 */
	public function tear_down() {
		$GLOBALS['wp_sitemaps'] = null;
		unset( $GLOBALS['wpseo_sitemaps'], $GLOBALS['swiftling_test_aioseo'] );
		parent::tear_down();
	}

	/**
	 * Counts a page as translated into a language, as visibility.php writes it.
	 *
	 * @param string $name Page.
	 * @param string $lang Language.
	 */
	private function translated( $name, $lang ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- SwiftLing's own table.
		$wpdb->insert(
			swiftling_table( 'translated' ),
			array(
				'post_id' => $this->ids[ $name ],
				'lang'    => swiftling_target_language( $lang )['locale'],
			)
		);
	}

	/**
	 * Returns WordPress's sitemaps as a new request sets them up, in the current language.
	 *
	 * @return \WP_Sitemaps
	 */
	private function sitemaps() {
		$GLOBALS['wp_sitemaps'] = null;
		return wp_sitemaps_get_server();
	}

	/**
	 * Returns the addresses of one of WordPress's sitemaps.
	 *
	 * @param string $provider Provider: posts or taxonomies.
	 * @param string $subtype  Post type or taxonomy.
	 * @return string[]
	 */
	private function locs( $provider, $subtype ) {
		return wp_list_pluck( $this->sitemaps()->registry->get_provider( $provider )->get_url_list( 1, $subtype ), 'loc' );
	}

	/**
	 * Returns the addresses of the sitemap index.
	 *
	 * @return string[]
	 */
	private function index() {
		return wp_list_pluck( $this->sitemaps()->index->get_sitemap_list(), 'loc' );
	}

	/**
	 * Shows the rest of the test in a language, as a request for its folder would.
	 *
	 * @param string $slug Language.
	 */
	private function in_folder( $slug ) {
		$this->show_in( $slug );
		swiftling_keep_folder( $slug );
	}

	/**
	 * A language's sitemap lists the pages translated into it, with their translated slugs, and the
	 * site address, which lists the posts in every language.
	 */
	public function test_language_sitemap_lists_translated_pages() {
		$this->translated( 'o-nama', 'de' );
		$this->translated( 'tim', 'de' );
		swiftling_save_slug( 'post:' . $this->ids['o-nama'], 'de_DE', 'uber-uns' );

		$this->in_folder( 'de' );
		$this->assertSame(
			array( 'http://example.org/de/', 'http://example.org/de/uber-uns/', 'http://example.org/de/uber-uns/tim/' ),
			$this->locs( 'posts', 'page' )
		);
		$this->assertSame( array(), $this->locs( 'posts', 'post' ) );
		$this->assertSame( array( 'http://example.org/de/category/vijesti/' ), $this->locs( 'taxonomies', 'category' ) );

		$this->in_folder( 'fr' );
		$this->assertSame( array( 'http://example.org/fr/' ), $this->locs( 'posts', 'page' ) );
	}

	/**
	 * The original's sitemaps stay as they are.
	 */
	public function test_original_sitemap() {
		$this->translated( 'tim', 'de' );
		$this->assertSame(
			array( 'http://example.org/', 'http://example.org/kontakt/', 'http://example.org/o-nama/', 'http://example.org/o-nama/tim/' ),
			$this->locs( 'posts', 'page' )
		);
	}

	/**
	 * The page of posts is in every language's sitemap, as in hreflang.
	 */
	public function test_page_of_posts_in_every_language() {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['kontakt'] );
		update_option( 'page_for_posts', $this->ids['o-nama'] );
		$this->in_folder( 'de' );
		$this->assertSame( array( 'http://example.org/de/o-nama/' ), $this->locs( 'posts', 'page' ) );
	}

	/**
	 * The front page is the site address in each language, also where a copy is the front page.
	 */
	public function test_front_page() {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['kontakt'] );
		$this->translated( 'kontakt', 'de' );
		$this->in_folder( 'de' );
		$this->assertSame( array( 'http://example.org/de/' ), $this->locs( 'posts', 'page' ) );

		$this->reset_state();
		$this->become( 'administrator' );
		wp_publish_post( swiftling_make_copy( get_post( $this->ids['kontakt'] ), 'de' ) );
		swiftling_copy_map( true );
		$this->in_folder( 'de' );
		$this->assertSame( array( 'http://example.org/de/' ), $this->locs( 'posts', 'page' ) );
	}

	/**
	 * The index lists the sitemaps of every language visitors see, with the pages each has.
	 */
	public function test_index_lists_every_language() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => array(
					'slug'    => 'it',
					'visible' => false,
				),
			),
			'directory'
		);
		$this->translated( 'o-nama', 'de' );
		$this->assertSame(
			array(
				'http://example.org/wp-sitemap-posts-post-1.xml',
				'http://example.org/wp-sitemap-posts-page-1.xml',
				'http://example.org/wp-sitemap-taxonomies-category-1.xml',
				'http://example.org/de/wp-sitemap-posts-page-1.xml',
				'http://example.org/de/wp-sitemap-taxonomies-category-1.xml',
				'http://example.org/fr/wp-sitemap-posts-page-1.xml',
				'http://example.org/fr/wp-sitemap-taxonomies-category-1.xml',
			),
			$this->index()
		);
	}

	/**
	 * With a host per language, each host has a sitemap index of its own, of its own addresses:
	 * Search Console takes each host as a site of its own, and a sitemap only of addresses of its
	 * host. The site address lists the original's sitemaps; a language's host lists its own, with
	 * no author sitemap, as a language has no author archives.
	 */
	public function test_each_host_has_its_own_index() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'A network of sites does not use the host formats.' );
		}
		require_once __DIR__ . '/stubs/yoast.php';
		require_once __DIR__ . '/stubs/aioseo.php';
		swiftling_test_aioseo_reset();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'subdomain'
		);
		$ok = array(
			'state' => 'ok',
			'at'    => time(),
		);
		update_option(
			'swiftling_address_checks',
			array(
				'de.example.org' => $ok,
				'fr.example.org' => $ok,
			)
		);
		$this->translated( 'o-nama', 'de' );

		$this->assertSame(
			array(
				'http://example.org/wp-sitemap-posts-post-1.xml',
				'http://example.org/wp-sitemap-posts-page-1.xml',
				'http://example.org/wp-sitemap-taxonomies-category-1.xml',
			),
			$this->index()
		);
		$yoast                     = new \WPSEO_Sitemaps();
		$yoast->providers          = array( new \WPSEO_Post_Type_Sitemap_Provider(), new \WPSEO_Taxonomy_Sitemap_Provider() );
		$GLOBALS['wpseo_sitemaps'] = $yoast;
		$this->assertSame( array(), ( new \SwiftLing\Sitemaps\YoastProvider() )->get_index_links( 1000 ) );
		$this->assertSame( array( 'http://example.org/page-sitemap1.xml', 'http://example.org/category-sitemap1.xml', 'http://example.org/author.xml' ), $this->seopress_index() );
		$aioseo = $this->aioseo_index( 'http://example.org' );
		$this->assertSame( $aioseo, apply_filters( 'aioseo_sitemap_indexes', $aioseo ) );

		// On the German host the index is served, not sent to the site address: a new request.
		$decided                = &swiftling_decided_language();
		$decided                = null;
		$_SERVER['HTTP_HOST']   = 'de.example.org';
		$_SERVER['REQUEST_URI'] = '/wp-sitemap.xml';
		swiftling_maybe_start_translation();
		$wp             = new \WP();
		$wp->query_vars = array( 'sitemap' => 'index' );
		$this->assert_no_redirect(
			static function () use ( $wp ) {
				swiftling_sitemap_request( $wp );
			}
		);
		$this->assertSame(
			array(
				'http://de.example.org/wp-sitemap-posts-page-1.xml',
				'http://de.example.org/wp-sitemap-taxonomies-category-1.xml',
			),
			$this->index()
		);
		$this->assertSame(
			array( 'http://de.example.org/page-sitemap.xml' ),
			wp_list_pluck(
				apply_filters(
					'wpseo_sitemap_index_links',
					array(
						array( 'loc' => 'http://de.example.org/page-sitemap.xml' ),
						array( 'loc' => 'http://de.example.org/author-sitemap.xml' ),
					)
				),
				'loc'
			)
		);
		$this->assertSame( array( 'http://de.example.org/page-sitemap1.xml', 'http://de.example.org/category-sitemap1.xml' ), $this->seopress_index() );
		$this->assertSame(
			array( 'http://de.example.org/page-sitemap.xml', 'http://de.example.org/category-sitemap.xml', 'http://de.example.org/post-archive-sitemap.xml' ),
			wp_list_pluck( apply_filters( 'aioseo_sitemap_indexes', $this->aioseo_index( 'http://de.example.org' ) ), 'loc' )
		);
	}

	/**
	 * In the parameter format the sitemaps and their addresses carry ?lang=.
	 */
	public function test_parameter_format() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->translated( 'o-nama', 'de' );
		swiftling_save_slug( 'post:' . $this->ids['o-nama'], 'de_DE', 'uber-uns' );
		$this->assertContains( 'http://example.org/wp-sitemap-posts-page-1.xml?lang=de', $this->index() );

		$this->show_in( 'de' );
		$wp             = new \WP();
		$wp->query_vars = array( 'sitemap' => 'posts' );
		swiftling_sitemap_request( $wp );
		$this->assertSame( array( 'http://example.org/?lang=de', 'http://example.org/uber-uns/?lang=de' ), $this->locs( 'posts', 'page' ) );
	}

	/**
	 * An original with a folder of its own gets it in the sitemaps at the site address.
	 */
	public function test_original_folder() {
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory', array( 'source_folder' => true ) );
		$this->assertContains( 'http://example.org/hr/wp-sitemap-posts-page-1.xml', $this->index() );
		$this->assertContains( 'http://example.org/hr/kontakt/', $this->locs( 'posts', 'page' ) );
	}

	/**
	 * Asked for in a language, the index sends the visitor to the index, and there is no author
	 * sitemap. Other sitemaps are built.
	 */
	public function test_requests_in_a_language() {
		$this->in_folder( 'de' );
		$_SERVER['REQUEST_URI'] = '/de/wp-sitemap.xml';
		$wp                     = new \WP();
		$wp->query_vars         = array( 'sitemap' => 'index' );
		$this->assertSame(
			'http://example.org/wp-sitemap.xml',
			$this->redirect_of(
				static function () use ( $wp ) {
					swiftling_sitemap_request( $wp );
				}
			)
		);
		$this->assertSame( 301, $this->redirect_status );

		$wp->query_vars = array( 'sitemap' => 'users' );
		swiftling_sitemap_request( $wp );
		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );

		$wp->query_vars = array(
			'sitemap'         => 'posts',
			'sitemap-subtype' => 'page',
		);
		swiftling_sitemap_request( $wp );
		$this->assertSame( 'posts', $wp->query_vars['sitemap'] );

		$this->reset_state();
		$wp->query_vars = array( 'sitemap' => 'index' );
		$this->assert_no_redirect(
			static function () use ( $wp ) {
				swiftling_sitemap_request( $wp );
			}
		);
	}

	/**
	 * Yoast SEO's and Rank Math's queries of posts leave copies out, and in a language keep to the
	 * posts translated into it.
	 */
	public function test_seo_plugin_queries() {
		global $wpdb;
		$this->translated( 'tim', 'de' );
		$this->become( 'administrator' );
		$copy = swiftling_make_copy( get_post( $this->ids['kontakt'] ), 'de' );
		wp_publish_post( $copy );
		swiftling_copy_map( true );

		$pages = static function ( $where ) use ( $wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- The filters' own SQL.
			return array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} AS p WHERE p.post_type = 'page' AND p.post_status = 'publish' {$where} ORDER BY p.ID" ) );
		};
		$this->assertSame( array( $this->ids['kontakt'], $this->ids['o-nama'], $this->ids['tim'] ), $pages( apply_filters( 'rank_math/sitemap/get_posts/where', '', 'page' ) ) );
		// The copy translates its original.
		$this->assertSame(
			array( $this->ids['kontakt'], $this->ids['tim'] ),
			swiftling_in_sitemap_language(
				'de',
				static function () use ( $pages ) {
					return $pages( apply_filters( 'rank_math/sitemap/post_count/where', '', 'page' ) );
				}
			)
		);
		$this->assertStringContainsString( $wpdb->posts . '.ID NOT IN', apply_filters( 'wpseo_posts_where', false, 'page' ) );
		$this->assertStringContainsString(
			$wpdb->posts . '.ID IN',
			swiftling_in_sitemap_language(
				'de',
				static function () {
					return apply_filters( 'wpseo_typecount_where', '', 'page' );
				}
			)
		);
	}

	/**
	 * Every page address Yoast SEO and Rank Math print gets the sitemap's language; images keep theirs.
	 */
	public function test_printed_addresses() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->show_in( 'de' );
		$xml = "<url>\n<loc>http://example.org/kontakt/?a=1&amp;b=2</loc>\n<image:loc>http://example.org/a.jpg</image:loc>\n</url>";
		$this->assertSame(
			"<url>\n<loc>http://example.org/kontakt/?a=1&amp;b=2&amp;lang=de</loc>\n<image:loc>http://example.org/a.jpg</image:loc>\n</url>",
			apply_filters( 'wpseo_sitemap_url', $xml, array() )
		);
		$this->assertSame( '<url><loc>https://other.example/</loc></url>', apply_filters( 'rank_math/sitemap/url', '<url><loc>https://other.example/</loc></url>', array() ) );

		$this->reset_state();
		$this->assertSame( $xml, apply_filters( 'rank_math/sitemap/url', $xml, array() ) );
	}

	/**
	 * Yoast SEO's and Rank Math's index list the sitemaps of post types and taxonomies of every
	 * language, counted in each.
	 */
	public function test_seo_plugin_indexes() {
		require_once __DIR__ . '/stubs/yoast.php';
		require_once __DIR__ . '/stubs/rank-math.php';
		$this->translated( 'o-nama', 'de' );

		$yoast                     = new \WPSEO_Sitemaps();
		$yoast->providers          = array( new \WPSEO_Post_Type_Sitemap_Provider(), new \WPSEO_Taxonomy_Sitemap_Provider(), new \WPSEO_Author_Sitemap_Provider() );
		$GLOBALS['wpseo_sitemaps'] = $yoast;
		$expected                  = array(
			'http://example.org/de/page-sitemap.xml',
			'http://example.org/de/category-sitemap.xml',
			'http://example.org/fr/category-sitemap.xml',
		);
		$this->assertSame( $expected, wp_list_pluck( ( new \SwiftLing\Sitemaps\YoastProvider() )->get_index_links( 1000 ), 'loc' ) );
		$this->assertSame( $expected, wp_list_pluck( ( new \SwiftLing\Sitemaps\RankMathProvider() )->get_index_links( 100 ), 'loc' ) );
		$this->assertFalse( ( new \SwiftLing\Sitemaps\YoastProvider() )->handles_type( 'page' ) );

		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->assertSame( array( 'http://example.org/page-sitemap.xml?lang=de', 'http://example.org/category-sitemap.xml?lang=de' ), wp_list_pluck( ( new \SwiftLing\Sitemaps\YoastProvider() )->get_index_links( 1000 ), 'loc' ) );

		// The index of a language is never built: it sends the visitor on.
		$this->show_in( 'de' );
		$this->assertSame( array(), ( new \SwiftLing\Sitemaps\RankMathProvider() )->get_index_links( 100 ) );
	}

	/**
	 * Yoast SEO and Rank Math cache no sitemap of a language, which they would keep under the
	 * original's name.
	 */
	public function test_caching() {
		$_SERVER['REQUEST_URI'] = '/page-sitemap.xml';
		$this->assertTrue( apply_filters( 'rank_math/sitemap/enable_caching', true ) );
		$_SERVER['REQUEST_URI'] = '/de/page-sitemap.xml';
		$this->assertFalse( apply_filters( 'rank_math/sitemap/enable_caching', true ) );
		$this->assertFalse( apply_filters( 'wpseo_enable_xml_sitemap_transient_caching', true ) );

		$this->set_languages();
		$_SERVER['REQUEST_URI'] = '/page-sitemap.xml?lang=de';
		$_GET['lang']           = 'de';
		$this->assertFalse( apply_filters( 'wpseo_enable_xml_sitemap_transient_caching', true ) );
	}

	/**
	 * Writing the languages of posts tells the cached indexes to go.
	 */
	public function test_languages_of_posts_changed() {
		$fired = did_action( 'swiftling_translated_changed' );
		swiftling_refresh_translated( array( $this->ids['kontakt'] ) );
		$this->assertSame( $fired + 1, did_action( 'swiftling_translated_changed' ) );
		$this->assertSame( 10, has_action( 'swiftling_translated_changed', 'swiftling_clear_sitemap_index' ) );
		$this->assertSame( 10, has_action( 'update_option_swiftling_languages', 'swiftling_clear_all_sitemaps' ) );
	}

	/**
	 * SEOPress's queries of posts, which suppress the filters of a query, leave copies out, and in a
	 * language keep to the posts translated into it: the posts of a sitemap, and their count in the
	 * index. Asked for as SEOPress 10.3 asks (templates/sitemap/single.php, SitemapPostCount).
	 */
	public function test_seopress_queries() {
		$this->translated( 'tim', 'de' );
		$this->become( 'administrator' );
		wp_publish_post( swiftling_make_copy( get_post( $this->ids['kontakt'] ), 'de' ) );
		swiftling_copy_map( true );

		$single = static function () {
			$args = array(
				'posts_per_page' => 1000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- SEOPress's own.
				'offset'         => 0,
				'order'          => 'ASC',
				'orderby'        => 'ID',
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'lang'           => '',
				'has_password'   => false,
			);
			return wp_list_pluck( get_posts( apply_filters( 'seopress_sitemaps_single_query', $args, 'page' ) ), 'ID' );
		};
		$count  = static function () {
			$args                   = array(
				'posts_per_page' => -1,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'lang'           => '',
				'has_password'   => false,
			);
			$args                   = wp_parse_args( apply_filters( 'seopress_sitemaps_index_post_types_query', $args, 'page' ), array( 'suppress_filters' => true ) );
			$args['posts_per_page'] = 1;
			return ( new \WP_Query( $args ) )->found_posts;
		};
		$this->assertSame( array( $this->ids['kontakt'], $this->ids['o-nama'], $this->ids['tim'] ), $single() );
		$this->assertSame( 3, $count() );

		// The copy translates its original.
		$this->assertSame( 2, swiftling_in_sitemap_language( 'de', $count ) );
		$this->in_folder( 'de' );
		$this->assertSame( array( $this->ids['kontakt'], $this->ids['tim'] ), $single() );
	}

	/**
	 * Returns the addresses of SEOPress's sitemap index, built as SEOPress builds it, with sitemaps
	 * of pages and categories and the author sitemap.
	 *
	 * @return string[]
	 */
	private function seopress_index() {
		require_once __DIR__ . '/stubs/seopress.php';
		update_option(
			'seopress_xml_sitemap_option_name',
			array(
				'seopress_xml_sitemap_general_enable'  => '1',
				'seopress_xml_sitemap_post_types_list' => array( 'page' => array( 'include' => '1' ) ),
				'seopress_xml_sitemap_taxonomies_list' => array( 'category' => array( 'include' => '1' ) ),
				'seopress_xml_sitemap_author_enable'   => '1',
			)
		);
		preg_match_all( '~<loc>([^<]*)</loc>~', seopress_xml_sitemap_index(), $found );
		return $found[1];
	}

	/**
	 * SEOPress's index lists the sitemaps of every language visitors see after the original's, as
	 * SEOPress builds them in each: those of the post types and taxonomies its settings include,
	 * counted in the language, without the author sitemap. The sitemaps other plugins add stay once.
	 */
	public function test_seopress_index() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => array(
					'slug'    => 'it',
					'visible' => false,
				),
			),
			'directory'
		);
		add_filter(
			'seopress_sitemaps_external_link',
			static function () {
				return array( array( 'sitemap_url' => 'http://example.org/news.xml' ) );
			}
		);
		add_filter(
			'seopress_sitemaps_max_posts_per_sitemap',
			static function () {
				return 2;
			}
		);
		$this->translated( 'o-nama', 'de' );
		$this->translated( 'tim', 'de' );
		$this->assertSame(
			array(
				'http://example.org/page-sitemap1.xml',
				'http://example.org/page-sitemap2.xml',
				'http://example.org/category-sitemap1.xml',
				'http://example.org/author.xml',
				// Two pages in German, one sitemap of two; SEOPress lists a type without posts too.
				'http://example.org/de/page-sitemap1.xml',
				'http://example.org/de/category-sitemap1.xml',
				'http://example.org/fr/page-sitemap1.xml',
				'http://example.org/fr/category-sitemap1.xml',
				'http://example.org/news.xml',
			),
			$this->seopress_index()
		);
		// Each sitemap keeps the last change SEOPress gave it: the first of pages, in each language.
		$this->assertSame( 3, substr_count( seopress_xml_sitemap_index(), '<lastmod>' ) );

		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->assertContains( 'http://example.org/page-sitemap1.xml?lang=de', $this->seopress_index() );
	}

	/**
	 * Every page address SEOPress prints gets the sitemap's language, and the front page is the site
	 * address, also where a copy is the front page of the language.
	 */
	public function test_seopress_addresses() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->show_in( 'de' );
		$xml = "\n<url>\n<loc>http://example.org/kontakt/?a=1&amp;b=2</loc>\n<lastmod>2026-10-19T10:00:00+00:00</lastmod>\n<image:image><image:loc>http://example.org/a.jpg</image:loc></image:image></url>";
		$this->assertSame(
			"\n<url>\n<loc>http://example.org/kontakt/?a=1&amp;b=2&amp;lang=de</loc>\n<lastmod>2026-10-19T10:00:00+00:00</lastmod>\n<image:image><image:loc>http://example.org/a.jpg</image:loc></image:image></url>",
			apply_filters( 'seopress_sitemaps_url', $xml, array() )
		);
		$this->reset_state();
		$this->assertSame( $xml, apply_filters( 'seopress_sitemaps_url', $xml, array() ) );

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['kontakt'] );
		$url = array(
			'loc'    => 'http://example.org/kontakt/',
			'mod'    => '2026-10-19T10:00:00+00:00',
			'images' => array(),
		);
		$this->assertSame( array( 'loc' => 'http://example.org/' ) + $url, apply_filters( 'seopress_sitemaps_single_url', $url, get_post( $this->ids['kontakt'] ) ) );
		$this->assertSame( array( 'loc' => 'http://example.org/o-nama/' ) + $url, apply_filters( 'seopress_sitemaps_single_url', array( 'loc' => 'http://example.org/o-nama/' ) + $url, get_post( $this->ids['o-nama'] ) ) );
	}

	/**
	 * Asked for in a language, SEOPress's index sends the visitor to the index, which lists every
	 * language, and there is no author sitemap; the sitemap of a post type or a taxonomy is built
	 * with the language's addresses. In the original nothing changes.
	 */
	public function test_seopress_requests() {
		$wp             = new \WP();
		$wp->query_vars = array( 'seopress_sitemap' => '1' );
		$this->assert_no_redirect(
			static function () use ( $wp ) {
				swiftling_sitemap_request( $wp );
			}
		);

		$this->in_folder( 'de' );
		$_SERVER['REQUEST_URI'] = '/de/sitemaps.xml';
		$this->assertSame(
			'http://example.org/sitemaps.xml',
			$this->redirect_of(
				static function () use ( $wp ) {
					swiftling_sitemap_request( $wp );
				}
			)
		);
		$this->assertSame( 301, $this->redirect_status );

		$wp->query_vars = array( 'seopress_author' => '1' );
		swiftling_sitemap_request( $wp );
		$this->assertSame( array( 'error' => '404' ), $wp->query_vars );

		$wp->query_vars = array(
			'seopress_cpt'   => 'page',
			'seopress_paged' => '1',
		);
		swiftling_sitemap_request( $wp );
		$this->assertSame( 'page', $wp->query_vars['seopress_cpt'] );
		$this->assertSame( 'de_DE', swiftling_link_locale_override() );
	}

	/**
	 * Returns an index of All in One SEO as it builds one (Sitemap\Root::indexes(), AIOSEO 5.0.3),
	 * before aioseo_sitemap_indexes: the sitemap of pages, its additional pages, of categories, of
	 * the archives of post types, of authors and of dates.
	 *
	 * @param string $host Site address the sitemaps are at.
	 * @return array[]
	 */
	private function aioseo_index( $host ) {
		$entries = array();
		foreach ( array( 'addl', 'page', 'category', 'post-archive', 'author', 'date' ) as $name ) {
			$entries[] = array(
				'loc'     => $host . '/' . $name . '-sitemap.xml',
				'lastmod' => '2026-10-01T10:00:00+00:00',
				'count'   => 3,
			);
		}
		return $entries;
	}

	/**
	 * All in One SEO reads the posts of its sitemaps with SQL of its own, which only leaves out the
	 * posts it is told to: in every sitemap the separate copies, in a language's the posts not
	 * translated into it, of the post types its sitemaps include, besides those its settings name.
	 */
	public function test_aioseo_excluded_posts() {
		require_once __DIR__ . '/stubs/aioseo.php';
		swiftling_test_aioseo_reset();
		$this->translated( 'tim', 'de' );
		$this->become( 'administrator' );
		$copy = swiftling_make_copy( get_post( $this->ids['kontakt'] ), 'de' );
		wp_publish_post( $copy );
		swiftling_copy_map( true );
		$post     = (int) get_posts(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		)[0];
		$excluded = static function () {
			return apply_filters( 'aioseo_sitemap_exclude_posts', array( 999 ), 'general' );
		};
		$this->assertSame( array( 999, $copy ), $excluded() );

		$this->in_folder( 'de' );
		$this->assertEqualsCanonicalizing( array( 999, $copy, $this->ids['o-nama'], $post ), $excluded() );

		// Only of the post types its sitemaps include.
		aioseo()->sitemap->helpers->types = array( 'page' );
		$this->assertEqualsCanonicalizing( array( 999, $copy, $this->ids['o-nama'] ), $excluded() );
	}

	/**
	 * All in One SEO's index lists the sitemaps of every language visitors see after the original's:
	 * those of post types, as many as the posts translated into the language take, with the last
	 * change among them, and of taxonomies and of the archives of post types, as the original's.
	 * Authors, dates and additional pages a language has none of.
	 */
	public function test_aioseo_index() {
		global $wpdb;
		require_once __DIR__ . '/stubs/aioseo.php';
		swiftling_test_aioseo_reset()->sitemap->linksPerIndex = 1;
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => array(
					'slug'    => 'it',
					'visible' => false,
				),
			),
			'directory'
		);
		$this->translated( 'o-nama', 'de' );
		$this->translated( 'tim', 'de' );
		$original = $this->aioseo_index( 'http://example.org' );
		$index    = apply_filters( 'aioseo_sitemap_indexes', $original );
		$this->assertSame( $original, array_slice( $index, 0, count( $original ) ) );
		$this->assertSame(
			array(
				// Two pages in German, one address per sitemap.
				'http://example.org/de/page-sitemap.xml',
				'http://example.org/de/page-sitemap2.xml',
				'http://example.org/de/category-sitemap.xml',
				'http://example.org/de/post-archive-sitemap.xml',
				'http://example.org/fr/category-sitemap.xml',
				'http://example.org/fr/post-archive-sitemap.xml',
			),
			wp_list_pluck( array_slice( $index, count( $original ) ), 'loc' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The pages' own dates.
		$modified = $wpdb->get_var( $wpdb->prepare( "SELECT MAX( post_modified_gmt ) FROM {$wpdb->posts} WHERE ID IN ( %d, %d )", $this->ids['o-nama'], $this->ids['tim'] ) );
		$language = array_slice( $index, count( $original ) );
		$this->assertSame( array( gmdate( 'Y-m-d\TH:i:s+00:00', (int) strtotime( $modified . ' UTC' ) ), 1 ), array( $language[0]['lastmod'], $language[0]['count'] ) );
		$this->assertSame( array( '2026-10-01T10:00:00+00:00', 3 ), array( $language[2]['lastmod'], $language[2]['count'] ), 'As the original\'s' );

		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->assertContains( 'http://example.org/page-sitemap.xml?lang=de', wp_list_pluck( apply_filters( 'aioseo_sitemap_indexes', $original ), 'loc' ) );
	}

	/**
	 * Every address All in One SEO's sitemaps print gets the sitemap's language, the front page the
	 * site address; images keep theirs, and the original's stay.
	 */
	public function test_aioseo_entries() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->ids['kontakt'] );
		$entry = array(
			'loc'     => 'http://example.org/o-nama/',
			'lastmod' => '2026-10-01T10:00:00+00:00',
			'images'  => array( (object) array( 'image:loc' => 'http://example.org/a.jpg' ) ),
		);
		$this->show_in( 'de' );
		$this->assertSame( array( 'loc' => 'http://example.org/o-nama/?lang=de' ) + $entry, apply_filters( 'aioseo_sitemap_post', $entry, $this->ids['o-nama'], 'page', 'post' ) );
		$this->assertSame( 'http://example.org/?lang=de', apply_filters( 'aioseo_sitemap_post', array( 'loc' => 'http://example.org/kontakt/' ) + $entry, $this->ids['kontakt'], 'page', 'post' )['loc'] );
		$this->assertSame( 'http://example.org/category/vijesti/?lang=de', apply_filters( 'aioseo_sitemap_term', array( 'loc' => 'http://example.org/category/vijesti/' ) + $entry, $this->ids['vijesti'], 'category', 'term' )['loc'] );
		$this->assertSame( 'http://example.org/blog/?lang=de', apply_filters( 'aioseo_sitemap_archive_entry', array( 'loc' => 'http://example.org/blog/' ), null, 'post', 'archive' )['loc'] );

		$this->reset_state();
		$this->assertSame( $entry, apply_filters( 'aioseo_sitemap_post', $entry, $this->ids['o-nama'], 'page', 'post' ) );
	}

	/**
	 * A language has no author archives: All in One SEO's author sitemap, which it serves by the
	 * address whatever a request's query variables say, lists none there.
	 */
	public function test_aioseo_author_sitemap() {
		$authors = array( array( 'loc' => 'http://example.org/author/admin/' ) );
		$this->assertSame( $authors, apply_filters( 'aioseo_sitemap_author_archives', $authors ) );
		$this->in_folder( 'de' );
		$this->assertSame( array(), apply_filters( 'aioseo_sitemap_author_archives', $authors ) );
	}

	/**
	 * All in One SEO names its sitemaps by the address, not by query variables. Asked for in a
	 * language, its index sends the visitor to the index, which lists every language, and there is
	 * no author sitemap; any other sitemap, and the index where it has none of its own but lists
	 * every address, is built with the language's addresses.
	 */
	public function test_aioseo_requests() {
		require_once __DIR__ . '/stubs/aioseo.php';
		swiftling_test_aioseo_reset();
		$request = static function ( $path ) {
			$wp             = new \WP();
			$wp->request    = $path;
			$wp->query_vars = array();
			swiftling_sitemap_request( $wp );
			return $wp;
		};
		$this->assert_no_redirect(
			static function () use ( $request ) {
				$request( 'sitemap.xml' );
			}
		);

		$this->in_folder( 'de' );
		$_SERVER['REQUEST_URI'] = '/de/sitemap.xml';
		$this->assertSame(
			'http://example.org/sitemap.xml',
			$this->redirect_of(
				static function () use ( $request ) {
					$request( 'sitemap.xml' );
				}
			)
		);
		$this->assertSame( 301, $this->redirect_status );
		$this->assertSame( array( 'error' => '404' ), $request( 'author-sitemap.xml' )->query_vars );
		$this->assertSame( null, swiftling_link_locale_override() );
		$request( 'page-sitemap2.xml' );
		$this->assertSame( 'de_DE', swiftling_link_locale_override() );
		$this->assertSame( array(), $request( 'kontakt' )->query_vars );

		// AIOSEO sends /de/page-sitemap1.xml on to the site address and the address asked for, which
		// in a language's folder names the folder twice.
		$request( 'page-sitemap1.xml' );
		$this->assertSame( 'http://example.org/de/page-sitemap.xml', apply_filters( 'wp_redirect', 'http://example.org/de/de/page-sitemap.xml', 302 ) );
		$this->assertSame( 'http://example.org/de/devices/', apply_filters( 'wp_redirect', 'http://example.org/de/devices/', 302 ) );

		// An index that lists every address is the language's.
		$links = &swiftling_link_locale_override();
		$links = null;

		aioseo()->options->sitemap->general->indexes = false;
		$this->assert_no_redirect(
			static function () use ( $request ) {
				$request( 'sitemap.xml' );
			}
		);
		$this->assertSame( 'de_DE', swiftling_link_locale_override() );
	}
}
