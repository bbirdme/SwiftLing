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
		// A post of a type never translated has no editor, only the screen that says what to translate.
		update_option( 'swiftling_settings', array( 'never' => array( 'post' ) ) );
		$this->assertFalse( $this->editor_has_formats( $blocks ), 'Never translated' );
		delete_option( 'swiftling_settings' );

		// Breakdance, Oxygen and Brizy show their own data, also where blocks are left in the content.
		foreach ( array( '_breakdance_data', '_oxygen_data', 'ct_builder_shortcodes', 'ct_builder_json', 'brizy' ) as $key ) {
			$post = self::factory()->post->create( array( 'post_content' => self::HEADING ) );
			add_post_meta( $post, $key, '{"tree":{}}' );
			$this->assertFalse( $this->editor_has_formats( $post ), $key );
		}
		// Beaver Builder and SiteOrigin keep a whole layout in one block.
		$beaver = self::factory()->post->create_and_get( array( 'post_content' => "<!-- wp:fl-builder/layout -->\n<p>Raspored.</p>\n<!-- /wp:fl-builder/layout -->" ) );
		$this->assertSame( 'Beaver Builder', swiftling_live_builder( $beaver ) );
		$this->assertFalse( $this->editor_has_formats( $beaver->ID ) );
		$origin = self::factory()->post->create_and_get( array( 'post_content' => '<!-- wp:siteorigin-panels/layout-block {"panelsData":{}} /-->' ) );
		$this->assertSame( 'SiteOrigin Page Builder', swiftling_live_builder( $origin ) );
	}

	/**
	 * The text of the fields is in the language they translate into, in its direction.
	 */
	public function test_fields_have_the_language_and_direction() {
		$this->become( 'administrator' );
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'ar'    => 'ar',
			)
		);
		$post = self::factory()->post->create( array( 'post_content' => self::HEADING ) );
		$this->assertTrue( $this->editor_has_formats( $post ) );
		$data = implode( '', (array) wp_scripts()->get_data( 'swiftling-editor-formats', 'before' ) );
		$this->assertStringContainsString( '"lang":"de-DE","dir":"ltr"', $data, 'The first language, without one asked for' );

		$_GET['lang'] = 'ar';
		( new Menu() )->enqueueAssets( Menu::screens()['swiftling-translation'] + array( 'slug' => 'swiftling-translation' ) );
		$data = implode( '', (array) wp_scripts()->get_data( 'swiftling-editor-formats', 'before' ) );
		$this->assertStringContainsString( '"lang":"ar","dir":"rtl"', $data );
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

	/**
	 * The link of a button stays outside the text the field edits, with the tags around it, and is
	 * put back as it is: a translation saved as the field writes it, also the original copied into
	 * it, has the button's link exactly once, its attributes unchanged.
	 */
	public function test_button_link_stays_around_the_translation() {
		$this->become( 'administrator' );
		$link   = '<a class="wp-block-button__link wp-element-button" href="https://wordpress.org/podcast/">';
		$post   = self::factory()->post->create_and_get(
			array( 'post_content' => "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\">" . $link . "Slušajte</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->" )
		);
		$result = $this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
				setup_postdata( $post );
				apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
			}
		);
		$unit   = $this->found( $result, 'Slušajte' );
		$key    = swiftling_place_key( 'post:' . $post->ID, swiftling_path_key( $unit['p'] ), swiftling_hash( $unit['v'] ) );

		// The parts the editor's rows give, and the link the field leaves outside the text it edits,
		// see unwrap() in assets/js/editor.js.
		list( $prefix, $middle, $suffix ) = swiftling_rich_parts( $unit['v'] );
		$this->assertSame( $link . 'Slušajte</a>', trim( $middle ) );
		$open  = substr( $middle, 0, strpos( $middle, '>' ) + 1 );
		$close = '</a>';
		$inner = substr( trim( $middle ), strlen( $open ), -strlen( $close ) );
		$this->assertSame( 'Slušajte', $inner );

		foreach ( array( 'Écoutez', $inner ) as $text ) {
			swiftling_save_texts(
				'post:' . $post->ID,
				'fr',
				array(
					array(
						'key'      => $key,
						'text'     => $prefix . $open . $text . $close . $suffix,
						'original' => $unit['v'],
					),
				),
				'reviewed'
			);
			$this->show_in( 'fr' );
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
			setup_postdata( $post );
			$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
			$this->assertStringContainsString( '<div class="wp-block-button">' . $link . $text . '</a></div>', $html );
			$this->assertSame( 1, substr_count( $html, '<a ' ), $text );
			$this->reset_state();
		}
	}

	/**
	 * A synced pattern is made of blocks too: where a page shows it, its highlighted paragraph is the
	 * text of one block, with the paragraph around it.
	 */
	public function test_synced_pattern_texts_get_the_field() {
		$this->become( 'administrator' );
		$pattern = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Uvod',
				'post_content' => "<!-- wp:paragraph {\"metadata\":{\"name\":\"Intro\",\"bindings\":{\"__default\":{\"source\":\"core/pattern-overrides\"}}}} -->\n<p>Zajednički uvod s <mark style=\"background-color:#FFEE58\" class=\"has-inline-color has-accent-3-color\">isticanjem</mark>.</p>\n<!-- /wp:paragraph -->",
			)
		);
		$this->assertTrue( swiftling_live_block_formats( $pattern ) );

		$page   = self::factory()->post->create_and_get( array( 'post_content' => '<!-- wp:block {"ref":' . $pattern->ID . '} /-->' ) );
		$result = $this->check(
			'post:' . $page->ID,
			static function () use ( $page ) {
				$GLOBALS['post'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
				setup_postdata( $page );
				apply_filters( 'the_content', $page->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
			}
		);

		$unit = $this->found( $result, 'isticanjem' );
		$this->assertSame( 'post:' . $pattern->ID, $unit['o'] );
		list( $prefix, $middle, , $blocks ) = swiftling_rich_parts( $unit['v'] );
		$this->assertSame( '<p>', trim( $prefix ) );
		$this->assertStringStartsWith( 'Zajednički uvod s <mark', $middle );
		$this->assertFalse( $blocks, 'The text of one block, not paragraphs' );
	}
}
