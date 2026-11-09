<?php
/**
 * MetaSlider: the texts of its slides in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * MetaSlider. A slider is a post of the type "ml-slider"; each slide a post of the type "ml-slide" with
 * its image of the media library. The caption of a slide is the image's caption, its description, or a
 * caption of the slide's own (the slide's excerpt); its title the image's or one of its own (its meta
 * "ml-slider_title"). The plugin reads the image's texts and the slide's own caption from their post
 * objects, and hands the slide out to the filter metaslider_image_slide_attributes, where they are
 * translated, see translateSlide(): the image's texts under the image, see AttachmentTexts, the slide's
 * own caption under the slide's post. Titles and alt texts of a slide's own it reads with get_post_meta(),
 * which SwiftLing translates.
 *
 * Checked against the code of MetaSlider 3.114 (the free plugin), in tests/GalleriesTest.php, and by hand
 * on a site with the plugin.
 */
class MetaSlider extends GalleryPlugin {
	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'metaslider';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'MetaSlider';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return class_exists( 'MetaSliderPlugin', false );
	}

	/**
	 * Returns what the plugin calls one of its objects.
	 */
	protected function objectNoun(): string {
		return __( 'Slider', 'swiftling' );
	}

	/**
	 * Returns the kind of the plugin's objects in their object keys.
	 */
	protected function objectKind(): string {
		return 'slider';
	}

	/**
	 * Hooks where the plugin hands out a slide.
	 */
	public function register(): void {
		add_filter( 'metaslider_image_slide_attributes', array( $this, 'translateSlide' ), 10, 3 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * Returns a slide with its caption and title in the mode of the moment.
	 *
	 * @param mixed $slide     The slide, as the plugin prints it: its ID, caption and title.
	 * @param mixed $slider_id The slider.
	 * @param mixed $settings  The slider's settings.
	 * @return mixed
	 */
	public function translateSlide( $slide, $slider_id = 0, $settings = array() ) {
		if ( ! is_array( $slide ) || empty( $slide['id'] ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $slide;
		}
		$id                        = (int) $slide['id'];
		$image                     = (int) get_post_thumbnail_id( $id );
		list( $source, $original ) = self::caption( $id, $image );
		$caption                   = $this->text( $id, $image, $source, $original );
		if ( $caption !== $original && is_string( $caption ) ) {
			// As the plugin prints a caption.
			$slide['caption_raw'] = do_shortcode( $caption );
			$slide['caption']     = html_entity_decode( do_shortcode( $caption ), ENT_NOQUOTES, 'UTF-8' );
			if ( function_exists( 'metaslider_filter_unsafe_html' ) && apply_filters( 'metaslider_filter_unsafe_html', true, $slide, $slider_id, $settings ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
				$slide['caption'] = metaslider_filter_unsafe_html( $slide['caption'], $slide, $slider_id, $settings );
			}
		}
		if ( $image && self::inherits( $id, 'title' ) && isset( $slide['title'] ) && is_string( $slide['title'] ) ) {
			$slide['title'] = AttachmentTexts::text( $image, 'title', $slide['title'] );
		}
		return $slide;
	}

	/**
	 * Returns where the caption of a slide comes from, as the plugin decides it, and the caption as stored.
	 *
	 * @param int $id    The slide.
	 * @param int $image Its image.
	 * @return array{0: string, 1: string} "caption" or "description" of the image, or "slide" for its own.
	 */
	private static function caption( int $id, int $image ): array {
		if ( self::inherits( $id, 'caption' ) ) {
			$source = 'caption';
		} else {
			$chosen = get_post_meta( $id, 'ml-slider_caption_source', true );
			$source = 'image-caption' === $chosen ? 'caption' : ( 'image-description' === $chosen ? 'description' : 'slide' );
		}
		$stored = 'slide' === $source ? (string) get_post_field( 'post_excerpt', $id, 'raw' ) : ( $image ? AttachmentTexts::stored( $image, $source ) : '' );
		return array( $source, $stored );
	}

	/**
	 * Returns a caption in the mode of the moment: the image's under the image, the slide's own under the
	 * slide's post.
	 *
	 * @param int    $id      The slide.
	 * @param int    $image   Its image.
	 * @param string $source  Where the caption comes from, see caption().
	 * @param string $caption The caption, as stored.
	 * @return mixed
	 */
	private function text( int $id, int $image, string $source, string $caption ) {
		if ( 'slide' !== $source ) {
			return AttachmentTexts::text( $image, $source, $caption );
		}
		if ( '' === trim( $caption ) ) {
			return $caption;
		}
		return swiftling_transform( swiftling_post_owner( $id ), 'field/post_excerpt', 'field:post_excerpt', $caption );
	}

	/**
	 * Whether a slide takes a text of its image.
	 *
	 * @param int    $id    The slide.
	 * @param string $field "caption", "title" or "alt".
	 */
	private static function inherits( int $id, string $field ): bool {
		return (bool) get_post_meta( $id, 'ml-slider_inherit_image_' . $field, true );
	}

	/**
	 * Returns the plugin's sliders: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function objects(): array {
		$sliders = array();
		foreach ( get_posts(
			array(
				'post_type'        => 'ml-slider',
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'posts_per_page'   => self::LIMIT,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		) as $post ) {
			$sliders[ (string) $post->ID ] = $post->post_title;
		}
		return $sliders;
	}

	/**
	 * Returns the slides of a slider, as the plugin tags them with a term named by the slider's ID.
	 *
	 * @param string $id The slider's ID.
	 * @return int[]
	 */
	protected function slides( string $id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'ml-slide',
					'post_status'      => array( 'publish', 'draft', 'private', 'inherit' ),
					'posts_per_page'   => self::LIMIT,
					'fields'           => 'ids',
					'suppress_filters' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The plugin's own link of slides to their slider.
					'tax_query'        => array(
						array(
							'taxonomy' => 'ml-slider',
							'field'    => 'name',
							'terms'    => $id,
						),
					),
				)
			)
		);
	}

	/**
	 * Returns the images of a slider.
	 *
	 * @param string $id The slider's ID.
	 * @return int[]
	 */
	protected function images( string $id ): array {
		return array_values( array_filter( array_map( 'get_post_thumbnail_id', $this->slides( $id ) ) ) );
	}

	/**
	 * Reads the texts of the slides of a slider, as the plugin hands each out.
	 *
	 * @param string $id The slider's ID.
	 */
	protected function readObject( string $id ): void {
		foreach ( $this->slides( $id ) as $slide ) {
			$image                    = (int) get_post_thumbnail_id( $slide );
			list( $source, $caption ) = self::caption( $slide, $image );
			$this->text( $slide, $image, $source, $caption );
			if ( $image ) {
				AttachmentTexts::read( array( $image ), self::inherits( $slide, 'title' ) ? array( 'title', 'alt' ) : array( 'alt' ) );
			}
			foreach ( array( 'title', 'alt' ) as $field ) {
				if ( ! self::inherits( $slide, $field ) ) {
					get_post_meta( $slide, 'title' === $field ? 'ml-slider_title' : '_wp_attachment_image_alt', true );
				}
			}
		}
	}

	/**
	 * Whether a text read for a slider is one of its own: of its slides or of their images.
	 *
	 * @param string $id   The slider's ID.
	 * @param array  $leaf The text.
	 */
	protected function ownsText( string $id, array $leaf ): bool {
		return parent::ownsText( $id, $leaf ) || ( 0 === strpos( $leaf['o'], 'post:' ) && in_array( (int) substr( $leaf['o'], 5 ), $this->slides( $id ), true ) );
	}

	/**
	 * Returns what the editor calls a text of a slider: of a slide, or of an image.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, 'post:' ) && 'ml-slide' === get_post_type( (int) substr( $owner, 5 ) ) ) {
			$labels = array(
				'field/post_excerpt' => __( 'Caption of the slide', 'swiftling' ),
				swiftling_meta_path( 'ml-slider_title', 0 ) => __( 'Title of the slide', 'swiftling' ),
				swiftling_meta_path( '_wp_attachment_image_alt', 0 ) => __( 'Alt text of the slide', 'swiftling' ),
			);
			return $labels[ $path ] ?? '';
		}
		return parent::fieldLabel( $owner, $path );
	}
}
