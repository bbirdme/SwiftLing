<?php
/**
 * Tests of the words in front of a list's addresses.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use WP_Error;
use WPDieException;

/**
 * The word in front of every address of a list or a content type, such as category in
 * /category/vijesti/, in each language: which lists have one, how it is saved, the links WordPress
 * builds, how a request finds its page and where the original word leads.
 */
class BasesTest extends TestCase {

	/**
	 * A post "kontakt", a category "vijesti", a tag "ljeto", a project "most" and a project named
	 * like its own word, and a product "sandala" whose word has two parts.
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * Makes the content types and the content, on a site with language folders.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		register_post_type(
			'swl_project',
			array(
				'public'      => true,
				'label'       => 'Projects',
				'has_archive' => true,
				'rewrite'     => array( 'slug' => 'project' ),
			)
		);
		register_post_type(
			'swl_product',
			array(
				'public'  => true,
				'label'   => 'Products',
				'rewrite' => array( 'slug' => 'shop/product' ),
			)
		);
		register_taxonomy(
			'swl_product_cat',
			'swl_product',
			array(
				'public'  => true,
				'label'   => 'Product categories',
				'rewrite' => array( 'slug' => 'product-category' ),
			)
		);
		$GLOBALS['wp_rewrite']->flush_rules();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);

		$this->ids['kontakt'] = self::factory()->post->create(
			array(
				'post_name'  => 'kontakt',
				'post_title' => 'Kontakt',
			)
		);
		$this->ids['vijesti'] = self::factory()->category->create(
			array(
				'slug' => 'vijesti',
				'name' => 'Vijesti',
			)
		);
		$this->ids['ljeto']   = self::factory()->tag->create( array( 'slug' => 'ljeto' ) );
		$this->ids['most']    = self::factory()->post->create(
			array(
				'post_type' => 'swl_project',
				'post_name' => 'most',
			)
		);
		$this->ids['project'] = self::factory()->post->create(
			array(
				'post_type' => 'swl_project',
				'post_name' => 'project',
			)
		);
		$this->ids['sandala'] = self::factory()->post->create(
			array(
				'post_type' => 'swl_product',
				'post_name' => 'sandala',
			)
		);
		$this->ids['obuca']   = self::factory()->term->create(
			array(
				'taxonomy' => 'swl_product_cat',
				'slug'     => 'obuca',
			)
		);
	}

	/**
	 * Removes the content types.
	 */
	public function tear_down() {
		unregister_taxonomy( 'swl_product_cat' );
		unregister_post_type( 'swl_product' );
		unregister_post_type( 'swl_project' );
		parent::tear_down();
	}

	/**
	 * Saves the word of a list in a language.
	 *
	 * @param string $key   List, e.g. "tax:category".
	 * @param string $word  Word.
	 * @param string $lang  Language.
	 * @param int    $index Which part of the list's word.
	 * @return string|WP_Error
	 */
	private function base( $key, $word, $lang = 'de', $index = 0 ) {
		return swiftling_save_base( 'base:' . $key . ':' . $index, swiftling_target_language( $lang )['locale'], $word );
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
	 * Lists with pages of their own and content types with a word of their own have a word to
	 * translate, each part apart; a placeholder in it has none. Posts and pages have no word.
	 */
	public function test_lists_with_a_word() {
		$lists = swiftling_base_lists();

		$this->assertSame( array( 'category' ), $lists['tax:category']['parts'] );
		$this->assertSame( array( 'tag' ), $lists['tax:post_tag']['parts'] );
		$this->assertSame( array( 'project' ), $lists['type:swl_project']['parts'] );
		$this->assertSame( array( 'shop', 'product' ), $lists['type:swl_product']['parts'] );
		$this->assertSame( array( 'product-category' ), $lists['tax:swl_product_cat']['parts'] );
		$this->assertSame( 'post', $lists['tax:category']['group'] );
		$this->assertSame( 'swl_product', $lists['tax:swl_product_cat']['group'] );
		$this->assertArrayNotHasKey( 'type:post', $lists );
		$this->assertArrayNotHasKey( 'type:page', $lists );
		$this->assertArrayNotHasKey( 'tax:post_format', $lists );

		register_post_type(
			'swl_shoe',
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'shoes/%swl_product_cat%' ),
			)
		);
		$this->assertSame( array( 0 => 'shoes' ), swiftling_base_lists()['type:swl_shoe']['parts'] );
		unregister_post_type( 'swl_shoe' );
	}

	/**
	 * A word is saved as typed; empty or the original's means the original again.
	 */
	public function test_saving_a_word() {
		$this->assertSame( 'kategorie', $this->base( 'tax:category', 'Kategorie' ) );
		$this->assertSame( 'kategorie', swiftling_get_slug( 'base:tax:category:0', 'de_DE' ) );
		$this->assertSame( 'reviewed', swiftling_slug_entry( 'base:tax:category:0', 'de_DE' )['state'] );

		$this->assertSame( '', $this->base( 'tax:category', 'category' ) );
		$this->assertSame( '', swiftling_get_slug( 'base:tax:category:0', 'de_DE' ) );
		$this->assertSame( '', $this->base( 'tax:category', '' ) );
	}

	/**
	 * A word taken in its language is refused, never made unique with a number, and says what has it.
	 */
	public function test_a_taken_word_is_refused() {
		$this->base( 'tax:category', 'kategorie' );

		$taken = $this->base( 'tax:post_tag', 'kategorie' );
		$this->assertInstanceOf( WP_Error::class, $taken );
		$this->assertStringContainsString( 'Categories', $taken->get_error_message(), 'Another list\'s word' );

		$taken = $this->base( 'tax:post_tag', 'kontakt' );
		$this->assertInstanceOf( WP_Error::class, $taken );
		$this->assertStringContainsString( '“Kontakt”', $taken->get_error_message(), 'The original slug of a post' );

		swiftling_save_slug( 'post:' . $this->ids['kontakt'], 'de_DE', 'kontaktieren' );
		$this->assertInstanceOf( WP_Error::class, $this->base( 'tax:post_tag', 'kontaktieren' ), 'A translated slug of a post' );
		$this->assertInstanceOf( WP_Error::class, $this->base( 'tax:post_tag', 'page' ), 'A reserved word' );
		$this->assertInstanceOf( WP_Error::class, $this->base( 'tax:post_tag', 'fr' ), 'A language folder' );
		$this->assertInstanceOf( WP_Error::class, $this->base( 'tax:post_tag', 'project' ), 'Another list\'s original word' );
		$this->assertSame( '', swiftling_get_slug( 'base:tax:post_tag:0', 'de_DE' ) );

		$this->assertSame( 'kategorie', $this->base( 'tax:post_tag', 'kategorie', 'fr' ), 'Another language is free' );

		// A post's slug cannot take a list's word either: it gets a number, as slugs do.
		$this->assertSame( 'kategorie-2', swiftling_save_slug( 'post:' . $this->ids['kontakt'], 'de_DE', 'kategorie' ) );
	}

	/**
	 * Links carry the word of the language they are for, in front of the term's or post's own slug.
	 */
	public function test_links() {
		$this->base( 'tax:category', 'kategorie' );
		$this->base( 'tax:post_tag', 'schlagwort' );
		$this->base( 'type:swl_project', 'projekt' );
		$this->base( 'type:swl_product', 'laden', 'de', 0 );
		$this->base( 'type:swl_product', 'produkt', 'de', 1 );
		$this->base( 'tax:swl_product_cat', 'produktkategorie' );
		swiftling_save_slug( 'term:' . $this->ids['vijesti'], 'de_DE', 'nachrichten' );

		$this->in_folder( 'de' );
		$this->assertSame( 'http://example.org/de/kategorie/nachrichten/', get_term_link( $this->ids['vijesti'] ) );
		$this->assertSame( 'http://example.org/de/schlagwort/ljeto/', get_term_link( $this->ids['ljeto'] ) );
		$this->assertSame( 'http://example.org/de/projekt/most/', get_permalink( $this->ids['most'] ) );
		$this->assertSame( 'http://example.org/de/projekt/project/', get_permalink( $this->ids['project'] ), 'The word, not the post\'s own slug' );
		$this->assertSame( 'http://example.org/de/projekt/', get_post_type_archive_link( 'swl_project' ) );
		$this->assertSame( 'http://example.org/de/laden/produkt/sandala/', get_permalink( $this->ids['sandala'] ) );
		$this->assertSame( 'http://example.org/de/produktkategorie/obuca/', get_term_link( $this->ids['obuca'] ) );

		$this->in_folder( 'fr' );
		$this->assertSame( 'http://example.org/fr/category/vijesti/', get_term_link( $this->ids['vijesti'] ) );
		$this->assertSame( 'http://example.org/fr/project/', get_post_type_archive_link( 'swl_project' ) );

		// The original: no language, no folder.
		$this->reset_state();
		remove_filter( 'home_url', 'swiftling_filter_home_url', 1 );
		$this->assertSame( 'http://example.org/category/vijesti/', get_term_link( $this->ids['vijesti'] ) );
		$this->assertSame( 'http://example.org/shop/product/sandala/', get_permalink( $this->ids['sandala'] ) );
	}

	/**
	 * A request with the word of its language finds the page.
	 */
	public function test_requests() {
		$this->base( 'tax:category', 'kategorie' );
		$this->base( 'type:swl_project', 'projekt' );
		$this->base( 'type:swl_product', 'laden', 'de', 0 );
		$this->base( 'type:swl_product', 'produkt', 'de', 1 );
		$this->in_folder( 'de' );

		$this->go_to( 'http://example.org/de/kategorie/vijesti/' );
		$this->assertTrue( is_category() );
		$this->assertSame( $this->ids['vijesti'], get_queried_object_id() );

		$this->go_to( 'http://example.org/de/projekt/most/' );
		$this->assertSame( $this->ids['most'], get_queried_object_id() );

		$this->go_to( 'http://example.org/de/projekt/' );
		$this->assertTrue( is_post_type_archive( 'swl_project' ) );

		$this->go_to( 'http://example.org/de/laden/produkt/sandala/' );
		$this->assertSame( $this->ids['sandala'], get_queried_object_id() );
	}

	/**
	 * The original word, or an earlier one, leads to the address with the word now, keeping the rest.
	 */
	public function test_redirects() {
		$this->base( 'tax:category', 'kategorie' );
		$this->base( 'type:swl_project', 'projekt' );
		$this->in_folder( 'de' );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->go_to( 'http://example.org/de/category/vijesti/?a=1' );
		$this->assertSame( 'http://example.org/de/kategorie/vijesti/?a=1', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );
		$this->assertSame( 301, $this->redirect_status );

		$this->go_to( 'http://example.org/de/project/most/' );
		$this->assertSame( 'http://example.org/de/projekt/most/', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );

		$this->go_to( 'http://example.org/de/project/' );
		$this->assertSame( 'http://example.org/de/projekt/', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );

		$this->base( 'tax:category', 'rubrik' );
		$this->go_to( 'http://example.org/de/kategorie/vijesti/' );
		$this->assertSame( 'http://example.org/de/rubrik/vijesti/', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );

		$this->go_to( 'http://example.org/de/rubrik/vijesti/' );
		$this->assert_no_redirect( 'swiftling_redirect_to_translated_slug' );
	}

	/**
	 * The switcher and hreflang give every language its own word, on a list and on a content type's archive.
	 */
	public function test_addresses_of_the_other_languages() {
		$this->base( 'tax:category', 'kategorie' );
		$this->base( 'tax:category', 'categorie', 'fr' );
		$this->base( 'type:swl_project', 'projekt' );
		$this->in_folder( 'de' );

		$this->go_to( 'http://example.org/de/kategorie/vijesti/' );
		$this->assertSame( 'http://example.org/fr/categorie/vijesti/', swiftling_current_url( 'fr' ) );
		$this->assertSame( 'http://example.org/category/vijesti/', swiftling_current_url( '' ) );

		$this->go_to( 'http://example.org/de/projekt/' );
		$this->assertSame( 'http://example.org/fr/project/', swiftling_current_url( 'fr' ) );
		$this->assertSame( 'http://example.org/project/', swiftling_current_url( '' ) );
	}

	/**
	 * With ?lang= the words work as slugs do: the links a page builds have no language and keep the
	 * original words; the address of a language, as the switcher and hreflang give it, has its word,
	 * and a request with it finds the page.
	 */
	public function test_parameter_format() {
		$this->base( 'tax:category', 'kategorie' );
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			)
		);
		$this->show_in( 'de' );
		$this->assertSame( 'http://example.org/category/vijesti/', get_term_link( $this->ids['vijesti'] ) );
		$this->assertSame( 'http://example.org/kategorie/vijesti/?lang=de', swiftling_term_language_url( get_term( $this->ids['vijesti'] ), 'de' ) );

		$this->go_to( 'http://example.org/kategorie/vijesti/?lang=de' );
		$this->assertTrue( is_category() );
		$this->assertSame( $this->ids['vijesti'], get_queried_object_id() );
	}

	/**
	 * The Addresses screen saves the words of a language, refuses a taken one and keeps what was typed.
	 */
	public function test_saving_the_screen() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_bases',
			array(
				'lang'  => 'de',
				'bases' => array(
					'base:tax:category:0'     => 'Kategorie',
					'base:tax:post_tag:0'     => 'kontakt',
					'base:type:swl_project:0' => '',
					'base:tax:unknown:0'      => 'egal',
				),
			)
		);
		$location = $this->redirect_of( 'swiftling_save_bases' );

		$this->assertStringContainsString( 'page=swiftling-addresses', $location );
		$this->assertStringContainsString( 'lang=de', $location );
		$this->assertStringContainsString( 'swiftling_notice=bases_saved', $location );
		$this->assertStringContainsString( 'swiftling_refused[' . rawurlencode( 'base:tax:post_tag:0' ) . ']=kontakt', $location );
		$this->assertSame( 'kategorie', swiftling_get_slug( 'base:tax:category:0', 'de_DE' ) );
		$this->assertSame( '', swiftling_get_slug( 'base:tax:post_tag:0', 'de_DE' ) );
		$this->assertNull( swiftling_slug_entry( 'base:tax:unknown:0', 'de_DE' ), 'Only the lists the screen shows' );
	}

	/**
	 * With plain permalinks addresses name pages by their number, so the screen saves no word.
	 */
	public function test_screen_saves_nothing_with_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_bases',
			array(
				'lang'  => 'de',
				'bases' => array( 'base:tax:category:0' => 'kategorie' ),
			)
		);
		$this->redirect_of( 'swiftling_save_bases' );
		$this->assertSame( '', swiftling_get_slug( 'base:tax:category:0', 'de_DE' ) );
	}

	/**
	 * Only people who manage the site change its addresses.
	 */
	public function test_saving_the_screen_needs_manage_options() {
		$this->become( 'editor' );
		$this->post_form( 'swiftling_save_bases', array( 'lang' => 'de' ) );
		$this->expectException( WPDieException::class );
		swiftling_save_bases();
	}

	/**
	 * The screen shows one language at a time: each list with its original word, the field and an address that follows.
	 */
	public function test_screen() {
		$this->base( 'tax:category', 'kategorie' );
		// The example is the most used category.
		wp_set_post_categories( $this->ids['kontakt'], array( $this->ids['vijesti'] ) );
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$_GET = array( 'lang' => 'de' );
		ob_start();
		( new Menu() )->renderScreen( 'addresses' );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );

		$this->assertStringContainsString( 'aria-current="page" href="' . esc_url( admin_url( 'admin.php?page=swiftling-addresses&lang=de' ) ) . '"', $html );
		$this->assertMatchesRegularExpression( '~name="bases\[base:tax:category:0\]"[^>]*value="kategorie"[^>]*placeholder="category"~', $html );
		$this->assertStringContainsString( 'name="bases[base:type:swl_product:1]"', $html );
		$this->assertStringContainsString( '/de/kategorie/vijesti/', $html );
		$this->assertStringContainsString( 'value="swiftling_save_bases"', $html );
		$this->assertStringContainsString( 'example.org/de/page/', $html, 'The format card still names a language' );
	}

	/**
	 * The address of a category in the editor says which word starts it and links to the screen.
	 */
	public function test_editor_names_the_word() {
		$this->base( 'tax:category', 'kategorie' );
		$this->become( 'administrator' );
		ob_start();
		swiftling_live_term_address( 'de', 'term:' . $this->ids['vijesti'], array() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '“kategorie” starts every address of Categories in Deutsch.', $html );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=swiftling-addresses&lang=de' ) ), $html );

		ob_start();
		swiftling_live_term_address( 'de', 'term:' . $this->ids['ljeto'], array() );
		$this->assertStringContainsString( '“tag” is still the original word in Deutsch.', (string) ob_get_clean() );
	}
}
