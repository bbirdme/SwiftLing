<?php
/**
 * Tests of the header and footer as rows of their own on the Templates screen.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Breakdance;
use SwiftLing\Builders\Elementor;
use SwiftLing\Builders\Registry;

/**
 * The header and the footer every page shows: which texts belong to them, and where they are translated.
 */
class SitePartsTest extends TestCase {

	/**
	 * Clears the active builders a test set.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * A text as the probe finds it.
	 *
	 * @param string $owner   Object key.
	 * @param string $region  Landmark it sits in.
	 * @param bool   $content Whether it is in the content of a post.
	 * @return array
	 */
	private static function unit( $owner, $region = '', $content = false ) {
		return array(
			'o'       => $owner,
			'p'       => 'meta/x',
			'region'  => $region,
			'content' => $content,
		);
	}

	/**
	 * A header and a footer a builder keeps in posts of its own are what every page shows, also when
	 * their posts can be opened on their own, as those of Header Footer Elementor: their texts are
	 * listed with the header and footer of a template, not as the template's own text, and not left out.
	 */
	public function test_header_and_footer_of_a_builder_are_site_wide() {
		register_post_type( 'breakdance_header', array( 'public' => false ) );
		register_post_type( 'breakdance_footer', array( 'public' => false ) );
		register_post_type( 'elementor-hf', array( 'public' => true ) );
		self::set_static(
			Registry::class,
			'active',
			array(
				'elementor'  => new Elementor(),
				'breakdance' => new Breakdance(),
			)
		);
		$bd_header  = self::factory()->post->create( array( 'post_type' => 'breakdance_header' ) );
		$bd_footer  = self::factory()->post->create( array( 'post_type' => 'breakdance_footer' ) );
		$hfe_header = self::factory()->post->create( array( 'post_type' => 'elementor-hf' ) );
		$hfe_footer = self::factory()->post->create( array( 'post_type' => 'elementor-hf' ) );
		$hfe_block  = self::factory()->post->create( array( 'post_type' => 'elementor-hf' ) );
		update_post_meta( $hfe_header, 'ehf_template_type', 'type_header' );
		update_post_meta( $hfe_footer, 'ehf_template_type', 'type_footer' );
		update_post_meta( $hfe_block, 'ehf_template_type', 'custom' );

		try {
			$this->assertSame( 'site', swiftling_template_unit_section( self::unit( 'post:' . $bd_header ) ) );
			$this->assertSame( 'site', swiftling_template_unit_section( self::unit( 'post:' . $bd_footer ) ) );
			$this->assertSame( 'site', swiftling_template_unit_section( self::unit( 'post:' . $hfe_header ) ) );
			$this->assertSame( 'site', swiftling_template_unit_section( self::unit( 'post:' . $hfe_footer ) ) );
			$this->assertSame( '', swiftling_template_unit_section( self::unit( 'post:' . $hfe_block ) ), 'A block for a shortcode is a post of its own' );

			$this->assertSame( 'header', swiftling_unit_site_part( self::unit( 'post:' . $bd_header ) ) );
			$this->assertSame( 'footer', swiftling_unit_site_part( self::unit( 'post:' . $bd_footer, 'main' ) ), 'The builder says it, whatever tag it prints' );
			$this->assertSame( 'header', swiftling_unit_site_part( self::unit( 'post:' . $hfe_header ) ) );
			$this->assertSame( 'footer', swiftling_unit_site_part( self::unit( 'post:' . $hfe_footer ) ) );
		} finally {
			unregister_post_type( 'breakdance_header' );
			unregister_post_type( 'breakdance_footer' );
			unregister_post_type( 'elementor-hf' );
		}
	}

	/**
	 * Without a builder that names it, a text's part comes from the template part it is in, or from
	 * the landmark around it; a menu and texts outside the header and footer belong to neither.
	 */
	public function test_part_of_a_text() {
		$this->assertSame( 'header', swiftling_unit_site_part( self::unit( 'gettext:acme', 'header' ) ) );
		$this->assertSame( 'footer', swiftling_unit_site_part( self::unit( 'options', 'footer' ) ) );
		$this->assertSame( '', swiftling_unit_site_part( self::unit( 'gettext:acme', 'navigation' ) ) );
		$this->assertSame( '', swiftling_unit_site_part( self::unit( 'gettext:acme', 'main' ) ) );
		$this->assertSame( '', swiftling_unit_site_part( self::unit( 'options' ) ) );

		$part = self::factory()->post->create(
			array(
				'post_type'   => 'wp_template_part',
				'post_name'   => 'site-footer',
				'post_status' => 'publish',
				'tax_input'   => array(
					'wp_theme'              => array( get_stylesheet() ),
					'wp_template_part_area' => array( 'footer' ),
				),
			)
		);
		wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
		wp_set_object_terms( $part, 'footer', 'wp_template_part_area' );
		$this->assertSame( 'footer', swiftling_unit_site_part( self::unit( 'part:' . get_stylesheet() . '//site-footer', 'header' ) ), 'The area of the template part, not the tag around it' );
		$this->assertSame( 'header', swiftling_unit_site_part( self::unit( 'part:' . get_stylesheet() . '//missing', 'header' ) ), 'An unknown part falls back to its landmark' );
	}

	/**
	 * Of what a page shows, the header lists only the site-wide texts of the header: not the page's
	 * own texts, not the footer, not texts of posts.
	 */
	public function test_texts_of_a_part() {
		$post  = self::factory()->post->create();
		$units = array(
			self::unit( 'part:theme//header', 'header' ),
			self::unit( 'gettext:acme', 'header' ),
			self::unit( 'gettext:acme', 'footer' ),
			self::unit( 'template:theme//page', 'header' ),
			self::unit( 'post:' . $post, 'header' ),
			self::unit( 'options', 'header', true ),
		);
		$in    = static function ( $part ) use ( $units ) {
			return array_keys(
				array_filter(
					$units,
					static function ( $unit ) use ( $part ) {
						return swiftling_is_site_part_unit( $unit, $part );
					}
				)
			);
		};
		$this->assertSame( array( 0, 1 ), $in( 'header' ) );
		$this->assertSame( array( 2 ), $in( 'footer' ) );
	}

	/**
	 * The header and the footer are checked on a page with the site's usual frame: the newest page
	 * without a page template of its own, else the newest post, else the front page. A front page is
	 * often a landing page without header or footer, such as one on Elementor's canvas.
	 */
	public function test_example_page() {
		$this->assertSame( home_url( '/' ), swiftling_site_part_example()['url'], 'Nothing else to check on' );

		$post = self::factory()->post->create( array( 'post_title' => 'Vijest' ) );
		swiftling_forget_memo();
		$this->assertSame( get_permalink( $post ), swiftling_site_part_example()['url'] );

		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$landing = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $landing, '_wp_page_template', 'elementor_canvas' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $landing );
		$this->assertSame( get_permalink( $page ), swiftling_site_part_example()['url'] );
		$this->assertSame( get_permalink( $page ), swiftling_subject( 'site:footer' )['url'] );
	}

	/**
	 * The header and the footer are subjects of the editor; nothing else of their kind is.
	 */
	public function test_subjects() {
		$this->become( 'administrator' );
		$header = swiftling_subject( 'site:header' );
		$this->assertSame( 'site', $header['kind'] );
		$this->assertSame( 'Header', $header['title'] );
		$this->assertSame( home_url( '/' ), $header['url'], 'On a site without pages and posts, the front page' );
		$this->assertTrue( $header['probe'] );
		$this->assertTrue( swiftling_can_translate_subject( $header ) );
		$this->assertSame( 'Footer', swiftling_subject( 'site:footer' )['title'] );
		$this->assertNull( swiftling_subject( 'site:sidebar' ) );

		$this->become( 'editor' );
		$this->assertFalse( swiftling_can_translate_subject( $header ), 'As the templates: the right to edit the design' );
	}

	/**
	 * The Templates screen lists the header and the footer above the kinds of pages, each opening its editor.
	 */
	public function test_templates_screen_lists_them() {
		$this->become( 'administrator' );
		$html = $this->render_screen( 'templates' );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'site:header' ) ), $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'site:footer' ) ), $html );
		$this->assertLessThan( strpos( $html, 'Kinds of pages' ), strpos( $html, esc_url( swiftling_editor_url( 'site:header' ) ) ) );
	}
}
