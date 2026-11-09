<?php
/**
 * Modula: the texts of its galleries in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Modula. A gallery is a post of the type "modula-gallery" with its images of the media library in its
 * meta "modula-images", each with a copy of the image's title, caption and alt text. The plugin shows the
 * image's own texts instead, read straight from the database, and hands the images to the filter
 * modula_gallery_images, where they are translated, see translateImages(), under each image, see
 * AttachmentTexts. Its copies are no texts a visitor reads, see fieldHoldsText(); only its newer gallery
 * shows the copy of a caption, where the image has none, and that one is a text of the gallery's own,
 * see GalleryPlugin::ownText().
 *
 * Checked against the code of Modula 3.0 (the free plugin), its classic and its newer gallery, in
 * tests/GalleriesTest.php, and by hand on a site with the plugin.
 */
class Modula extends GalleryPlugin {
	/**
	 * The post type of the galleries and the meta of their images.
	 */
	const POST_TYPE = 'modula-gallery';
	const IMAGES    = 'modula-images';

	/**
	 * The texts of an image the plugin shows, as it names them, and the image's text each is.
	 */
	const TEXTS = array(
		'title'       => 'title',
		'description' => 'caption',
		'caption'     => 'caption',
		'alt'         => 'alt',
	);

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'modula';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Modula';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'MODULA_LITE_VERSION' ) || class_exists( 'Modula_Shortcode', false );
	}

	/**
	 * Hooks where the plugin hands out the images of a gallery.
	 */
	public function register(): void {
		add_filter( 'modula_gallery_images', array( $this, 'translateImages' ), 10, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * Returns the images of a gallery with their texts in the mode of the moment.
	 *
	 * @param mixed $images   The images, each with its ID and texts.
	 * @param mixed $settings The gallery's settings, with its ID.
	 * @return mixed
	 */
	public function translateImages( $images, $settings = array() ) {
		if ( ! is_array( $images ) ) {
			return $images;
		}
		$gallery = is_array( $settings ) && ! empty( $settings['gallery_id'] ) ? (string) absint( $settings['gallery_id'] ) : '';
		foreach ( $images as $index => $image ) {
			if ( ! is_array( $image ) || empty( $image['id'] ) ) {
				continue;
			}
			foreach ( self::TEXTS as $name => $field ) {
				if ( ! isset( $image[ $name ] ) || ! is_string( $image[ $name ] ) ) {
					continue;
				}
				$own                       = 'caption' === $field && '' !== $gallery && '' === trim( AttachmentTexts::stored( (int) $image['id'], 'caption' ) );
				$images[ $index ][ $name ] = $own ? $this->ownText( $gallery, $image[ $name ] ) : AttachmentTexts::text( (int) $image['id'], $field, $image[ $name ] );
			}
		}
		return $images;
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
				'post_type'        => self::POST_TYPE,
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
		$rows = get_post_meta( (int) $id, self::IMAGES, true );
		$ids  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$ids[] = (int) $row['id'];
			}
		}
		return $ids;
	}

	/**
	 * Reads the texts of the images of a gallery, and the gallery's own captions.
	 *
	 * @param string $id The gallery's ID.
	 */
	protected function readObject( string $id ): void {
		AttachmentTexts::read( $this->images( $id ), array( 'title', 'caption', 'alt' ) );
		// A caption of the gallery's own, which the newer gallery shows where the image has none.
		$rows = get_post_meta( (int) $id, self::IMAGES, true );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && isset( $row['description'] ) && '' === trim( AttachmentTexts::stored( (int) $row['id'], 'caption' ) ) ) {
				$this->ownText( $id, $row['description'] );
			}
		}
	}

	/**
	 * Declares the copies of the images' texts in a gallery's meta as no text: the plugin shows the
	 * images' own.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		$prefix = swiftling_meta_path( self::IMAGES, 0 );
		if ( 0 === strpos( $owner, 'post:' ) && ( $prefix === $path || 0 === strpos( $path, $prefix . '/' ) ) && self::POST_TYPE === get_post_type( (int) substr( $owner, 5 ) ) ) {
			return false;
		}
		return parent::fieldHoldsText( $owner, $path );
	}
}
