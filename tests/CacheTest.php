<?php
/**
 * Tests of emptying page caches.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidHookName.UseUnderscores -- The hooks and globals of the cache plugins, used as they use them.

namespace SwiftLing\Tests;

use WP_REST_Request;

/**
 * Which addresses a change empties, once at the end of the request, and how the cache plugins
 * are asked to empty them.
 */
class CacheTest extends TestCase {

	/**
	 * A checked post "kontakt", which shows the site title.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * What the end of the request emptied: urls and posts, or all.
	 *
	 * @var array
	 */
	private $purged = array();

	/**
	 * Makes the post, on a site with language folders, and listens to what is emptied.
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
		$this->become( 'administrator' );
		update_option( 'blogname', 'Moja stranica' );
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_name'    => 'kontakt',
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		$post       = $this->post;
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
				get_option( 'blogname' );
			}
		);
		$this->forget_queue();
		add_action( 'swiftling_purge_urls', array( $this, 'note_urls' ), 10, 2 );
		add_action( 'swiftling_purge_all', array( $this, 'note_all' ) );
	}

	/**
	 * Notes the addresses emptied.
	 *
	 * @param string[] $urls     Addresses.
	 * @param int[]    $post_ids Posts.
	 */
	public function note_urls( $urls, $post_ids ) {
		$this->purged = array(
			'urls'  => $urls,
			'posts' => $post_ids,
		);
	}

	/**
	 * Notes that everything was emptied.
	 */
	public function note_all() {
		$this->purged = array( 'all' => true );
	}

	/**
	 * Forgets what is queued, such as what making the content queued.
	 */
	private function forget_queue() {
		$queue = &swiftling_purge_queue();
		$queue = array(
			'all'   => false,
			'urls'  => array(),
			'posts' => array(),
		);
	}

	/**
	 * Ends the request and returns what was emptied.
	 *
	 * @return array
	 */
	private function end_request() {
		$this->purged = array();
		swiftling_run_purge();
		return $this->purged;
	}

	/**
	 * Sends a request to a route of SwiftLing.
	 *
	 * @param string $route  Route.
	 * @param array  $params Body.
	 * @return \WP_REST_Response
	 */
	private function call( $route, $params ) {
		$request = new WP_REST_Request( 'POST', '/swiftling/v1' . $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Saves a translation of the post's title in German.
	 *
	 * @param string $text Translation.
	 */
	private function translate_title( $text ) {
		$key = swiftling_place_key( 'post:' . $this->post->ID, swiftling_path_key( 'field/post_title' ), swiftling_hash( 'Kontakt' ) );
		return $this->call(
			'/translations',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'  => $key,
						'text' => $text,
					),
				),
			)
		);
	}

	/**
	 * Saving a post's translations empties its address in the language, the one it had before its
	 * title gave it a slug, and the front page of the language.
	 */
	public function test_saving_translations_of_a_post() {
		$this->assertSame( 200, $this->translate_title( 'Kontaktieren' )->get_status() );
		$purged = $this->end_request();
		$this->assertEqualsCanonicalizing(
			array( 'http://example.org/de/kontakt/', 'http://example.org/de/kontaktieren/', 'http://example.org/de/' ),
			$purged['urls']
		);
		$this->assertSame( array( $this->post->ID ), $purged['posts'] );

		$this->translate_title( 'Kontaktieren Sie uns' );
		$this->assertEqualsCanonicalizing( array( 'http://example.org/de/kontaktieren/', 'http://example.org/de/' ), $this->end_request()['urls'] );
	}

	/**
	 * A text the whole site shows, such as the site title, empties everything.
	 */
	public function test_saving_a_shared_text() {
		$this->call(
			'/translations',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'  => swiftling_place_key( 'options', swiftling_path_key( 'option/blogname' ), swiftling_hash( 'Moja stranica' ) ),
						'text' => 'Meine Seite',
					),
				),
			)
		);
		$this->assertSame( array( 'all' => true ), $this->end_request() );
	}

	/**
	 * A new slug empties the address before and the one now.
	 */
	public function test_saving_a_slug() {
		$this->call(
			'/slug',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'slug'    => 'schreiben',
			)
		);
		$this->assertEqualsCanonicalizing( array( 'http://example.org/de/kontakt/', 'http://example.org/de/schreiben/' ), $this->end_request()['urls'] );
	}

	/**
	 * Changing a published post empties its addresses in every language before and after the
	 * change, and the front pages.
	 */
	public function test_changing_the_original() {
		wp_update_post(
			array(
				'ID'        => $this->post->ID,
				'post_name' => 'pisite-nam',
			)
		);
		$this->assertEqualsCanonicalizing(
			array(
				'http://example.org/kontakt/',
				'http://example.org/de/kontakt/',
				'http://example.org/fr/kontakt/',
				'http://example.org/pisite-nam/',
				'http://example.org/de/pisite-nam/',
				'http://example.org/fr/pisite-nam/',
				'http://example.org/',
				'http://example.org/de/',
				'http://example.org/fr/',
			),
			$this->end_request()['urls']
		);
	}

	/**
	 * Publishing, unpublishing and deleting a post empty its addresses; a draft has none.
	 */
	public function test_publishing_and_deleting() {
		$draft = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_name'   => 'novo',
			)
		);
		wp_update_post(
			array(
				'ID'         => $draft,
				'post_title' => 'Novo',
			)
		);
		$this->assertSame( array(), $this->end_request() );

		wp_publish_post( $draft );
		$this->assertContains( 'http://example.org/de/novo/', $this->end_request()['urls'] );

		wp_trash_post( $draft );
		$this->assertContains( 'http://example.org/fr/novo/', $this->end_request()['urls'] );

		wp_delete_post( $this->post->ID, true );
		$this->assertContains( 'http://example.org/de/kontakt/', $this->end_request()['urls'] );
	}

	/**
	 * A separate copy has one address: its original's in its language.
	 */
	public function test_copy() {
		$copy = swiftling_make_copy( $this->post, 'de' );
		$this->assertSame( array(), $this->end_request() );
		wp_publish_post( $copy );
		swiftling_copy_map( true );
		$this->forget_queue();
		wp_update_post(
			array(
				'ID'         => $copy,
				'post_title' => 'Kontakt (Kopie)',
			)
		);
		$this->assertEqualsCanonicalizing( array( 'http://example.org/de/kontakt/', 'http://example.org/de/' ), $this->end_request()['urls'] );
	}

	/**
	 * A synced pattern empties the pages whose checks saw it, as long as every published post had
	 * its check; otherwise everything.
	 */
	public function test_pattern() {
		$pattern = self::factory()->post->create(
			array(
				'post_type'  => 'wp_block',
				'post_title' => 'Poziv',
			)
		);
		$page    = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'o-nama',
			)
		);
		$this->check(
			'post:' . $page,
			static function () use ( $page, $pattern ) {
				get_the_title( $page );
				get_the_title( $pattern );
			}
		);
		// Posts made by WordPress itself, never checked.
		foreach ( get_posts(
			array(
				'post__not_in' => array( $this->post->ID, $page ),
				'post_type'    => 'any',
				'numberposts'  => -1,
			)
		) as $other ) {
			wp_delete_post( $other->ID, true );
		}
		$this->forget_queue();

		swiftling_purge_shown( 'post:' . $pattern, 'de' );
		$this->assertEqualsCanonicalizing( array( 'http://example.org/de/o-nama/', 'http://example.org/de/' ), $this->end_request()['urls'] );

		self::factory()->post->create();
		$this->forget_queue();
		swiftling_purge_shown( 'post:' . $pattern, 'de' );
		$this->assertSame( array( 'all' => true ), $this->end_request() );

		swiftling_purge_shown( 'part:twentytwentyfive//header', 'de' );
		$this->assertSame( array( 'all' => true ), $this->end_request() );
	}

	/**
	 * The languages, the switcher and the settings of untranslated content empty everything.
	 */
	public function test_settings() {
		update_option( 'swiftling_settings', array( 'untranslated' => array( 'post' => 'hide' ) ) );
		$this->assertSame( array( 'all' => true ), $this->end_request() );
		update_option( 'swiftling_switcher', array( 'style' => 'list' ) );
		$this->assertSame( array( 'all' => true ), $this->end_request() );
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->assertSame( array( 'all' => true ), $this->end_request() );
	}

	/**
	 * Everything queued is emptied once, at the end of the request, in the parameter format with
	 * the language in the query.
	 */
	public function test_once_at_the_end() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->forget_queue();
		swiftling_purge_post( $this->post, array( 'de' ) );
		swiftling_purge_post( $this->post, array( 'de' ) );
		$this->assertSame( 5, has_action( 'shutdown', 'swiftling_run_purge' ) );
		$this->assertSame(
			array(
				'urls'  => array( 'http://example.org/kontakt/?lang=de', 'http://example.org/?lang=de' ),
				'posts' => array( $this->post->ID ),
			),
			$this->end_request()
		);
		$this->assertSame( array(), $this->end_request() );
	}

	/**
	 * Each cache plugin is asked the way it offers. WP Rocket and SiteGround empty a front page
	 * alone, never the pages below it.
	 */
	public function test_cache_plugins() {
		require_once __DIR__ . '/stubs/cache-plugins.php';
		require_once __DIR__ . '/stubs/siteground.php';
		$GLOBALS['swiftling_tests_purged'] = array();
		$litespeed                         = array();
		add_action(
			'litespeed_purge_post',
			static function ( $post_id ) use ( &$litespeed ) {
				$litespeed[] = $post_id;
			}
		);
		$GLOBALS['nginx_purger'] = new class() {
			/**
			 * Empties an address.
			 *
			 * @param string $url  Address.
			 * @param bool   $feed Whether its feed goes too.
			 */
			public function purge_url( $url, $feed = true ) {
				swiftling_tests_purged( 'nginx', array( $url, $feed ) );
			}
		};

		$urls = array( 'http://example.org/de/kontakt/', 'http://example.org/de/' );
		swiftling_purge_cache_plugins( $urls, array( 7 ) );
		unset( $GLOBALS['nginx_purger'] );

		$calls = $GLOBALS['swiftling_tests_purged'];
		$this->assertContains( array( 'rocket_clean_files', array( 'http://example.org/de/kontakt/' ) ), $calls );
		$this->assertContains( array( 'rocket_clean_home', 'cache/example.org*/de' ), $calls );
		$this->assertContains( array( 'w3tc_flush_url', 'http://example.org/de/' ), $calls );
		$this->assertContains( array( 'wpsc_delete_url_cache', 'http://example.org/de/kontakt/' ), $calls );
		$this->assertContains( array( 'sg_cachepress_purge_cache', 'http://example.org/de/kontakt/' ), $calls );
		$this->assertNotContains( array( 'sg_cachepress_purge_cache', 'http://example.org/de/' ), $calls );
		$this->assertContains( array( 'Supercacher::purge_cache_request', array( 'http://example.org/de/', false ) ), $calls );
		$this->assertContains( array( 'Breeze_PurgeCache::purge_post_cache', array( 'http://example.org/?p=7', 'http://example.org/de/kontakt/', 'http://example.org/de/' ) ), $calls );
		$this->assertContains( array( 'nginx', array( 'http://example.org/de/', false ) ), $calls );
		$this->assertContains( array( 'VarnishPurger::purge_url', 'http://example.org/de/kontakt/' ), $calls );
		$this->assertSame( array( 7 ), $litespeed );
		$this->assertFalse( has_filter( 'rocket_clean_home_root' ) );
		$this->assertFalse( has_filter( 'breeze_collect_urls_for_cache_purge' ) );

		// WP Super Cache empties an address with a query with the one without.
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$GLOBALS['swiftling_tests_purged'] = array();
		swiftling_purge_cache_plugins( array( 'http://example.org/kontakt/?lang=de', 'http://example.org/?lang=de' ), array( 7 ) );
		$calls = $GLOBALS['swiftling_tests_purged'];
		$this->assertContains( array( 'wpsc_delete_url_cache', 'http://example.org/kontakt/' ), $calls );
		$this->assertContains( array( 'wpsc_delete_url_cache', 'http://example.org/' ), $calls );
		$this->assertContains( array( 'rocket_clean_home', 'cache/example.org*' ), $calls );

		$GLOBALS['swiftling_tests_purged'] = array();
		swiftling_purge_cache_plugins_all();
		$calls = wp_list_pluck( $GLOBALS['swiftling_tests_purged'], 0 );
		foreach ( array( 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_everything', 'VarnishPurger::purge_url' ) as $call ) {
			$this->assertContains( $call, $calls );
		}
	}
}
