<?php
/**
 * FooGallery: the texts of its galleries in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * FooGallery. A gallery is a post of the type "foogallery" with the images of the media library in its
 * meta "foogallery_attachments". The plugin reads each image into an object of its own, from the image's
 * post itself: its title, caption and description are translated there, see translateImage(), under the
 * image, see AttachmentTexts. The captions it prints under an image it reads once more from the image's
 * post, by the source the gallery chose, and hands to a filter of its own: translated there, see
 * translateCaption(). Its alt text it reads with get_post_meta(), which SwiftLing translates. Its copy of
 * a gallery's HTML, an option off by default, a page in a language does without, see start().
 *
 * Checked against the code of FooGallery 3.3 (the free plugin), in tests/GalleriesTest.php, and by hand on
 * a site with the plugin.
 */
class FooGallery extends GalleryPlugin {
	/**
	 * The texts of an image a gallery shows.
	 */
	const TEXTS = array( 'title', 'caption', 'description', 'alt' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'foogallery';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'FooGallery';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'FOOGALLERY_VERSION' );
	}

	/**
	 * Hooks where the plugin has read an image, and where it hands out the captions of one.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hooks.
		add_action( 'foogallery_attachment_instance_after_load', array( $this, 'translateImage' ), 10, 1 );
		add_filter( 'foogallery_get_caption_title_for_attachment', array( $this, 'translateCaption' ), 10, 2 );
		add_filter( 'foogallery_get_caption_desc_for_attachment', array( $this, 'translateCaption' ), 10, 2 );
		// phpcs:enable
	}

	/**
	 * In a page check and a page in a language, prints galleries without the plugin's copy of them: the
	 * plugin keeps one gallery's HTML, if the site's owner turned its cache on, whatever the language.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		unset( $probe );
		if ( 'control' !== $mode ) {
			add_filter( 'foogallery_html_cache_disabled', '__return_true' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		}
	}

	/**
	 * Puts the texts of an image the plugin has read in the mode of the moment.
	 *
	 * @param mixed $image The plugin's object of the image.
	 */
	public function translateImage( $image ): void {
		if ( ! is_object( $image ) || empty( $image->ID ) ) {
			return;
		}
		foreach ( self::TEXTS as $text ) {
			if ( isset( $image->$text ) && is_string( $image->$text ) ) {
				$image->$text = self::translated( (int) $image->ID, $text, $image->$text );
			}
		}
	}

	/**
	 * Returns a caption the plugin prints under an image in the mode of the moment. The filter does not
	 * say which text of the image the caption is, so it is the first whose words it has; the file name,
	 * or the alt text SwiftLing already translated, stays as it is.
	 *
	 * @param mixed $caption The caption.
	 * @param mixed $post    The image's post.
	 * @return mixed
	 */
	public function translateCaption( $caption, $post ) {
		if ( ! is_string( $caption ) || '' === $caption || ! $post instanceof \WP_Post ) {
			return $caption;
		}
		foreach ( self::TEXTS as $text ) {
			$translated = self::translated( (int) $post->ID, $text, $caption );
			if ( $translated !== $caption || trim( AttachmentTexts::stored( (int) $post->ID, $text ) ) === $caption ) {
				return $translated;
			}
		}
		return $caption;
	}

	/**
	 * Returns a text of an image the plugin read in the mode of the moment, when it is what the image
	 * holds: the plugin trims what it reads.
	 *
	 * @param int    $id    The image's ID.
	 * @param string $text  Which text, see AttachmentTexts::FIELDS.
	 * @param string $value The text, as the plugin read it.
	 */
	private static function translated( int $id, string $text, string $value ): string {
		$stored = AttachmentTexts::stored( $id, $text );
		return trim( $stored ) === $value ? trim( (string) AttachmentTexts::text( $id, $text, $stored ) ) : $value;
	}

	/**
	 * Returns the plugin's galleries: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function objects(): array {
		$galleries = array();
		foreach ( get_posts(
			array(
				'post_type'        => 'foogallery',
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => self::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		) as $post ) {
			$galleries[ (string) $post->ID ] = $post->post_title;
		}
		return $galleries;
	}

	/**
	 * Returns the images of a gallery.
	 *
	 * @param string $id The gallery's ID.
	 * @return int[]
	 */
	protected function images( string $id ): array {
		$ids = get_post_meta( (int) $id, 'foogallery_attachments', true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	/**
	 * Reads the texts of the images of a gallery.
	 *
	 * @param string $id The gallery's ID.
	 */
	protected function readObject( string $id ): void {
		AttachmentTexts::read( $this->images( $id ), self::TEXTS );
	}
}
