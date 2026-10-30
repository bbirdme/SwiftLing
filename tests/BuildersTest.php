<?php
/**
 * Tests of what builder classes declare.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Acf;
use SwiftLing\Builders\Aioseo;
use SwiftLing\Builders\Breakdance;
use SwiftLing\Builders\Brizy;
use SwiftLing\Builders\Elementor;
use SwiftLing\Builders\Registry;
use SwiftLing\Builders\SeoPress;

/**
 * The builders SwiftLing knows, and what they declare without the builder installed.
 */
class BuildersTest extends TestCase {

	/**
	 * Every known builder is listed; without the builders installed none is active and none declares anything.
	 */
	public function test_registry_without_builders() {
		$this->assertSame( array( 'elementor', 'breakdance', 'brizy', 'bricks', 'acf', 'cf7', 'forminator', 'gravityforms', 'ninjaforms', 'fluentforms', 'wpforms', 'formidable', 'woocommerce', 'tutor', 'seopress', 'aioseo' ), array_keys( Registry::all() ) );
		$this->assertSame( array(), Registry::active() );
		$this->assertNull( Registry::fieldHoldsText( 'post:1', 'meta/title' ) );
		$this->assertFalse( Registry::declaresText( 'meta:x||title' ) );
		$this->assertFalse( Registry::holdsNoText( 'meta/x' ) );
		$this->assertSame( array(), Registry::regeneratedMeta() );
		$this->assertNull( Registry::subject( 'acf-options:theme' ) );
		$this->assertNull( swiftling_subject( 'acf-options:theme' ) );

		$post = self::factory()->post->create();
		$this->become( 'administrator' );
		$this->assertSame( get_edit_post_link( $post, 'raw' ), Registry::editUrl( $post ) );
	}

	/**
	 * Elementor's styles are written into files while a page renders, so their values are never marked.
	 */
	public function test_elementor_styles_hold_no_text() {
		$elementor = new Elementor();
		$this->assertTrue( $elementor->holdsNoText( 'meta/_elementor_global_class_data/@json/x' ) );
		$this->assertTrue( $elementor->holdsNoText( 'meta/_elementor_data/@json/#a1/styles/e-1/variants/0/props/font' ) );
		$this->assertFalse( $elementor->holdsNoText( 'meta/_elementor_data/@json/#a1/settings/title' ) );
		$this->assertFalse( $elementor->holdsNoText( 'meta/other/#a1/styles/x' ) );
	}

	/**
	 * A default text of an Elementor widget is text; the fields of widgets need Elementor to tell.
	 */
	public function test_elementor_declarations() {
		$elementor = new Elementor();
		$this->assertFalse( $elementor->isActive() );
		$this->assertTrue( $elementor->declaresText( 'elementor-default:heading||' ) );
		$this->assertFalse( $elementor->declaresText( 'meta:_elementor_data|heading|settings/title' ) );
		$this->assertFalse( $elementor->isCode( 'post:1', 'meta/_elementor_data', 'meta:_elementor_data|html|settings/html' ) );
		$this->assertContains( 'wysiwyg', $elementor->textTypes() );
		$this->assertContains( 'code:html', $elementor->textTypes() );
		$this->assertNotContains( 'code:css', $elementor->textTypes() );
		$this->assertContains( '_elementor_css', $elementor->regeneratedMeta() );
		$this->assertSame( 'disable', $elementor->disableElementCache() );
	}

	/**
	 * Breakdance is active only with its plugin in Breakdance's mode. It does not say a post is built
	 * with it, since a separate copy in Breakdance was not checked; only its header and footer posts
	 * are parts of every page.
	 */
	public function test_breakdance_without_the_plugin() {
		$breakdance = new Breakdance();
		$this->assertSame( 'breakdance', $breakdance->id() );
		$this->assertFalse( $breakdance->isActive() );
		$post = self::factory()->post->create();
		update_post_meta( $post, '_breakdance_data', '{"tree_json_string":"{}"}' );
		$this->assertFalse( $breakdance->isUsed( $post ) );
		$this->assertSame( '', $breakdance->sitePart( 'post:' . $post ) );
		$this->assertSame( '', $breakdance->sitePart( 'options' ) );
	}

	/**
	 * Brizy is active only with its plugin. Its editor's tree is no text a page shows, and only a
	 * data-brz-translatable attribute is declared as text.
	 */
	public function test_brizy_without_the_plugin() {
		$brizy = new Brizy();
		$this->assertSame( 'brizy', $brizy->id() );
		$this->assertFalse( $brizy->isActive() );
		$this->assertFalse( $brizy->isUsed( 1 ) );
		$this->assertTrue( $brizy->holdsNoText( 'meta/brizy/brizy-post/editor_data/@b64/items' ) );
		$this->assertFalse( $brizy->holdsNoText( 'meta/brizy-compiled-sections/@b64/blocks/*/html' ) );
		$this->assertTrue( $brizy->declaresText( 'meta:brizy-compiled-sections||blocks/*/html/@brizy-attribute' ) );
		$this->assertFalse( $brizy->declaresText( 'meta:brizy-compiled-sections||blocks/*/html/@brizy' ) );
	}

	/**
	 * Advanced Custom Fields is active only with its plugin, and never says a page is built with it.
	 */
	public function test_acf_without_the_plugin() {
		$acf = new Acf();
		$this->assertSame( 'acf', $acf->id() );
		$this->assertFalse( $acf->isActive() );
		$this->assertFalse( $acf->isUsed( 1 ) );
	}

	/**
	 * SEOPress's fields of a post or a term: its titles and descriptions, for search engines, when
	 * shared and in a breadcrumb, are text, named as SEOPress names them; its settings for robots,
	 * the keywords it analyses the page for and its redirect are not. Any other value is left to the
	 * page check.
	 */
	public function test_seopress_declarations() {
		$seopress = new SeoPress();
		$this->assertSame( array( 'seopress', 'SEOPress' ), array( $seopress->id(), $seopress->name() ) );
		$this->assertFalse( $seopress->isActive() );
		$this->assertFalse( $seopress->isUsed( 1 ) );

		$labels = array(
			'_seopress_titles_title'         => 'SEO title',
			'_seopress_titles_desc'          => 'Meta description',
			'_seopress_social_fb_title'      => 'Facebook title',
			'_seopress_social_fb_desc'       => 'Facebook description',
			'_seopress_social_twitter_title' => 'X title',
			'_seopress_social_twitter_desc'  => 'X description',
			'_seopress_robots_breadcrumbs'   => 'Breadcrumb title',
		);
		foreach ( $labels as $key => $label ) {
			foreach ( array( 'post:1', 'term:3' ) as $owner ) {
				$this->assertTrue( $seopress->fieldHoldsText( $owner, swiftling_meta_path( $key, 0 ) ), $key );
				$this->assertSame( $label, $seopress->fieldLabel( $owner, swiftling_meta_path( $key, 0 ) ) );
			}
		}
		foreach ( array( '_seopress_robots_index', '_seopress_robots_follow', '_seopress_robots_imageindex', '_seopress_robots_snippet', '_seopress_robots_primary_cat', '_seopress_analysis_target_kw', '_seopress_redirections_value', '_seopress_redirections_type' ) as $key ) {
			$this->assertFalse( $seopress->fieldHoldsText( 'post:1', swiftling_meta_path( $key, 0 ) ), $key );
		}
		// The page check decides: an address typed into a field, another plugin's field, a value
		// kept elsewhere under the same name.
		$this->assertNull( $seopress->fieldHoldsText( 'post:1', swiftling_meta_path( '_seopress_robots_canonical', 0 ) ) );
		$this->assertNull( $seopress->fieldHoldsText( 'post:1', swiftling_meta_path( 'hero_title', 0 ) ) );
		$this->assertNull( $seopress->fieldHoldsText( 'options', 'option/_seopress_titles_title' ) );
		$this->assertSame( '', $seopress->fieldLabel( 'post:1', swiftling_meta_path( 'hero_title', 0 ) ) );
	}

	/**
	 * All in One SEO's texts of a post are named as it names them; it declares nothing, as a text of
	 * its own may be its tags alone, such as "#post_title #separator_sa #site_title".
	 */
	public function test_aioseo_declarations() {
		$aioseo = new Aioseo();
		$this->assertSame( array( 'aioseo', 'All in One SEO' ), array( $aioseo->id(), $aioseo->name() ) );
		$this->assertFalse( $aioseo->isActive() );
		$this->assertFalse( $aioseo->isUsed( 1 ) );

		$labels = array(
			'_aioseo_title'               => 'SEO title',
			'_aioseo_description'         => 'Meta description',
			'_aioseo_og_title'            => 'Facebook title',
			'_aioseo_og_description'      => 'Facebook description',
			'_aioseo_twitter_title'       => 'X title',
			'_aioseo_twitter_description' => 'X description',
		);
		foreach ( $labels as $key => $label ) {
			$this->assertSame( $label, $aioseo->fieldLabel( 'post:1', swiftling_meta_path( $key, 0 ) ) );
			$this->assertNull( $aioseo->fieldHoldsText( 'post:1', swiftling_meta_path( $key, 0 ) ) );
		}
		$this->assertSame( '', $aioseo->fieldLabel( 'post:1', swiftling_meta_path( '_aioseo_keywords', 0 ) ) );
		$this->assertSame( '', $aioseo->fieldLabel( 'options', 'option/_aioseo_title' ) );
	}
}
