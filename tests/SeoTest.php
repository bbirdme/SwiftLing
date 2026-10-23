<?php
/**
 * Tests of what search engines and social networks read in the head of a page in a language.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Yoast SEO's, Rank Math's, SEOPress's, All in One SEO's and WordPress's own hooks, run as they run them.

namespace SwiftLing\Tests;

/**
 * The canonical address, og:locale, and what Yoast SEO and Rank Math get from SwiftLing.
 */
class SeoTest extends TestCase {

	/**
	 * A post "kontakt" with the German slug "kontaktieren", and a page "o-nama".
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
		$this->ids['kontakt'] = self::factory()->post->create(
			array(
				'post_name'    => 'kontakt',
				'post_title'   => 'Kontakt',
				'post_content' => 'Prva<!--nextpage-->Druga',
				'post_excerpt' => '',
			)
		);
		$this->ids['o-nama']  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'o-nama',
				'post_title' => 'O nama',
			)
		);
		swiftling_save_slug( 'post:' . $this->ids['kontakt'], 'de_DE', 'kontaktieren' );
		swiftling_save_slug( 'post:' . $this->ids['o-nama'], 'de_DE', 'uber-uns' );
	}

	/**
	 * Opens an address in a language, as a request for it would.
	 *
	 * @param string $url  Address.
	 * @param string $slug Language.
	 */
	private function open( $url, $slug ) {
		$this->show_in( $slug );
		if ( 'directory' === swiftling_url_format() ) {
			swiftling_keep_folder( $slug );
		}
		$this->go_to( $url );
	}

	/**
	 * Returns an indexable of Yoast SEO as it reads one from its table.
	 *
	 * @param string $type      Object type.
	 * @param int    $id        Object ID.
	 * @param string $permalink Stored address.
	 * @return object
	 */
	private function indexable( $type, $id, $permalink ) {
		return (object) array(
			'object_type'      => $type,
			'object_id'        => $id,
			'permalink'        => $permalink,
			'title'            => null,
			'description'      => null,
			'breadcrumb_title' => 'stored',
		);
	}

	/**
	 * Translates the title of the post "kontakt" into German.
	 */
	private function translate_title() {
		$id = $this->ids['kontakt'];
		$this->check(
			'post:' . $id,
			static function () use ( $id ) {
				get_the_title( $id );
			}
		);
		$this->translate( 'post:' . $id, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
	}

	/**
	 * Prints the meta description as Hello Elementor 3.5 does on wp_head
	 * (hello_elementor_add_description_meta_tag()): the excerpt of the post shown, from the post
	 * object, unless its filter says no.
	 *
	 * @return string
	 */
	private function hello_elementor_description() {
		ob_start();
		if ( apply_filters( 'hello_elementor_description_meta_tag', true ) && is_singular() ) {
			$post = get_queried_object();
			if ( ! empty( $post->post_excerpt ) ) {
				echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $post->post_excerpt ) ) . '">' . "\n";
			}
		}
		return (string) ob_get_clean();
	}

	/**
	 * Hello Elementor names a page in the head with its excerpt read from the post, past
	 * get_the_excerpt: a page in a language names itself with the excerpt's translation; a page
	 * check finds it there; the original's page and a page without an excerpt stay as the theme
	 * prints them.
	 */
	public function test_hello_elementor_description() {
		$id = $this->ids['kontakt'];
		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => 'Pišite nam & nazovite',
			)
		);
		$excerpt = static function () use ( $id ) {
			get_the_excerpt( $id );
		};
		$this->check( 'post:' . $id, $excerpt );
		// As WordPress stored it: "&amp;" for a user who may not write HTML.
		$this->translate( 'post:' . $id, 'field/post_excerpt', get_post_field( 'post_excerpt', $id ), 'de', 'Schreiben Sie uns & rufen Sie an' );

		$this->go_to( get_permalink( $id ) );
		$this->assertSame( '<meta name="description" content="Pišite nam &amp; nazovite">' . "\n", $this->hello_elementor_description(), 'The original' );

		$this->open( home_url( '/de/kontaktieren/' ), 'de' );
		$this->assertSame( '<meta name="description" content="Schreiben Sie uns &amp; rufen Sie an">' . "\n", $this->hello_elementor_description() );

		// A page check finds the excerpt in the description, with its marker.
		$this->reset_state();
		swiftling_start( 'probe' );
		$this->go_to( get_permalink( $id ) );
		$this->assertMatchesRegularExpression( '~<meta name="description" content="Pišite nam &amp; nazovite' . swiftling_marker( '\d+' ) . '">~', $this->hello_elementor_description() );
		$this->reset_state();

		$this->open( home_url( '/de/uber-uns/' ), 'de' );
		$this->assertSame( '', $this->hello_elementor_description(), 'No excerpt' );
	}

	/**
	 * The canonical address of a page in a language is the page itself, also on its second page.
	 */
	public function test_canonical_in_folders() {
		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		$this->assertSame( 'http://example.org/de/kontaktieren/', wp_get_canonical_url() );
		$this->open( 'http://example.org/de/kontaktieren/2/', 'de' );
		$this->assertSame( 'http://example.org/de/kontaktieren/2/', wp_get_canonical_url() );

		$this->reset_state();
		remove_filter( 'home_url', 'swiftling_filter_home_url', 1 );
		$this->go_to( 'http://example.org/kontakt/' );
		$this->assertSame( 'http://example.org/kontakt/', wp_get_canonical_url() );
	}

	/**
	 * In the parameter format the canonical address has the language and the translated slug,
	 * though the links of the page keep the original's.
	 */
	public function test_canonical_with_parameter() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->open( 'http://example.org/kontaktieren/?lang=de', 'de' );
		$this->assertSame( 'http://example.org/kontakt/', get_permalink( $this->ids['kontakt'] ) );
		$this->assertSame( 'http://example.org/kontaktieren/?lang=de', wp_get_canonical_url() );
		$this->open( 'http://example.org/kontaktieren/2/?lang=de', 'de' );
		$this->assertSame( 'http://example.org/kontaktieren/2/?lang=de', wp_get_canonical_url() );
	}

	/**
	 * A canonical address typed into an SEO plugin for another page of the site is that page's address
	 * in the language, its translated slug included, never one that sends on to it: a canonical
	 * address that redirects is a weak one. So for a post, a page and a category, with what follows
	 * the path kept, and in the parameter format. An address of the site that names nothing, and one
	 * of another site, stay as before. Yoast SEO's, typed into its field, too.
	 */
	public function test_canonical_of_another_page() {
		$news = self::factory()->category->create( array( 'slug' => 'vijesti' ) );
		swiftling_save_slug( 'term:' . $news, 'de_DE', 'nachrichten' );
		$prices = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'cjenik',
			)
		);
		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		$this->assertSame( 'http://example.org/de/uber-uns/', apply_filters( 'get_canonical_url', 'http://example.org/o-nama/' ) );
		$this->assertSame( 'http://example.org/de/uber-uns/', apply_filters( 'rank_math/frontend/canonical', 'http://example.org/o-nama/' ) );
		$this->assertSame( 'http://example.org/de/uber-uns/', apply_filters( 'aioseo_canonical_url', 'http://example.org/o-nama/' ) );
		$this->assertSame( 'http://example.org/de/uber-uns/?ref=1#tim', apply_filters( 'aioseo_canonical_url', 'http://example.org/o-nama/?ref=1#tim' ), 'What follows the path stays' );
		$this->assertSame( 'http://example.org/de/category/nachrichten/', apply_filters( 'aioseo_canonical_url', get_term_link( $news ) ) );
		$this->assertSame( 'http://example.org/de/cjenik/', apply_filters( 'aioseo_canonical_url', get_permalink( $prices ) ), 'A page without a slug of its own in the language' );
		$this->assertSame( 'http://example.org/de/nema-toga/', apply_filters( 'aioseo_canonical_url', 'http://example.org/nema-toga/' ), 'An address that names nothing' );
		$this->assertSame( 'http://example.org/de/kontaktieren/', apply_filters( 'aioseo_canonical_url', 'http://example.org/kontakt/' ), 'The page itself' );

		$indexable            = $this->indexable( 'post', $this->ids['kontakt'], 'http://example.org/kontakt/' );
		$indexable->canonical = 'http://example.org/o-nama/';
		swiftling_yoast_localize( $indexable );
		$this->assertSame( 'http://example.org/de/uber-uns/', $indexable->canonical, 'Yoast SEO\'s, typed into its field' );
		$this->assertSame( 'http://example.org/de/kontaktieren/', $indexable->permalink );

		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->open( 'http://example.org/kontaktieren/?lang=de', 'de' );
		$this->assertSame( 'http://example.org/uber-uns/?lang=de', apply_filters( 'aioseo_canonical_url', 'http://example.org/o-nama/' ), 'In the parameter format' );
	}

	/**
	 * The edge cases of a canonical address typed for another page (see
	 * test_canonical_of_another_page()): a slug with letters outside ASCII, in any form of its
	 * percent-encoding; a path that only contains a taxonomy's word, and a term whose slug is also
	 * another term's under another parent; an address with a query (?page_id=, ?cat=) and the front
	 * page; a page under a parent; one that is not public; a host of another site; and a canonical
	 * localized twice, as Yoast SEO's indexable is, which stays as it is.
	 */
	public function test_canonical_of_another_page_edge_cases() {
		$accent   = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'cjenik-za-djecu',
				'post_title' => 'Cjenik',
			)
		);
		$cyrillic = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'цјеник',
				'post_title' => 'Цјеник',
			)
		);
		swiftling_save_slug( 'post:' . $cyrillic, 'de_DE', 'preisliste' );
		$parent = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'tvrtka',
			)
		);
		$child  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'tim',
				'post_parent' => $parent,
			)
		);
		swiftling_save_slug( 'post:' . $parent, 'de_DE', 'firma' );
		swiftling_save_slug( 'post:' . $child, 'de_DE', 'mannschaft' );
		$draft  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'skica',
				'post_status' => 'draft',
			)
		);
		$first  = self::factory()->category->create( array( 'slug' => 'vijesti' ) );
		$second = self::factory()->category->create(
			array(
				'slug'   => 'vijesti',
				'parent' => self::factory()->category->create( array( 'slug' => 'tvrtka-vijesti' ) ),
			)
		);
		swiftling_save_slug( 'term:' . $second, 'de_DE', 'nachrichten-der-firma' );
		$canonical = static function ( $url ) {
			return apply_filters( 'aioseo_canonical_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		};
		$this->open( 'http://example.org/de/kontaktieren/', 'de' );

		// Letters outside ASCII, in either case of the percent-encoding and as the letters themselves.
		$this->assertSame( 'http://example.org/de/preisliste/', $canonical( 'http://example.org/%D1%86%D1%98%D0%B5%D0%BD%D0%B8%D0%BA/' ) );
		$this->assertSame( 'http://example.org/de/preisliste/', $canonical( 'http://example.org/%d1%86%d1%98%d0%b5%d0%bd%d0%b8%d0%ba/' ) );
		$this->assertSame( 'http://example.org/de/preisliste/', $canonical( 'http://example.org/цјеник/' ) );

		// A page under a parent: the parent's slug is translated too, not only the page's.
		$this->assertSame( 'http://example.org/de/firma/mannschaft/', $canonical( 'http://example.org/tvrtka/tim/' ) );

		// A path that only holds a taxonomy's word is no term; the right one of two with a slug.
		$this->assertSame( 'http://example.org/de/shop/category/vijesti/', $canonical( 'http://example.org/shop/category/vijesti/' ), 'Not a path of the category list' );
		$this->assertSame( 'http://example.org/de/category/tvrtka-vijesti/nachrichten-der-firma/', $canonical( get_term_link( $second ) ), 'The term under the parent' );
		$this->assertSame( 'http://example.org/de/category/vijesti/', $canonical( get_term_link( $first ) ), 'The term without a translated slug' );

		// A query naming an object, the front page, and what follows is kept.
		$this->assertSame( 'http://example.org/de/preisliste/', $canonical( 'http://example.org/?page_id=' . $cyrillic ) );
		$this->assertSame( 'http://example.org/de/preisliste/?ref=1', $canonical( 'http://example.org/?page_id=' . $cyrillic . '&ref=1' ) );
		$this->assertSame( 'http://example.org/de/category/tvrtka-vijesti/nachrichten-der-firma/', $canonical( 'http://example.org/?cat=' . $second ) );
		$this->assertSame( 'http://example.org/de/', $canonical( 'http://example.org/' ) );

		// What is not public, or not of this site, stays as it was.
		$this->assertSame( 'http://example.org/de/skica/', $canonical( 'http://example.org/skica/' ), 'A draft names no address' );
		$this->assertSame( 'https://other.example/o-nama/', $canonical( 'https://other.example/o-nama/' ) );
		$this->assertSame( 'http://www.example.org/o-nama/', $canonical( 'http://www.example.org/o-nama/' ), 'Another host of the same name' );
		$this->assertSame( 'http://example.org/de/cjenik-za-djecu/', $canonical( get_permalink( $accent ) ) );

		// Localized twice, as one indexable is for the page and the breadcrumbs: the same.
		$indexable            = $this->indexable( 'post', $this->ids['kontakt'], 'http://example.org/kontakt/' );
		$indexable->canonical = 'http://example.org/o-nama/';
		swiftling_yoast_localize( $indexable );
		swiftling_yoast_localize( $indexable );
		$this->assertSame( 'http://example.org/de/uber-uns/', $indexable->canonical );
		$this->assertSame( 'http://example.org/de/kontaktieren/', $indexable->permalink );

		// An address resolved once is not resolved again for the request.
		$resolved = 0;
		$count    = static function ( $id ) use ( &$resolved ) {
			++$resolved;
			return $id;
		};
		add_filter( 'url_to_postid', $count );
		$canonical( 'http://example.org/tvrtka/' );
		$canonical( 'http://example.org/tvrtka/' );
		$canonical( 'http://example.org/tvrtka/' );
		remove_filter( 'url_to_postid', $count );
		$this->assertSame( 1, $resolved, 'Remembered for the request' );

		// On the page of a parent, the canonical of its child: the child's slug is translated too,
		// not only the part of the path the two share.
		$this->open( 'http://example.org/de/firma/', 'de' );
		$this->assertSame( 'http://example.org/de/firma/mannschaft/', $canonical( 'http://example.org/tvrtka/tim/' ), 'On the page of the parent' );
	}

	/**
	 * The second review of test_canonical_of_another_page_edge_cases(): the query of an address stays
	 * as typed; a query names an object only for an address with no path, and a path is the object it
	 * says or nothing; a taxonomy whose word has a slash; what the request changes is not remembered;
	 * the shown page's own address looks nothing up.
	 */
	public function test_canonical_of_another_page_second_review() {
		$canonical = static function ( $url ) {
			return apply_filters( 'aioseo_canonical_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		};
		$draft     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'skica',
				'post_status' => 'draft',
			)
		);
		$news      = self::factory()->category->create( array( 'slug' => 'vijesti' ) );
		$this->open( 'http://example.org/de/kontaktieren/', 'de' );

		// The query as typed: a dot in a name, a key twice, a key without a value.
		$this->assertSame( 'http://example.org/de/uber-uns/?utm.source=x&a=1&a=2&flag#top', $canonical( 'http://example.org/o-nama/?utm.source=x&a=1&a=2&flag#top' ) );
		$this->assertSame( 'http://example.org/de/uber-uns/?b=%C5%BE+1', $canonical( 'http://example.org/o-nama/?b=%C5%BE+1' ), 'Not decoded' );
		$this->assertSame( 'http://example.org/de/uber-uns/?utm.source=x&a=1', $canonical( 'http://example.org/?page_id=' . $this->ids['o-nama'] . '&utm.source=x&a=1' ), 'By a query, the rest as typed' );

		// With a path, the path decides: a query naming another object, or none, changes nothing.
		$this->assertSame( 'http://example.org/de/uber-uns/?page_id=' . $this->ids['kontakt'], $canonical( 'http://example.org/o-nama/?page_id=' . $this->ids['kontakt'] ), 'The path, not the query' );
		$this->assertSame( 'http://example.org/de/category/vijesti/?p=' . $draft, $canonical( 'http://example.org/category/vijesti/?p=' . $draft ), 'A draft in a query is no other object' );
		$this->assertSame( 'http://example.org/de/category/vijesti/?cat=99999', $canonical( 'http://example.org/category/vijesti/?cat=99999' ) );
		$this->assertSame( 'http://example.org/de/?cat=99999', $canonical( 'http://example.org/?cat=99999' ), 'A term that is not there' );
		$this->assertSame( 'http://example.org/de/?p=' . $draft, $canonical( 'http://example.org/?p=' . $draft ), 'A draft names no address' );

		// A taxonomy whose word holds a slash.
		register_taxonomy(
			'brand',
			'post',
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'shop/brand' ),
			)
		);
		flush_rewrite_rules();
		$brand = self::factory()->term->create(
			array(
				'taxonomy' => 'brand',
				'name'     => 'Acme',
				'slug'     => 'acme',
			)
		);
		swiftling_save_slug( 'term:' . $brand, 'de_DE', 'marke-acme' );
		$this->assertSame( 'http://example.org/de/shop/brand/marke-acme/', $canonical( 'http://example.org/shop/brand/acme/' ) );

		// What the request changes is not remembered: a draft published, a slug changed.
		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( 'http://example.org/de/skica/', $canonical( 'http://example.org/skica/' ), 'Published since' );
		swiftling_save_slug( 'post:' . $draft, 'de_DE', 'entwurf' );
		$this->assertSame( 'http://example.org/de/entwurf/', $canonical( 'http://example.org/skica/' ), 'Slug changed since' );
		wp_update_term( $news, 'category', array( 'slug' => 'novosti' ) );
		$this->assertSame( 'http://example.org/de/category/novosti/', $canonical( 'http://example.org/category/novosti/' ) );
		swiftling_save_slug( 'term:' . $news, 'de_DE', 'neues' );
		$this->assertSame( 'http://example.org/de/category/neues/', $canonical( 'http://example.org/category/novosti/' ), 'Term slug changed since' );

		// The memo is bounded.
		for ( $i = 0; $i < 300; ++$i ) {
			$canonical( 'http://example.org/nema-' . $i . '/' );
		}
		$remembered = count( swiftling_state()['values']['object-addresses'] );
		$this->assertGreaterThan( 0, $remembered, 'Something is remembered' );
		$this->assertLessThanOrEqual( 200, $remembered );

		// The shown page's own address looks nothing up, as before.
		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		$resolved = 0;
		$count    = static function ( $id ) use ( &$resolved ) {
			++$resolved;
			return $id;
		};
		add_filter( 'url_to_postid', $count );
		$this->assertSame( 'http://example.org/de/kontaktieren/', $canonical( 'http://example.org/kontakt/' ) );
		remove_filter( 'url_to_postid', $count );
		$this->assertSame( 0, $resolved, 'The page itself looks nothing up' );
	}

	/**
	 * On a site in a folder of its host, as example.org/blog, a canonical typed for another page is
	 * that page in the language, inside the folder.
	 */
	public function test_canonical_of_another_page_in_a_folder() {
		update_option( 'home', 'http://example.org/blog' );
		update_option( 'siteurl', 'http://example.org/blog' );
		$this->use_pretty_permalinks();
		$this->open( 'http://example.org/blog/de/kontaktieren/', 'de' );
		$this->assertSame( 'http://example.org/blog/de/uber-uns/', apply_filters( 'aioseo_canonical_url', 'http://example.org/blog/o-nama/' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin\'s own filter.
		$id = (int) get_page_by_path( 'o-nama' )->ID;
		$this->assertSame( 'http://example.org/blog/de/uber-uns/', apply_filters( 'aioseo_canonical_url', 'http://example.org/blog/?page_id=' . $id ), 'By a query, in a folder' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin\'s own filter.
		$this->assertSame( 'http://example.org/blog/de/uber-uns/?0', apply_filters( 'aioseo_canonical_url', 'http://example.org/blog/o-nama/?0' ), 'A query piece of 0' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin\'s own filter.
		$this->assertSame( 'http://example.org/blog/de/uber-uns/?=page_id&x=1', apply_filters( 'aioseo_canonical_url', 'http://example.org/blog/?page_id=' . $id . '&=page_id&x=1' ), 'A stray piece is kept' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin\'s own filter.
	}

	/**
	 * Rank Math's canonical address is the page in its language; one that leads to another site stays.
	 */
	public function test_rank_math_canonical() {
		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		$this->assertSame( 'http://example.org/de/kontaktieren/', apply_filters( 'rank_math/frontend/canonical', 'http://example.org/de/kontaktieren/' ) );
		$this->assertSame( 'https://other.example/kontakt/', apply_filters( 'rank_math/frontend/canonical', 'https://other.example/kontakt/' ) );
	}

	/**
	 * The og:locale tag names the page's language, also the original's where WordPress runs in another one,
	 * and a custom language, which runs WordPress in its close language.
	 */
	public function test_og_locale() {
		$this->assertSame( 'hr', apply_filters( 'wpseo_locale', 'en_US' ) );
		$this->show_in( 'de' );
		$this->assertSame( 'de_DE', apply_filters( 'wpseo_locale', 'de_DE' ) );

		$this->set_languages(
			array(
				'fa_AF' => array(
					'slug'   => 'prs',
					'name'   => 'Dari',
					'custom' => array(
						'wp_locale' => 'en_US',
						'rtl'       => true,
					),
				),
			)
		);
		$this->show_in( 'prs' );
		$this->assertSame( 'fa_AF', apply_filters( 'wpseo_locale', 'en_US' ) );
	}

	/**
	 * The language of Yoast SEO's structured data (inLanguage) is the page's, as its lang attribute
	 * says: WordPress's German texts give "de", and a custom language runs WordPress in its close
	 * language, whose language Yoast SEO named.
	 */
	public function test_yoast_schema_language() {
		$this->assertSame( 'hr', apply_filters( 'wpseo_schema_piece_language', 'en-US', array() ) );
		$this->show_in( 'de' );
		$this->assertSame( 'de-DE', apply_filters( 'wpseo_schema_piece_language', 'de', array() ) );

		$this->set_languages(
			array(
				'fa_AF' => array(
					'slug'   => 'prs',
					'name'   => 'Dari',
					'custom' => array(
						'wp_locale' => 'en_US',
						'rtl'       => true,
					),
				),
			)
		);
		$this->show_in( 'prs' );
		$this->assertSame( 'fa-AF', apply_filters( 'wpseo_schema_piece_language', 'en-US', array() ) );
	}

	/**
	 * The texts of a page when it is shared, og: and twitter:, have names of their own in the
	 * editor: a social title is not the title search engines show.
	 */
	public function test_social_texts_have_their_own_names() {
		$labels = \SwiftLing\Admin\Ui::partLabels();
		$this->assertSame( 'Title when shared', $labels['social_title'] );
		$this->assertSame( 'Description when shared', $labels['social_description'] );

		$script = (string) file_get_contents( SWIFTLING_DIR . 'assets/js/page-check.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local source file of the plugin.
		$this->assertStringContainsString( "'social_title'", $script );
		$this->assertStringContainsString( "'social_description'", $script );
	}

	/**
	 * Yoast SEO writes no indexable while SwiftLing renders a page.
	 */
	public function test_yoast_writes_nothing() {
		$this->assertTrue( apply_filters( 'wpseo_should_save_indexable', true, null ) );
		$this->show_in( 'de' );
		$this->assertFalse( apply_filters( 'wpseo_should_save_indexable', true, null ) );
		$this->reset_state();
		swiftling_start( 'probe' );
		$this->assertFalse( apply_filters( 'wpseo_should_save_indexable', true, null ) );
	}

	/**
	 * A page check finds Yoast SEO's title of a post, and a page in a language shows its translation
	 * with the address of the language, whatever address Yoast SEO stored.
	 */
	public function test_yoast_page() {
		$id = $this->ids['kontakt'];
		update_post_meta( $id, '_yoast_wpseo_title', 'Kontakt %%sep%% Tvrtka' );
		update_post_meta( $id, '_yoast_wpseo_metadesc', 'Pišite nam.' );
		$presentation = (object) array( 'model' => $this->indexable( 'post', $id, 'http://example.org/kontakt/' ) );

		$result = $this->check(
			'post:' . $id,
			static function () use ( $presentation ) {
				apply_filters( 'wpseo_frontend_presentation', $presentation );
			},
			array( 'meta/_yoast_wpseo_title' => 'head-title' )
		);
		$this->found( $result, 'Kontakt %%sep%% Tvrtka' );
		$this->translate( 'post:' . $id, 'meta/_yoast_wpseo_title', 'Kontakt %%sep%% Tvrtka', 'de', 'Kontaktieren %%sep%% Firma' );

		$presentation = (object) array( 'model' => $this->indexable( 'post', $id, 'http://example.org/kontakt/' ) );
		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		apply_filters( 'wpseo_frontend_presentation', $presentation );
		$this->assertSame( 'http://example.org/de/kontaktieren/', $presentation->model->permalink );
		$this->assertSame( 'Kontaktieren %%sep%% Firma', $presentation->model->title );
		$this->assertSame( 'Pišite nam.', $presentation->model->description );

		// The original's page shows what Yoast SEO stored.
		$presentation = (object) array( 'model' => $this->indexable( 'post', $id, 'http://example.org/kontakt/' ) );
		$this->reset_state();
		apply_filters( 'wpseo_frontend_presentation', $presentation );
		$this->assertSame( 'http://example.org/kontakt/', $presentation->model->permalink );
		$this->assertNull( $presentation->model->title );
	}

	/**
	 * Without a description of its own in Yoast SEO, the home page's is the tagline, which Yoast SEO
	 * stores as it was when it built the page's indexable: the original. A page in a language reads
	 * the tagline again, so its meta description and og:description are the translation, as its
	 * title already was. A description of its own stays as Yoast SEO keeps it.
	 */
	public function test_yoast_home_description_is_the_translated_tagline() {
		update_option( 'blogdescription', 'Obiteljski apartmani' );
		$this->check(
			'post:' . $this->ids['kontakt'],
			static function () {
				get_option( 'blogdescription' );
			}
		);
		$this->translate( 'options', 'option/blogdescription', 'Obiteljski apartmani', 'de', 'Familienapartments' );

		$stored              = $this->indexable( 'home-page', 0, 'http://example.org/' );
		$stored->description = 'Obiteljski apartmani';
		$this->open( 'http://example.org/?lang=de', 'de' );
		apply_filters( 'wpseo_frontend_presentation', (object) array( 'model' => $stored ) );
		$this->assertSame( 'Familienapartments', $stored->description );

		update_option( 'wpseo_titles', array( 'metadesc-home-wpseo' => 'Apartmani uz more' ) );
		$own              = $this->indexable( 'home-page', 0, 'http://example.org/' );
		$own->description = 'Apartmani uz more';
		apply_filters( 'wpseo_frontend_presentation', (object) array( 'model' => $own ) );
		$this->assertSame( 'Apartmani uz more', $own->description );

		// The original's page shows what Yoast SEO stored.
		delete_option( 'wpseo_titles' );
		$this->reset_state();
		$original              = $this->indexable( 'home-page', 0, 'http://example.org/' );
		$original->description = 'Obiteljski apartmani';
		apply_filters( 'wpseo_frontend_presentation', (object) array( 'model' => $original ) );
		$this->assertSame( 'Obiteljski apartmani', $original->description );
	}

	/**
	 * Yoast SEO's breadcrumbs get the addresses of the language and the translated titles.
	 */
	public function test_yoast_breadcrumbs() {
		$this->translate_title();
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$this->open( 'http://example.org/kontaktieren/?lang=de', 'de' );
		$crumbs = apply_filters(
			'wpseo_breadcrumb_indexables',
			array(
				$this->indexable( 'home-page', 0, 'http://example.org/' ),
				$this->indexable( 'post', $this->ids['o-nama'], 'http://example.org/o-nama/' ),
				$this->indexable( 'post', $this->ids['kontakt'], 'http://example.org/kontakt/' ),
			),
			null
		);
		$this->assertSame( array( 'http://example.org/?lang=de', 'http://example.org/uber-uns/?lang=de', 'http://example.org/kontaktieren/?lang=de' ), wp_list_pluck( $crumbs, 'permalink' ) );
		$this->assertSame( array( 'stored', 'O nama', 'Kontaktieren' ), wp_list_pluck( $crumbs, 'breadcrumb_title' ) );
	}

	/**
	 * The site in Yoast SEO's structured data gets the address of the page's language.
	 */
	public function test_yoast_schema_site() {
		$graph = array(
			array(
				'@type'    => 'WebPage',
				'@id'      => 'http://example.org/de/kontaktieren/#webpage',
				'isPartOf' => array( '@id' => 'http://example.org/#website' ),
			),
			array(
				'@type' => 'WebSite',
				'@id'   => 'http://example.org/#website',
				'url'   => 'http://example.org/',
			),
		);
		$this->assertSame( $graph, apply_filters( 'wpseo_schema_graph', $graph, null ) );

		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		$graph = apply_filters( 'wpseo_schema_graph', $graph, null );
		$this->assertSame( 'http://example.org/de/#website', $graph[0]['isPartOf']['@id'] );
		$this->assertSame( 'http://example.org/de/kontaktieren/#webpage', $graph[0]['@id'] );
		$this->assertSame( 'http://example.org/de/#website', $graph[1]['@id'] );
		$this->assertSame( 'http://example.org/de/', $graph[1]['url'] );
	}

	/**
	 * The title variable of Yoast SEO and Rank Math gives the translated title.
	 */
	public function test_title_variable() {
		$this->translate_title();
		$post  = get_post( $this->ids['kontakt'] );
		$yoast = array(
			'%%title%%' => 'Kontakt',
			'%%sep%%'   => '-',
		);
		$this->assertSame( $yoast, apply_filters( 'wpseo_replacements', $yoast, (object) (array) $post ) );

		$this->show_in( 'de' );
		$this->assertSame( 'Kontaktieren', apply_filters( 'wpseo_replacements', $yoast, (object) (array) $post )['%%title%%'] );
		$this->assertSame( 'Kontaktieren', apply_filters( 'rank_math/replacements', array( '%title%' => 'Kontakt' ), $post )['%title%'] );
		// A term has no title of a post.
		$this->assertSame( array( '%title%' => 'Vijesti' ), apply_filters( 'rank_math/replacements', array( '%title%' => 'Vijesti' ), get_term( 1 ) ) );
	}

	/**
	 * Returns the variables of a post as SEOPress builds them (seopress_get_dynamic_variables(),
	 * SEOPress 10.3): each variable's value in a list beside the list of variables, the title read
	 * with get_post_field(), the excerpt read with get_the_excerpt() for a post with one of its own,
	 * else cut from its content as stored, as the content's variable always is.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $excerpt The excerpt SEOPress made.
	 * @return array
	 */
	private function seopress_variables( $post, $excerpt ) {
		$content = 'Javite nam se svaki dan.';
		return array(
			'post'                                     => $post,
			'term'                                     => null,
			'seopress_excerpt_length'                  => 50,
			'seopress_get_the_excerpt'                 => $excerpt,
			'seopress_titles_template_variables_array' => array( '%%sep%%', '%%sitetitle%%', '%%title%%', '%%post_title%%', '%%post_excerpt%%', '%%excerpt%%', '%%post_content%%', '%%wc_single_short_desc%%' ),
			'seopress_titles_template_replace_array'   => array( '-', 'Lanterna', $post->post_title, $post->post_title, $excerpt, $excerpt, $content, $excerpt ),
		);
	}

	/**
	 * Returns SEOPress's variables as the filter leaves them, by variable.
	 *
	 * @param array $variables Variables, see seopress_variables().
	 * @return array<string, string>
	 */
	private function seopress_values( $variables ) {
		$variables = apply_filters( 'seopress_titles_template_variables', $variables );
		return array_combine( $variables['seopress_titles_template_variables_array'], $variables['seopress_titles_template_replace_array'] );
	}

	/**
	 * SEOPress's title variables give the post's title as SwiftLing reads it: translated in a language,
	 * marked in a page check. SEOPress reads it with get_post_field(), past the filters that translate
	 * it. The excerpt it cuts from the content of a post without one, and the content variable, are
	 * cut from the content in the language. An excerpt of the post's own SEOPress reads through
	 * get_the_excerpt, in the language already. The original's page stays as SEOPress built it.
	 */
	public function test_seopress_variables() {
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Pišite nam',
				'post_content' => '<p>Javite nam se svaki dan.</p>',
				'post_excerpt' => '',
			)
		);
		$owner  = 'post:' . $post->ID;
		$render = static function () use ( $post ) {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
			get_the_title( $post );
			apply_filters( 'the_content', $post->post_content );
		};
		$this->check( $owner, $render );
		unset( $GLOBALS['post'] );
		$this->translate( $owner, 'field/post_title', 'Pišite nam', 'de', 'Schreiben Sie uns' );
		$this->translate( $owner, 'field/post_content', '<p>Javite nam se svaki dan.</p>', 'de', '<p>Melden Sie sich jeden Tag.</p>' );

		$variables = $this->seopress_variables( $post, 'Javite nam se svaki dan.' );
		$this->assertSame( $variables, apply_filters( 'seopress_titles_template_variables', $variables ), 'The original' );

		$this->show_in( 'de' );
		$values = $this->seopress_values( $variables );
		$this->assertSame( array( 'Schreiben Sie uns', 'Schreiben Sie uns' ), array( $values['%%title%%'], $values['%%post_title%%'] ) );
		$this->assertSame( array_fill( 0, 4, 'Melden Sie sich jeden Tag.' ), array( $values['%%post_excerpt%%'], $values['%%excerpt%%'], $values['%%post_content%%'], $values['%%wc_single_short_desc%%'] ) );
		$this->assertSame( array( '-', 'Lanterna' ), array( $values['%%sep%%'], $values['%%sitetitle%%'] ), 'What the site gives stays' );
		$this->assertSame( 'Melden Sie sich jeden Tag.', apply_filters( 'seopress_titles_template_variables', $variables )['seopress_get_the_excerpt'] );

		// An excerpt of its own: SEOPress's, read in the language; the content variable is the content's.
		$post->post_excerpt = 'Kratko';
		$values             = $this->seopress_values( $this->seopress_variables( $post, 'Kurz' ) );
		$this->assertSame( array( 'Kurz', 'Melden Sie sich jeden Tag.' ), array( $values['%%post_excerpt%%'], $values['%%post_content%%'] ) );

		// A page check finds the title in the head, with its marker.
		$this->reset_state();
		swiftling_start( 'probe' );
		$this->assertMatchesRegularExpression( '~^Pišite nam' . swiftling_marker( '\d+' ) . '$~', $this->seopress_values( $variables )['%%post_title%%'] );
	}

	/**
	 * SEOPress's canonical address, its own or typed into its field, is the page in its language;
	 * one that leads to another site stays, and so does the original's. Typed in for another page of
	 * the site, it is that page's address in the language, see test_canonical_of_another_page().
	 */
	public function test_seopress_canonical() {
		$link = static function ( $url ) {
			return '<link rel="canonical" href="' . $url . '">';
		};
		$this->go_to( get_permalink( $this->ids['kontakt'] ) );
		$this->assertSame( $link( 'http://example.org/o-nama/' ), apply_filters( 'seopress_titles_canonical', $link( 'http://example.org/o-nama/' ) ) );

		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		$this->assertSame( $link( 'http://example.org/de/kontaktieren/' ), apply_filters( 'seopress_titles_canonical', $link( 'http://example.org/de/kontaktieren/' ) ) );
		$this->assertSame( $link( 'http://example.org/de/kontaktieren/' ), apply_filters( 'seopress_titles_canonical', $link( 'http://example.org/kontakt/' ) ), 'Typed into its field' );
		$this->assertSame( $link( 'http://example.org/de/uber-uns/' ), apply_filters( 'seopress_titles_canonical', $link( 'http://example.org/o-nama/' ) ) );
		$this->assertSame( $link( 'https://other.example/kontakt/' ), apply_filters( 'seopress_titles_canonical', $link( 'https://other.example/kontakt/' ) ) );
	}

	/**
	 * SEOPress's fields of a term, as its SEO title, are text: the editor of a list of categories
	 * translates them, also when the page of the category it checks has none. Without SEOPress the
	 * editor does not know.
	 */
	public function test_seopress_field_of_a_term() {
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$term = self::factory()->category->create( array( 'name' => 'Vijesti' ) );
		wp_set_post_categories( $this->ids['kontakt'], array( $term ) );
		update_term_meta( $term, '_seopress_titles_title', 'Vijesti iz grada' );
		$fields = static function () {
			return swiftling_term_fields( swiftling_subject( 'terms:category' ) );
		};
		$this->assertNull( $fields()['_seopress_titles_title']['shown'], 'Without SEOPress' );

		self::set_static( \SwiftLing\Builders\Registry::class, 'active', array( 'seopress' => new \SwiftLing\Builders\SeoPress() ) );
		try {
			$field = $fields()['_seopress_titles_title'];
		} finally {
			self::reset_static( \SwiftLing\Builders\Registry::class, 'active' );
		}
		$this->assertSame( array( true, true, 'SEO title' ), array( $field['shown'], $field['translated'], $field['label'] ) );
	}

	/**
	 * Returns All in One SEO's row of a post as its model gives it (Models\Post::getPost(), AIOSEO
	 * 5.0.3): the texts of the post as stored in its own table.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Columns.
	 * @return object
	 */
	private function aioseo_post( $post_id, $fields ) {
		return (object) array_merge(
			array(
				'post_id'             => $post_id,
				'title'               => null,
				'description'         => null,
				'og_title'            => null,
				'og_description'      => null,
				'twitter_title'       => null,
				'twitter_description' => null,
				'canonical_url'       => null,
				'robots_noindex'      => 0,
			),
			$fields
		);
	}

	/**
	 * All in One SEO's texts of a post, its SEO title, meta description and Facebook and X texts,
	 * read from its own table past the filters of meta, are read as the copy it keeps in the post's
	 * meta would be: marked in a page check, translated in a language. Its tags stay in the text. A
	 * text left empty stays empty, and so does the original's page.
	 */
	public function test_aioseo_fields() {
		$id     = $this->ids['kontakt'];
		$owner  = 'post:' . $id;
		$stored = array(
			'title'               => '#post_title #separator_sa Pišite nam',
			'description'         => 'Javite nam se svaki dan.',
			'og_title'            => 'Kontakt za goste',
			'twitter_description' => 'Pišite nam bilo kad.',
			'og_description'      => '',
		);
		$model  = function () use ( $id, $stored ) {
			return apply_filters( 'aioseo_get_post', $this->aioseo_post( $id, $stored ) );
		};
		$this->assertEquals( $this->aioseo_post( $id, $stored ), $model(), 'The original' );

		$this->check( $owner, $model );
		$this->translate( $owner, 'meta/_aioseo_title', '#post_title #separator_sa Pišite nam', 'de', '#post_title #separator_sa Schreiben Sie uns' );
		$this->translate( $owner, 'meta/_aioseo_description', 'Javite nam se svaki dan.', 'de', 'Melden Sie sich jeden Tag.' );
		$this->translate( $owner, 'meta/_aioseo_og_title', 'Kontakt za goste', 'de', 'Kontakt für Gäste' );
		$this->translate( $owner, 'meta/_aioseo_twitter_description', 'Pišite nam bilo kad.', 'de', 'Schreiben Sie uns jederzeit.' );

		$this->show_in( 'de' );
		$shown = $model();
		$this->assertSame(
			array( '#post_title #separator_sa Schreiben Sie uns', 'Melden Sie sich jeden Tag.', 'Kontakt für Gäste', 'Schreiben Sie uns jederzeit.' ),
			array( $shown->title, $shown->description, $shown->og_title, $shown->twitter_description )
		);
		$this->assertSame( array( '', null, null ), array( $shown->og_description, $shown->twitter_title, $shown->canonical_url ) );
		$this->assertSame( $id, $shown->post_id );

		// A page check finds them in the head, with their markers.
		$this->reset_state();
		swiftling_start( 'probe' );
		$this->assertMatchesRegularExpression( '~^Javite nam se svaki dan\.' . swiftling_marker( '\d+' ) . '$~', $model()->description );
	}

	/**
	 * All in One SEO's tags that read a post as stored, past the filters that translate it
	 * (Utils\Tags::getTagValue(), AIOSEO 5.0.3), give it as SwiftLing reads it in the titles and
	 * descriptions it prints: #post_excerpt and #post_excerpt_only, the post's own excerpt, and
	 * #parent_title, the parent's title, marked in a page check and translated in a language;
	 * #post_content, and #post_excerpt of a post without one, cut in a language from the content
	 * in the language, as AIOSEO cuts it. #post_title reads the title through get_the_title()
	 * already. The original's page stays as AIOSEO built it.
	 */
	public function test_aioseo_tags() {
		require_once __DIR__ . '/stubs/aioseo.php';
		$parent = $this->ids['o-nama'];
		$child  = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_parent'  => $parent,
				'post_title'   => 'Tim',
				'post_excerpt' => 'Upoznajte nas',
				'post_content' => '<p>Mi smo mala obitelj.</p>',
			)
		);
		$owner  = 'post:' . $child->ID;
		$this->check(
			$owner,
			static function () use ( $child ) {
				$GLOBALS['post'] = $child; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
				get_the_excerpt( $child );
				apply_filters( 'the_content', $child->post_content );
			}
		);
		unset( $GLOBALS['post'] );
		$this->translate( $owner, 'field/post_excerpt', 'Upoznajte nas', 'de', 'Lernen Sie uns kennen' );
		$this->translate( $owner, 'field/post_content', '<p>Mi smo mala obitelj.</p>', 'de', '<p>Wir sind eine kleine Familie.</p>' );
		$this->check(
			'post:' . $parent,
			static function () use ( $parent ) {
				get_the_title( $parent );
			}
		);
		$this->translate( 'post:' . $parent, 'field/post_title', 'O nama', 'de', 'Über uns' );

		// As AIOSEO puts the values of "#post_title - #parent_title" and "#post_excerpt | #post_content" in.
		$title       = static function () {
			return apply_filters( 'aioseo_title', 'Tim - O nama' );
		};
		$description = static function () {
			return apply_filters( 'aioseo_description', 'Upoznajte nas | Mi smo mala obitelj.' );
		};

		swiftling_test_aioseo_reset()->helpers->post = $child;
		$this->assertSame( array( 'Tim - O nama', 'Upoznajte nas | Mi smo mala obitelj.' ), array( $title(), $description() ), 'The original' );

		$this->show_in( 'de' );
		$this->assertSame( array( 'Tim - Über uns', 'Lernen Sie uns kennen | Wir sind eine kleine Familie.' ), array( $title(), $description() ) );

		// Without an excerpt of its own, #post_excerpt is the content's.
		$child->post_excerpt = '';
		$this->show_in( 'de' );
		$this->assertSame( 'Wir sind eine kleine Familie.', apply_filters( 'aioseo_description', 'Mi smo mala obitelj.' ) );

		// A page check finds the excerpt and the parent's title where AIOSEO prints them.
		$child->post_excerpt = 'Upoznajte nas';
		$this->reset_state();
		swiftling_start( 'probe' );
		$marker = swiftling_marker( '\d+' );
		$this->assertMatchesRegularExpression( '~^Tim - O nama' . $marker . '$~', $title() );
		$this->assertMatchesRegularExpression( '~^Upoznajte nas' . $marker . ' \| Mi smo mala obitelj\.$~', $description() );
	}

	/**
	 * All in One SEO's canonical address, its own or typed into its field, is the page in its
	 * language; one that leads to another site stays, and so does the original's.
	 */
	public function test_aioseo_canonical() {
		$this->go_to( get_permalink( $this->ids['kontakt'] ) );
		$this->assertSame( 'http://example.org/o-nama/', apply_filters( 'aioseo_canonical_url', 'http://example.org/o-nama/' ) );

		$this->open( 'http://example.org/de/kontaktieren/', 'de' );
		$this->assertSame( 'http://example.org/de/kontaktieren/', apply_filters( 'aioseo_canonical_url', 'http://example.org/kontakt/' ), 'Typed into its field' );
		$this->assertSame( 'http://example.org/de/kontaktieren/', apply_filters( 'aioseo_canonical_url', 'http://example.org/de/kontaktieren/' ) );
		$this->assertSame( 'https://other.example/kontakt/', apply_filters( 'aioseo_canonical_url', 'https://other.example/kontakt/' ) );
	}
}
