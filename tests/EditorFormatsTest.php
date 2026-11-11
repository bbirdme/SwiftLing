<?php
/**
 * Tests of the block editor's inline formats in the translation editor.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakePageBuilder.php';

/**
 * A post made of blocks gets the block editor's own field for its texts, with the formats the
 * block editor has, and a translation keeps them on the page.
 */
class EditorFormatsTest extends TestCase {

	/**
	 * A heading with a highlighted word, as the block editor writes it.
	 */
	const HEADING = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Naš <mark style=\"background-color:#503AA8\" class=\"has-inline-color has-accent-1-color\">podcast</mark> priča</h2>\n<!-- /wp:heading -->";

	/**
	 * Puts the queues of scripts and styles back, and the builders.
	 */
	public function tear_down() {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of styles.
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Loads the assets of the translation editor of a post, and says whether the block editor's
	 * field came with them.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function editor_has_formats( $post_id ) {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of styles.
		$_GET                  = array(
			'page' => 'swiftling-translation',
			'post' => (string) $post_id,
		);
		( new Menu() )->enqueueAssets( Menu::screens()['swiftling-translation'] + array( 'slug' => 'swiftling-translation' ) );
		return wp_script_is( 'swiftling-editor-formats' );
	}

	/**
	 * Only a post made of blocks gets the field; classic content and a post a page builder built
	 * keep the visual editor of WordPress.
	 */
	public function test_only_posts_of_blocks_get_the_field() {
		$this->become( 'administrator' );
		$blocks  = self::factory()->post->create( array( 'post_content' => self::HEADING ) );
		$classic = self::factory()->post->create( array( 'post_content' => '<p>Naš <strong>podcast</strong> priča</p>' ) );
		$built   = self::factory()->post->create( array( 'post_content' => self::HEADING ) );
		add_post_meta( $built, '_fake_builder', '1' );
		self::set_static( Registry::class, 'builders', Registry::all() + array( 'fakepagebuilder' => new FakePageBuilder() ) );
		self::reset_static( Registry::class, 'active' );

		$this->assertTrue( $this->editor_has_formats( $blocks ) );
		// The editor's script comes after it, and the formats of the block editor with it.
		$this->assertContains( 'swiftling-editor-formats', wp_scripts()->registered['swiftling-editor']->deps );
		$this->assertTrue( wp_script_is( 'wp-format-library' ) );
		$this->assertTrue( wp_style_is( 'wp-format-library' ) );
		$data = implode( '', (array) wp_scripts()->get_data( 'swiftling-editor-formats', 'before' ) );
		$this->assertStringContainsString( 'window.swiftlingEditorFormats = {', $data );

		$this->assertFalse( $this->editor_has_formats( $classic ), 'Classic content' );
		$this->assertTrue( wp_script_is( 'swiftling-editor' ), 'The editor itself still loads' );
		$this->assertFalse( $this->editor_has_formats( $built ), 'A page builder' );
		$this->assertFalse( $this->editor_has_formats( 0 ), 'No post' );
	}

	/**
	 * A translation with a highlight, as the field writes it, is saved as it is, also by someone who
	 * may not write unfiltered HTML, and the page in the language shows the highlight.
	 */
	public function test_translation_keeps_inline_formats() {
		$this->become( 'administrator' );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);
		$post   = self::factory()->post->create_and_get( array( 'post_content' => self::HEADING ) );
		$result = $this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
				setup_postdata( $post );
				apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
			}
		);

		$unit = $this->found( $result, 'podcast' );
		$mark = '<mark style="background-color:#503AA8" class="has-inline-color has-accent-1-color">';
		$this->assertStringContainsString( $mark, $unit['v'] );

		$text  = str_replace( array( 'Naš ', '</mark> priča' ), array( 'Notre ', '</mark> raconte' ), $unit['v'] );
		$key   = swiftling_place_key( 'post:' . $post->ID, swiftling_path_key( $unit['p'] ), swiftling_hash( $unit['v'] ) );
		$saved = swiftling_save_texts(
			'post:' . $post->ID,
			'fr',
			array(
				array(
					'key'      => $key,
					'text'     => $text,
					'original' => $unit['v'],
				),
			),
			'reviewed'
		);
		$this->assertSame( array( $key => 'reviewed' ), $saved['states'] );
		$this->assertSame( $text, swiftling_get_translations( 'fr_FR', array( $key ) )[ $key ]['text'] );

		$this->show_in( 'fr' );
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
		$this->assertStringContainsString( 'Notre ' . $mark . 'podcast</mark> raconte</h2>', $html );
	}
}
