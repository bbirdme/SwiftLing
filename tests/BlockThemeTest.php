<?php
/**
 * Tests of a block theme's templates: which are listed, where each is checked, and their texts
 * translated where visitors see them.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A block theme, Twenty Twenty-Five, with its templates and a template part changed in the Site Editor.
 */
class BlockThemeTest extends TestCase {

	/**
	 * Runs every test with the block theme active.
	 */
	public function set_up() {
		parent::set_up();
		switch_theme( 'twentytwentyfive' );
		swiftling_forget_memo();
	}

	/**
	 * Clears the template a test rendered.
	 */
	public function tear_down() {
		unset( $GLOBALS['_wp_current_template_id'] );
		parent::tear_down();
	}

	/**
	 * Returns the kinds of pages, by key, built again.
	 *
	 * @return array<string, array>
	 */
	private function views() {
		swiftling_forget_memo();
		$views = array();
		foreach ( swiftling_site_views() as $view ) {
			$views[ $view['key'] ] = $view;
		}
		return $views;
	}

	/**
	 * Saves a template part of the theme as the Site Editor does when it is changed.
	 *
	 * @param string $slug    Slug, e.g. footer.
	 * @param string $area    header or footer.
	 * @param string $content Its blocks.
	 * @return int Post ID.
	 */
	private function part( $slug, $area, $content ) {
		$part = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => $slug,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
		wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
		wp_set_object_terms( $part, $area, 'wp_template_part_area' );
		return $part;
	}

	/**
	 * Renders blocks as a page made from a template would: inside the template.
	 *
	 * @param string $template Template ID, e.g. twentytwentyfive//page.
	 * @param string $blocks   Blocks of the template.
	 * @return string HTML.
	 */
	private function render( $template, $blocks ) {
		$GLOBALS['_wp_current_template_id'] = $template; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress's own global; As WordPress sets it when it picks the template; reset in tear_down().
		return do_blocks( $blocks );
	}

	/**
	 * Does what a page check of a template does: renders the page with markers, and reports as
	 * landed, as the browser does, only the values whose marker shows in the HTML, outside the
	 * content of posts, where a template's and a template part's texts are.
	 *
	 * @param string $key      Subject key.
	 * @param string $template Template ID.
	 * @param string $blocks   Blocks of the template.
	 * @return array What swiftling_save_probe() returns.
	 */
	private function check_template( $key, $template, $blocks ) {
		$html   = '';
		$leaves = $this->probe(
			function () use ( &$html, $template, $blocks ) {
				$html = $this->render( $template, $blocks );
			}
		);
		$found  = array();
		$order  = 0;
		foreach ( array_keys( $leaves ) as $id ) {
			if ( false !== strpos( $html, swiftling_marker( $id ) ) ) {
				$found[ $id ] = array( $this->landing( 'text', ++$order, array( 'content' => false ) ) );
			}
		}
		return swiftling_save_probe( swiftling_subject( $key ), array( 'leaves' => $leaves ), $found, array(), true );
	}

	/**
	 * Each template an example page uses is a row, checked on that page: the newest post, the
	 * newest page with the default template, a category, search results and the page for an
	 * address that does not exist. A page template of the theme is checked on a page that uses it;
	 * a template no example page uses is listed without one.
	 */
	public function test_templates_are_listed_with_their_example_pages() {
		$category = self::factory()->category->create( array( 'name' => 'Putovanja' ) );
		$post     = self::factory()->post->create( array( 'post_category' => array( $category ) ) );
		$page     = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$no_title = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'meta_input' => array( '_wp_page_template' => 'page-no-title' ),
			)
		);

		$views = $this->views();
		$this->assertSame( get_permalink( $post ), $views['template:twentytwentyfive//single']['url'] );
		$this->assertSame( get_permalink( $page ), $views['template:twentytwentyfive//page']['url'], 'Not the page with a template of its own' );
		$this->assertSame( get_permalink( $no_title ), $views['template:twentytwentyfive//page-no-title']['url'] );
		$this->assertSame( get_category_link( $category ), $views['template:twentytwentyfive//archive']['url'] );
		$this->assertContains( 'Category', $views['template:twentytwentyfive//archive']['uses'] );
		$this->assertSame( swiftling_not_found_url(), $views['template:twentytwentyfive//404']['url'] );
		$this->assertArrayHasKey( 'template:twentytwentyfive//search', $views );
		$this->assertSame( '', $views['template:twentytwentyfive//index']['url'], 'Shown by no example page' );
	}

	/**
	 * A template is a subject of the editor, checked on its example page; a template no example
	 * page uses has no page check, and one the theme does not have is no subject.
	 */
	public function test_template_subject() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		swiftling_forget_memo();

		$subject = swiftling_subject( 'template:twentytwentyfive//page' );
		$this->assertSame( 'template', $subject['kind'] );
		$this->assertSame( get_permalink( $page ), $subject['url'] );
		$this->assertTrue( $subject['probe'] );
		$this->assertFalse( swiftling_subject( 'template:twentytwentyfive//index' )['probe'] );
		$this->assertNull( swiftling_subject( 'template:twentytwentyfive//nothing' ) );
	}

	/**
	 * A template's own text and the text of a template part changed in the Site Editor are found on
	 * the template's page, the part's as what every page shows, also in the editor of the footer;
	 * translated there, visitors read them in the language.
	 */
	public function test_template_and_template_part_are_translated() {
		$this->part( 'footer', 'footer', "<!-- wp:paragraph -->\n<p>Otvoreno od ponedjeljka do petka.</p>\n<!-- /wp:paragraph -->" );
		$blocks = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Naši vodiči</h2>\n<!-- /wp:heading -->\n\n"
			. '<!-- wp:template-part {"slug":"footer","theme":"twentytwentyfive","tagName":"footer"} /-->';
		$result = $this->check_template( 'template:twentytwentyfive//page', 'twentytwentyfive//page', $blocks );
		$own    = $this->found( $result, 'Naši vodiči' );
		$shared = $this->found( $result, 'Otvoreno' );
		$this->assertSame( 'template:twentytwentyfive//page', $own['o'] );
		$this->assertSame( 'part:twentytwentyfive//footer', $shared['o'] );
		$this->assertSame( array( 'Naši vodiči' ), array_map( 'swiftling_visible_text', wp_list_pluck( $result['sections']['page'], 'v' ) ) );
		$this->assertSame( array( $shared['v'] ), wp_list_pluck( $result['sections']['site'], 'v' ) );

		$footer = $this->check_template( 'site:footer', 'twentytwentyfive//page', $blocks );
		$this->assertSame( array( $shared['v'] ), wp_list_pluck( $footer['sections']['page'], 'v' ), 'The footer lists its part by the part\'s area' );

		$this->translate( $own['o'], $own['p'], $own['v'], 'de', str_replace( 'Naši vodiči', 'Unsere Reiseführer', $own['v'] ) );
		$this->translate( $shared['o'], $shared['p'], $shared['v'], 'de', str_replace( 'Otvoreno od ponedjeljka do petka.', 'Geöffnet von Montag bis Freitag.', $shared['v'] ) );

		$this->show_in( 'de' );
		$html = $this->render( 'twentytwentyfive//page', $blocks );
		$this->assertStringContainsString( 'Unsere Reiseführer</h2>', $html );
		$this->assertStringContainsString( '>Geöffnet von Montag bis Freitag.</p>', $html );
		$this->assertStringNotContainsString( 'Otvoreno', $html );
		$this->assertStringContainsString( '<footer', $html, 'The part prints with its tag' );
	}
	/**
	 * The Page List block, the menu of a block theme before a menu is made, shows each page's title
	 * in the language of the page, as the page itself and its link do. Core prints the title as
	 * stored, without the_title, so it is put into the language where get_pages() hands it over.
	 */
	public function test_page_list_shows_translated_titles() {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'O nama',
			)
		);
		$this->check(
			'post:' . $page,
			static function () use ( $page ) {
				get_the_title( $page );
			}
		);
		$this->translate( 'post:' . $page, 'field/post_title', 'O nama', 'de', 'Über uns' );

		$this->show_in( 'de' );
		$html = do_blocks( '<!-- wp:page-list /-->' );
		$this->assertStringContainsString( '>Über uns</a>', $html );
		$this->assertStringNotContainsString( 'O nama', $html );
		$this->assertStringContainsString( '>Über uns</a>', wp_list_pages( array( 'echo' => false ) ), 'A classic theme\'s list, which runs the_title too, once' );
		$this->assertSame( 'O nama', get_post( $page )->post_title, 'The post itself keeps its title' );
	}
}
