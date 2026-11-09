<?php
/**
 * Envira Gallery: the texts of its galleries in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Envira Gallery (the free plugin "envira-gallery-lite"). A gallery is a post of the type "envira" whose
 * meta "_eg_gallery_data" holds its images, each with a title, alt text and caption of the gallery's own,
 * and its settings, with its description. The plugin reads the meta with get_post_meta(), which SwiftLing
 * translates. This class makes each gallery a subject of the Templates screen and tells the texts in the
 * meta from its settings and the addresses of its images, see fieldHoldsText().
 *
 * The plugin keeps what it read for a day, as transients "_eg_cache_…", whatever the language: a page in a
 * language puts the texts it hands out in the language, see translateData(), and leaves nothing it read
 * in the plugin's cache, see forgetCache(), so a page in the original language never shows a translation.
 *
 * Checked against the code of Envira Gallery Lite 1.16, in tests/GalleriesTest.php, and by hand on a site
 * with the plugin.
 */
class Envira extends GalleryPlugin {
	/**
	 * The meta of a gallery that holds it.
	 */
	const DATA = '_eg_gallery_data';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'envira';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Envira Gallery';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'ENVIRA_LITE_VERSION' ) || defined( 'ENVIRA_VERSION' );
	}

	/**
	 * In a page check and a page in a language, puts the texts of the galleries the plugin prints in the
	 * language, and keeps them out of the plugin's cache.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		unset( $probe );
		if ( 'control' === $mode ) {
			return;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		add_filter( 'envira_gallery_pre_data', array( $this, 'translateData' ), 10, 1 );
		// The action after a transient is set: renamed in WordPress 6.8.
		add_action( is_wp_version_compatible( '6.8' ) ? 'set_transient' : 'setted_transient', array( $this, 'forgetCache' ), 10, 1 );
	}

	/**
	 * Returns a gallery the plugin is about to print with its texts in the mode of the moment. The plugin
	 * may have taken it from its cache, made by a page in the original language: each text that is still
	 * what the gallery's meta holds is taken from the meta as SwiftLing hands it out. The order and the
	 * number of images the shortcode asked for stay as they are.
	 *
	 * @param mixed $data The gallery's data.
	 * @return mixed
	 */
	public function translateData( $data ) {
		if ( ! is_array( $data ) || empty( $data['id'] ) || 'envira' !== get_post_type( (int) $data['id'] ) ) {
			return $data;
		}
		$state          = &swiftling_state();
		$guard          = $state['guard'];
		$state['guard'] = true;
		try {
			$stored = get_post_meta( (int) $data['id'], self::DATA, true );
		} finally {
			$state['guard'] = $guard;
		}
		$translated = get_post_meta( (int) $data['id'], self::DATA, true );
		if ( ! is_array( $stored ) || ! is_array( $translated ) ) {
			return $data;
		}
		foreach ( (array) ( $data['gallery'] ?? array() ) as $image => $item ) {
			foreach ( array( 'title', 'alt', 'caption' ) as $text ) {
				if ( isset( $item[ $text ], $stored['gallery'][ $image ][ $text ], $translated['gallery'][ $image ][ $text ] ) && $stored['gallery'][ $image ][ $text ] === $item[ $text ] ) {
					$data['gallery'][ $image ][ $text ] = $translated['gallery'][ $image ][ $text ];
				}
			}
		}
		if ( isset( $data['config']['description'], $stored['config']['description'], $translated['config']['description'] ) && $stored['config']['description'] === $data['config']['description'] ) {
			$data['config']['description'] = $translated['config']['description'];
		}
		return $data;
	}

	/**
	 * Removes what the plugin put in its cache in a page check or a page in a language: the gallery as
	 * SwiftLing handed it out there, which a page in the original language would show.
	 *
	 * @param mixed $transient The transient's name.
	 */
	public function forgetCache( $transient ): void {
		if ( is_string( $transient ) && 0 === strpos( $transient, '_eg_cache_' ) ) {
			delete_transient( $transient );
		}
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
				'post_type'        => 'envira',
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
	 * Reads the gallery as the plugin does.
	 *
	 * @param string $id The gallery's ID.
	 */
	protected function readObject( string $id ): void {
		get_post_meta( (int) $id, self::DATA, true );
	}

	/**
	 * Whether a text read for a gallery is one of its own: a text of its meta.
	 *
	 * @param string $id   The gallery's ID.
	 * @param array  $leaf The text.
	 */
	protected function ownsText( string $id, array $leaf ): bool {
		return swiftling_post_owner( (int) $id ) === $leaf['o'] && true === $this->fieldHoldsText( $leaf['o'], $leaf['p'] );
	}

	/**
	 * Declares the title, alt text and caption of each image and the description of a gallery as text,
	 * and every other value of its meta, as an address or a setting, as none.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		$prefix = swiftling_meta_path( self::DATA, 0 );
		if ( 0 !== strpos( $owner, 'post:' ) || ( $prefix !== $path && 0 !== strpos( $path, $prefix . '/' ) ) || 'envira' !== get_post_type( (int) substr( $owner, 5 ) ) ) {
			return null;
		}
		$rest = substr( $path, strlen( $prefix ) + 1 );
		return 1 === preg_match( '#^(gallery/\d+/(title|alt|caption)|config/description)$#', $rest );
	}

	/**
	 * Returns what the editor calls a text of a gallery.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( true !== $this->fieldHoldsText( $owner, $path ) ) {
			return '';
		}
		if ( preg_match( '#/gallery/(\d+)/(title|alt|caption)$#', $path, $found ) ) {
			$names = array(
				'title'   => __( 'Title', 'swiftling' ),
				'alt'     => __( 'Alt text', 'swiftling' ),
				'caption' => __( 'Caption', 'swiftling' ),
			);
			$file  = wp_basename( (string) get_attached_file( (int) $found[1] ) );
			return ( '' !== $file ? $file : __( 'Image', 'swiftling' ) ) . ' › ' . $names[ $found[2] ];
		}
		return __( 'Description', 'swiftling' );
	}
}
