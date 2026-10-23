<?php
/**
 * Tests of the fields of the translation editor.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * How big the field of a translation is.
 */
class EditorFieldsTest extends TestCase {

	/**
	 * Renders the editor's rows of a page whose title the probe found, and returns the field of
	 * the title.
	 *
	 * @param string $title Title of the page.
	 * @return array{rows: int, placeholder: string, fit: bool}
	 */
	private function title_field( $title ) {
		$this->become( 'administrator' );
		$page   = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => $title,
			)
		);
		$result = $this->check(
			'post:' . $page->ID,
			static function () use ( $page ) {
				get_the_title( $page );
			}
		);
		$html   = swiftling_live_rows_html( swiftling_subject( 'post:' . $page->ID ), 'de', $result );
		$this->assertMatchesRegularExpression( '~<textarea[^>]*data-original="' . preg_quote( esc_attr( $title ), '~' ) . '"[^>]*>~', $html );
		preg_match( '~<textarea[^>]*data-original="' . preg_quote( esc_attr( $title ), '~' ) . '"[^>]*>~', $html, $field );
		preg_match( '~rows="(\d+)"~', $field[0], $rows );
		preg_match( '~placeholder="([^"]*)"~', $field[0], $placeholder );
		return array(
			'rows'        => (int) $rows[1],
			'placeholder' => html_entity_decode( $placeholder[1], ENT_QUOTES ),
			'fit'         => false !== strpos( $field[0], ' data-fit' ),
		);
	}

	/**
	 * An empty field of a short text has room for the whole placeholder, which tells what to do:
	 * a title of a few words gave one row, and the placeholder showed half of its first line.
	 */
	public function test_empty_field_fits_its_placeholder() {
		$field = $this->title_field( 'Apartman Lavanda' );
		$this->assertGreaterThan( 70, strlen( $field['placeholder'] ), 'The placeholder is longer than a row' );
		$this->assertGreaterThanOrEqual( (int) ceil( strlen( $field['placeholder'] ) / 60 ), $field['rows'] );
		$this->assertTrue( $field['fit'], 'Fits its placeholder exactly where the browser can' );
	}

	/**
	 * A long text keeps the rows of its own length.
	 */
	public function test_long_text_keeps_its_rows() {
		$field = $this->title_field( trim( str_repeat( 'Apartman Lavanda uz more ', 20 ) ) );
		$this->assertGreaterThanOrEqual( 7, $field['rows'] );
		$this->assertFalse( $field['fit'], 'Keeps the rows of its text while empty' );
	}

	/**
	 * Renders the editor's rows of a post with a featured image and an excerpt, as its page check
	 * finds them.
	 *
	 * @return string
	 */
	private function page_with_image_and_excerpt() {
		$this->become( 'administrator' );
		$image = self::factory()->attachment->create_object(
			array(
				'file'           => '2026/10/naslovna.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$page  = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Apartman Lavanda',
				'post_excerpt' => 'Kratki opis apartmana.',
			)
		);
		set_post_thumbnail( $page, $image );
		$result = $this->check(
			'post:' . $page->ID,
			static function () use ( $page ) {
				get_the_title( $page );
			}
		);
		return swiftling_live_rows_html( swiftling_subject( 'post:' . $page->ID ), 'de', $result );
	}

	/**
	 * The fields stored with a page and its excerpt follow its texts without a heading or a note of
	 * their own, which said little to a person; each row says what it is.
	 */
	public function test_fields_and_excerpt_have_no_heading() {
		$html = $this->page_with_image_and_excerpt();
		foreach ( array( 'More fields of this page', 'Other images and fields', 'In lists of posts', 'Excerpt, shown in lists', 'Stored with this page in custom fields', 'Shown where this page is listed' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $html );
		}
	}

	/**
	 * The excerpt is named under its kind, as WordPress names it.
	 */
	public function test_excerpt_row_is_named() {
		$html = $this->page_with_image_and_excerpt();
		$this->assertMatchesRegularExpression( '~Paragraph\s*<span class="swiftling-muted swiftling-live-place">Excerpt</span>~', $html );
	}

	/**
	 * A section without a title that holds only images hides with them as a whole: no note that it
	 * holds them stands under the page's texts. The page's own section still says so.
	 */
	public function test_section_of_only_images_hides_with_them() {
		$html = $this->page_with_image_and_excerpt();
		$this->assertSame( 1, substr_count( $html, 'is-extras-only' ), 'The featured image alone' );
		$this->assertStringNotContainsString( 'swiftling-live-only-extras', $html );
		$this->assertMatchesRegularExpression( '~<tbody class="swiftling-live-group is-extras-only">\s*<tr class="[^"]*is-type-image~', $html );
	}
}
