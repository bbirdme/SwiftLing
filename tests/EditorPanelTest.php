<?php
/**
 * Tests of the Translations box in the classic editor.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\EditorPanel;

/**
 * Where a post is edited in the classic editor, as WooCommerce edits its products, a Translations
 * box in the side offers what the block editor's panel offers.
 */
class EditorPanelTest extends TestCase {

	/**
	 * A product, edited in the classic editor.
	 *
	 * @var \WP_Post
	 */
	private $product;

	/**
	 * Registers products as WooCommerce does: public, without the block editor.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type(
			'swl_product',
			array(
				'public'       => true,
				'show_in_rest' => true,
			)
		);
		add_filter( 'use_block_editor_for_post_type', array( $this, 'classic_for_products' ), 10, 2 );
		$this->product = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'swl_product',
				'post_title' => 'Sandala',
			)
		);
	}

	/**
	 * Takes the products away again.
	 */
	public function tear_down() {
		remove_filter( 'use_block_editor_for_post_type', array( $this, 'classic_for_products' ), 10 );
		unregister_post_type( 'swl_product' );
		$GLOBALS['wp_meta_boxes'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Boxes of the edit screen the test opened.
		parent::tear_down();
	}

	/**
	 * Products are edited in the classic editor, as WooCommerce has it.
	 *
	 * @param bool   $block Whether the block editor is used.
	 * @param string $type  Post type.
	 * @return bool
	 */
	public function classic_for_products( $block, $type ) {
		return 'swl_product' === $type ? false : $block;
	}

	/**
	 * Opens the edit screen of a post as WordPress does, and returns the box SwiftLing added.
	 *
	 * @param \WP_Post $post Post.
	 * @return array|null The box, or null when there is none.
	 */
	private function box( $post ) {
		set_current_screen( $post->post_type );
		$GLOBALS['wp_meta_boxes'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh edit screen.
		( new EditorPanel() )->register();
		do_action( 'add_meta_boxes', $post->post_type, $post );
		set_current_screen( 'front' );
		$boxes = $GLOBALS['wp_meta_boxes'];
		return isset( $boxes[ $post->post_type ]['side']['high']['swiftling-translations'] ) ? $boxes[ $post->post_type ]['side']['high']['swiftling-translations'] : null;
	}

	/**
	 * Prints a box.
	 *
	 * @param array    $box  Box.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function render( $box, $post ) {
		ob_start();
		call_user_func( $box['callback'], $post, $box );
		return (string) ob_get_clean();
	}

	/**
	 * A product edited in the classic editor gets the box, with every language and its state, each a
	 * link to the translation editor.
	 */
	public function test_classic_editor_has_the_box() {
		$this->become( 'administrator' );
		$box = $this->box( $this->product );
		$this->assertNotNull( $box, 'The classic editor offers the translation' );

		$html = $this->render( $box, $this->product );
		$this->assertStringContainsString( 'Deutsch', $html );
		$this->assertStringContainsString( 'Français', $html );
		$this->assertStringContainsString( 'Not translated', $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( $this->product->ID, 'de' ) ), $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( $this->product->ID, 'fr' ) ), $html );
	}

	/**
	 * A post in the block editor has the panel there, not the box; nor does someone who may not
	 * translate get it.
	 */
	public function test_no_box_elsewhere() {
		$this->become( 'administrator' );
		$this->assertNull( $this->box( self::factory()->post->create_and_get() ), 'The block editor has its panel' );

		$this->become( 'editor' );
		$this->assertNull( $this->box( $this->product ) );
	}

	/**
	 * A language that keeps the original says so, by that name, not as a duplicate, which reads as a
	 * copy of the post.
	 */
	public function test_duplicate() {
		$this->become( 'administrator' );
		swiftling_set_language_mode( $this->product, 'de', 'duplicate' );
		$html = $this->render( $this->box( $this->product ), $this->product );
		$this->assertStringContainsString( 'Keeps the original', $html );
		$this->assertStringNotContainsString( 'Duplicate', $html );
	}

	/**
	 * A separate copy, edited in the classic editor, says whose copy it is and leads to the
	 * translations of its original.
	 */
	public function test_copy() {
		$this->become( 'administrator' );
		$page    = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kontakt',
			)
		);
		$copy_id = swiftling_make_copy( $page, 'de' );
		$this->assertIsInt( $copy_id );
		add_filter( 'use_block_editor_for_post', '__return_false' );
		$copy = get_post( $copy_id );
		$html = $this->render( $this->box( $copy ), $copy );
		remove_filter( 'use_block_editor_for_post', '__return_false' );
		$this->assertStringContainsString( 'Kontakt', $html );
		$this->assertStringContainsString( 'Deutsch', $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( $page->ID, 'de' ) ), $html );
	}

	/**
	 * A post not saved yet has nothing to translate: the box says to save it first.
	 */
	public function test_new_post() {
		$this->become( 'administrator' );
		$draft = get_default_post_to_edit( 'swl_product', true );
		$html  = $this->render( $this->box( $draft ), $draft );
		$this->assertStringContainsString( 'Save', $html );
		$this->assertStringNotContainsString( 'swiftling-translation', $html );
	}
}
