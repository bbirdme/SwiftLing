<?php
/**
 * The texts of an image of the media library, as a gallery plugin reads them.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * The title, caption, description and alt text of an image of the media library, for a plugin that reads
 * them past the places SwiftLing translates them at: from the image's post object or straight from the
 * database. Each is translated under the image itself, as everywhere else on the site, "post:<ID>" at
 * "field/post_title", "field/post_excerpt", "field/post_content" or the meta "_wp_attachment_image_alt",
 * so an image is translated once and shows translated in every gallery and page that holds it.
 *
 * A value that is not what the image holds, as a copy a plugin keeps and the site's owner changed, is
 * left as it is: it is no text of the image.
 */
final class AttachmentTexts {
	/**
	 * The texts of an image, by the name a gallery gives them: where each is kept.
	 */
	const FIELDS = array(
		'title'       => 'post_title',
		'caption'     => 'post_excerpt',
		'description' => 'post_content',
		'alt'         => '_wp_attachment_image_alt',
	);

	/**
	 * Returns a text of an image in the mode of the moment.
	 *
	 * @param int    $id    The image's ID.
	 * @param string $field Which text, see FIELDS.
	 * @param mixed  $value The text, as the plugin read it.
	 * @return mixed
	 */
	public static function text( int $id, string $field, $value ) {
		if ( ! $id || ! isset( self::FIELDS[ $field ] ) || ! is_string( $value ) || '' === trim( $value ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $value;
		}
		if ( 'attachment' !== get_post_type( $id ) || self::stored( $id, $field ) !== $value ) {
			return $value;
		}
		$owner = swiftling_post_owner( $id );
		$key   = self::FIELDS[ $field ];
		$new   = 'alt' === $field
			? swiftling_transform( $owner, swiftling_meta_path( $key, 0 ), 'meta:' . $key, $value )
			: swiftling_transform( $owner, 'field/' . $key, 'field:' . $key, $value );
		return is_string( $new ) ? $new : $value;
	}

	/**
	 * Returns a text of an image as stored, untranslated.
	 *
	 * @param int    $id    The image's ID.
	 * @param string $field Which text, see FIELDS.
	 */
	public static function stored( int $id, string $field ): string {
		if ( 'alt' !== $field ) {
			return (string) get_post_field( self::FIELDS[ $field ], $id, 'raw' );
		}
		// The meta as stored: SwiftLing's own filter would hand it out translated.
		$state          = &swiftling_state();
		$guard          = $state['guard'];
		$state['guard'] = true;
		try {
			return (string) get_post_meta( $id, self::FIELDS['alt'], true );
		} finally {
			$state['guard'] = $guard;
		}
	}

	/**
	 * Reads the texts of images as a gallery shows them, so the editor lists each under the image.
	 *
	 * @param int[]    $ids    The images.
	 * @param string[] $fields Which texts, see FIELDS.
	 */
	public static function read( array $ids, array $fields ): void {
		foreach ( $ids as $id ) {
			foreach ( $fields as $field ) {
				self::text( (int) $id, $field, self::stored( (int) $id, $field ) );
			}
		}
	}

	/**
	 * Whether an object key is an image of the media library.
	 *
	 * @param string $owner Object key, as "post:5".
	 */
	public static function isImage( string $owner ): bool {
		return 0 === strpos( $owner, 'post:' ) && 'attachment' === get_post_type( (int) substr( $owner, 5 ) );
	}

	/**
	 * Returns what the editor calls a text of an image: the image's file name and which text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of an image.
	 */
	public static function label( string $owner, string $path ): string {
		if ( ! self::isImage( $owner ) ) {
			return '';
		}
		$names = array(
			'field/post_title'                            => __( 'Title', 'swiftling' ),
			'field/post_excerpt'                          => __( 'Caption', 'swiftling' ),
			'field/post_content'                          => __( 'Description', 'swiftling' ),
			swiftling_meta_path( self::FIELDS['alt'], 0 ) => __( 'Alt text', 'swiftling' ),
		);
		if ( ! isset( $names[ $path ] ) ) {
			return '';
		}
		$file = wp_basename( (string) get_attached_file( (int) substr( $owner, 5 ) ) );
		return ( '' !== $file ? $file : __( 'Image', 'swiftling' ) ) . ' › ' . $names[ $path ];
	}
}
