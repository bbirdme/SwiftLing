<?php
/**
 * Tests of the alternate addresses for search engines.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Links with hreflang in the head of a page.
 */
class HreflangTest extends TestCase {

	/**
	 * Makes a published post translated into some languages.
	 *
	 * @param string[] $slugs Languages.
	 * @return \WP_Post
	 */
	private function post( $slugs ) {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		foreach ( $slugs as $slug ) {
			$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', $slug, 'Kontakt ' . $slug );
		}
		swiftling_refresh_translated( array( $post->ID ) );
		return $post;
	}

	/**
	 * Language tags have the language, the script and the country, without WordPress's variants.
	 *
	 * @dataProvider data_codes
	 *
	 * @param string $locale   Locale.
	 * @param string $expected Tag.
	 */
	public function test_hreflang_code( $locale, $expected ) {
		$this->assertSame( $expected, swiftling_hreflang_code( $locale ) );
	}

	/**
	 * Locales and their tags.
	 *
	 * @return array
	 */
	public function data_codes() {
		return array(
			'language and country' => array( 'de_DE', 'de-DE' ),
			'formal variant'       => array( 'de_DE_formal', 'de-DE' ),
			'script'               => array( 'sr_Latn_RS', 'sr-Latn-RS' ),
			'three letters'        => array( 'cnr', 'cnr' ),
			'region number'        => array( 'es_419', 'es-419' ),
			'dashes'               => array( 'pt-br', 'pt-BR' ),
			'language only'        => array( 'hr', 'hr' ),
			'not a language'       => array( 'english', '' ),
			'empty'                => array( '', '' ),
		);
	}

	/**
	 * A post names the original, every language it is translated into, and x-default.
	 */
	public function test_post_names_its_languages() {
		$post = $this->post( array( 'de' ) );
		$this->go_to( get_permalink( $post ) );
		$url = get_permalink( $post );
		$this->assertSame(
			array(
				'hr'        => $url,
				'de-DE'     => add_query_arg( 'lang', 'de', $url ),
				'x-default' => $url,
			),
			swiftling_hreflangs()
		);
	}

	/**
	 * In the translated view the same list shows; a language the post is not translated into has none.
	 */
	public function test_translated_view() {
		$post = $this->post( array( 'de' ) );
		$this->show_in( 'de' );
		$this->go_to( get_permalink( $post ) );
		$this->assertCount( 3, swiftling_hreflangs() );

		$this->show_in( 'fr' );
		$this->assertSame( array(), swiftling_hreflangs(), 'Shown untranslated, it is no alternate' );
	}

	/**
	 * A language hidden from visitors is never named, and one language alone has no alternates.
	 */
	public function test_hidden_language_is_left_out() {
		$this->set_languages(
			array(
				'de_DE' => array(
					'slug'    => 'de',
					'visible' => false,
				),
			)
		);
		$post = $this->post( array( 'de' ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( array(), swiftling_hreflangs() );
	}

	/**
	 * Two languages with one tag: the first in the order keeps it.
	 */
	public function test_one_tag_per_language() {
		$this->set_languages(
			array(
				'de_DE'        => 'de',
				'de_DE_formal' => 'de-formal',
			)
		);
		$post = $this->post( array( 'de', 'de-formal' ) );
		$this->go_to( get_permalink( $post ) );
		$links = swiftling_hreflangs();
		$this->assertSame( array( 'hr', 'de-DE', 'x-default' ), array_keys( $links ) );
		$this->assertStringContainsString( 'lang=de', $links['de-DE'] );
		$this->assertStringNotContainsString( 'de-formal', $links['de-DE'] );
	}

	/**
	 * The front page of posts and archives name every visible language; searches and second pages none.
	 */
	public function test_lists() {
		self::factory()->post->create_many( 3 );
		$category = self::factory()->category->create();
		self::factory()->post->create( array( 'post_category' => array( $category ) ) );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( array( 'hr', 'de-DE', 'fr-FR', 'x-default' ), array_keys( swiftling_hreflangs() ) );

		$this->go_to( get_term_link( $category ) );
		$this->assertSame( array( 'hr', 'de-DE', 'fr-FR', 'x-default' ), array_keys( swiftling_hreflangs() ) );

		$this->go_to( home_url( '/?s=a' ) );
		$this->assertSame( array(), swiftling_hreflangs() );

		$this->go_to( home_url( '/?paged=2' ) );
		$this->assertSame( array(), swiftling_hreflangs() );
	}

	/**
	 * In the directory format the alternates are folders.
	 */
	public function test_directory_format() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$post = $this->post( array( 'de' ) );
		$this->go_to( get_permalink( $post ) );
		$links = swiftling_hreflangs();
		$this->assertSame( get_permalink( $post ), $links['hr'] );
		$this->assertSame( str_replace( 'http://example.org/', 'http://example.org/de/', get_permalink( $post ) ), $links['de-DE'] );
	}

	/**
	 * The links are printed escaped, and other plugins may change them.
	 */
	public function test_printed_links() {
		$post = $this->post( array( 'de' ) );
		$this->go_to( get_permalink( $post ) );
		add_filter(
			'swiftling_hreflangs',
			static function ( $links ) {
				$links['x-test'] = 'http://example.org/?a=1&b="2"';
				return $links;
			}
		);
		ob_start();
		swiftling_print_hreflangs();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<link rel="alternate" hreflang="de-DE" href="http://example.org/?p=' . $post->ID . '&#038;lang=de" />', $html );
		$this->assertStringContainsString( 'hreflang="x-test"', $html );
		$this->assertStringNotContainsString( '"2"', $html );
	}

	/**
	 * A page that shows the archive of a kind of content, as WooCommerce's shop page shows the
	 * products, has the alternates of the page: the languages it is translated into, at its own
	 * translated addresses. WooCommerce makes the shop page the queried object of the archive.
	 */
	public function test_page_that_shows_an_archive() {
		$this->use_pretty_permalinks();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		register_post_type(
			'product',
			array(
				'public'      => true,
				'has_archive' => 'trgovina',
			)
		);
		$this->set_permalink_structure( '/%postname%/' );
		self::factory()->post->create( array( 'post_type' => 'product' ) );
		$shop = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'Trgovina',
				'post_name'  => 'trgovina',
			)
		);
		$this->check(
			'post:' . $shop->ID,
			static function () use ( $shop ) {
				get_the_title( $shop );
			}
		);
		$this->translate( 'post:' . $shop->ID, 'field/post_title', 'Trgovina', 'de', 'Geschäft' );
		swiftling_refresh_translated( array( $shop->ID ) );
		swiftling_save_slug( 'post:' . $shop->ID, 'de_DE', 'geschaeft' );
		swiftling_forget_slugs();

		$this->go_to( get_post_type_archive_link( 'product' ) );
		$this->assertTrue( is_post_type_archive( 'product' ) );
		// What WooCommerce does on its shop page.
		$GLOBALS['wp_query']->queried_object    = $shop;
		$GLOBALS['wp_query']->queried_object_id = $shop->ID;

		$links = swiftling_hreflangs();
		$this->assertSame( array( 'hr', 'de-DE', 'x-default' ), array_keys( $links ), 'The languages the page is translated into' );
		$this->assertSame( 'http://example.org/trgovina/', $links['hr'] );
		$this->assertSame( 'http://example.org/de/geschaeft/', $links['de-DE'], 'The page\'s translated address' );
	}
}
