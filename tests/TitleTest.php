<?php
/**
 * Tests of the title of a post in the translation editor.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Every post has its title to translate, whatever kind of content it is and whatever its title
 * looks like: one word in lowercase, such as "varijacija", is a title as much as "Test sandala".
 */
class TitleTest extends TestCase {

	/**
	 * Registers a kind of content of a plugin, as WooCommerce registers products.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type(
			'product',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt' ),
			)
		);
		$this->become( 'administrator' );
	}

	/**
	 * Returns the title among the texts the editor shows on the page's own part.
	 *
	 * @param array  $result What check() returned.
	 * @param string $owner  Object key of the post.
	 * @return array|null
	 */
	private function title_of( $result, $owner ) {
		foreach ( $result['sections']['page'] as $unit ) {
			if ( $owner === $unit['o'] && 'field/post_title' === $unit['p'] ) {
				return $unit;
			}
		}
		return null;
	}

	/**
	 * A title of one word in lowercase, or without vowels, is found where the page shows it, for
	 * every kind of content; the editor names it Title, and it shows translated in the language.
	 */
	public function test_title_of_any_shape_is_found() {
		foreach ( array(
			array( 'product', 'varijacija' ),
			array( 'product', 'sdf' ),
			array( 'page', 'ninja' ),
			array( 'post', 'bibče' ),
			array( 'product', 'Test sandala' ),
		) as list( $type, $title ) ) {
			$post   = self::factory()->post->create_and_get(
				array(
					'post_type'  => $type,
					'post_title' => $title,
				)
			);
			$owner  = 'post:' . $post->ID;
			$result = $this->check(
				$owner,
				static function () use ( $post ) {
					get_the_title( $post );
				}
			);
			$unit   = $this->title_of( $result, $owner );
			$this->assertNotNull( $unit, $type . ' "' . $title . '": a text of its page' );
			$this->assertSame( $title, $unit['v'] );
			$this->assertMatchesRegularExpression( '~<th scope="row">\s*Title~', swiftling_live_rows_html( swiftling_subject( $owner ), 'de', $result ), $title );

			$this->translate( $owner, 'field/post_title', $title, 'de', 'DE ' . $title );
			$this->show_in( 'de' );
			$this->assertSame( 'DE ' . $title, get_the_title( $post ), $title . ' in German' );
			$this->reset_state();
		}
	}

	/**
	 * A page that does not show its own title, such as one a builder makes without it, still has
	 * it in its editor, first: the title shows translated wherever WordPress prints it, such as in
	 * the browser's tab and in menus.
	 */
	public function test_title_listed_where_its_page_does_not_show_it() {
		$post   = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'product',
				'post_title'   => 'varijacija',
				'post_content' => 'Opis proizvoda.',
			)
		);
		$owner  = 'post:' . $post->ID;
		$result = $this->check(
			$owner,
			static function () use ( $post ) {
				apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
			}
		);
		$unit   = $this->title_of( $result, $owner );
		$this->assertNotNull( $unit );
		$this->assertSame( $unit, $result['sections']['page'][0], 'First, as on a page that shows it' );
		$this->assertCount( 1, wp_list_filter( $result['units'], array( 'p' => 'field/post_title' ) ), 'Once' );

		$this->translate( $owner, 'field/post_title', 'varijacija', 'de', 'Variation' );
		$this->show_in( 'de' );
		$this->assertSame( 'Variation', get_the_title( $post ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as wp_title() runs it.
		$this->assertSame( 'Variation', apply_filters( 'single_post_title', $post->post_title, $post ), 'In the browser\'s tab' );
	}

	/**
	 * A post without a title has no Title row: there is nothing to translate.
	 */
	public function test_no_title_no_row() {
		$post   = self::factory()->post->create_and_get( array( 'post_title' => '' ) );
		$result = $this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->assertNull( $this->title_of( $result, 'post:' . $post->ID ) );
	}

	/**
	 * The excerpt is text by its nature too, also one word in lowercase; a setting kept in a field
	 * of the post still is no text.
	 */
	public function test_excerpt_of_one_word_and_settings() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'product',
				'post_title'   => 'Sandale',
				'post_excerpt' => 'udobne',
			)
		);
		update_post_meta( $post->ID, 'layout', 'boxed' );
		$leaves = $this->probe(
			static function () use ( $post ) {
				get_the_excerpt( $post );
				get_post_meta( $post->ID, 'layout', true );
			}
		);
		$paths  = wp_list_pluck( $leaves, 'p' );
		$this->assertContains( 'field/post_excerpt', $paths );
		$this->assertNotContains( 'meta/layout', $paths, 'A setting stays a setting' );
	}
}
