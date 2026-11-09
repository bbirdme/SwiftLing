<?php
/**
 * Photo Gallery by 10Web: the texts of its galleries in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Photo Gallery by 10Web (the plugin "photo-gallery"). Its galleries and their images are rows of its own
 * tables, "bwg_gallery" and "bwg_image", each image with an alt text and a description. The plugin reads
 * them straight from its tables and prints them through no filter of its own, in its shortcode
 * [Best_Wordpress_Gallery] and in what it loads through admin-ajax.php after the page, its window of an
 * image (GalleryBox) and the next page of a gallery (bwg_frontend_data). In each, the texts are put in the
 * language, see translateOutput(); those requests are answered in the language of the page, see
 * sendingActions(). The texts are known by their words, one text of all the plugin's galleries,
 * "photo-gallery:gallery:all", as a request loaded after the page names no gallery of its own.
 *
 * Checked against the code of Photo Gallery 1.8 (the free plugin), in tests/GalleriesTest.php, and by hand
 * on a site with the plugin.
 */
class PhotoGallery extends GalleryPlugin {
	/**
	 * How many images the texts of the plugin are known from.
	 */
	const TEXTS_LIMIT = 5000;

	/**
	 * The actions of admin-ajax.php the plugin loads parts of a gallery through.
	 */
	const ACTIONS = array( 'GalleryBox', 'bwg_frontend_data' );

	/**
	 * The texts of the plugin's images and galleries, read once a request.
	 *
	 * @var string[]|null
	 */
	private $known = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'photo-gallery';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Photo Gallery by 10Web';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return function_exists( 'BWG' ) && class_exists( 'BWG', false );
	}

	/**
	 * Returns the actions of admin-ajax.php the plugin loads parts of a gallery through: answered in the
	 * language of the page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return self::ACTIONS;
	}

	/**
	 * Hooks where the plugin prints a gallery and answers a request of a part of one.
	 */
	public function register(): void {
		add_filter( 'do_shortcode_tag', array( $this, 'translateShortcode' ), 10, 2 );
		foreach ( self::ACTIONS as $action ) {
			// Before the plugin's own answer, at 10.
			add_action( 'wp_ajax_' . $action, array( $this, 'startOutput' ), 1 );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'startOutput' ), 1 );
		}
	}

	/**
	 * Returns what the plugin's shortcode printed with its texts in the mode of the moment.
	 *
	 * @param mixed  $output What the shortcode printed.
	 * @param string $tag    The shortcode.
	 * @return mixed
	 */
	public function translateShortcode( $output, $tag = '' ) {
		return 'Best_Wordpress_Gallery' === $tag && is_string( $output ) ? $this->translateOutput( $output ) : $output;
	}

	/**
	 * Before the plugin answers a request of a part of a gallery: what it prints goes through
	 * translateOutput().
	 */
	public function startOutput(): void {
		if ( 'translate' === swiftling_mode() ) {
			ob_start( array( $this, 'translateOutput' ) );
		}
	}

	/**
	 * Returns what the plugin printed with its texts in the mode of the moment.
	 *
	 * @param string $output What it printed.
	 */
	public function translateOutput( $output ) {
		return is_string( $output ) && '' !== $output ? $this->replaceTexts( $output, 'all', $this->known() ) : $output;
	}

	/**
	 * Returns the object key of the plugin's texts: one for all its galleries.
	 *
	 * @param string $id The gallery's ID.
	 */
	protected function owner( string $id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- One object for every gallery.
		return 'photo-gallery:gallery:all';
	}

	/**
	 * Returns the texts of the plugin's images and galleries.
	 *
	 * @return string[]
	 */
	private function known(): array {
		if ( null === $this->known ) {
			$this->known = array();
			foreach ( $this->rows( 0 ) as $row ) {
				$this->known[] = (string) $row->alt;
				$this->known[] = (string) $row->description;
			}
			foreach ( $this->galleries() as $gallery ) {
				$this->known[] = (string) $gallery->name;
				$this->known[] = (string) $gallery->description;
			}
			$this->known = array_values( array_unique( array_filter( $this->known, 'strlen' ) ) );
		}
		return $this->known;
	}

	/**
	 * Returns the plugin's images, of one gallery or of all.
	 *
	 * @param int $gallery The gallery, or 0 for all.
	 * @return object[]
	 */
	private function rows( int $gallery ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_image';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table: the texts it prints, read once a request.
		return (array) ( $gallery
			? $wpdb->get_results( $wpdb->prepare( 'SELECT id, alt, description FROM %i WHERE gallery_id = %d ORDER BY `order`, id LIMIT %d', $table, $gallery, self::TEXTS_LIMIT ) )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT id, alt, description FROM %i WHERE published = 1 ORDER BY id LIMIT %d', $table, self::TEXTS_LIMIT ) ) );
		// phpcs:enable
	}

	/**
	 * Returns the plugin's galleries.
	 *
	 * @return object[]
	 */
	private function galleries(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bwg_gallery';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, description FROM %i ORDER BY id LIMIT %d', $table, self::LIMIT ) );
	}

	/**
	 * Returns the plugin's galleries: their names, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function objects(): array {
		$galleries = array();
		foreach ( $this->galleries() as $gallery ) {
			$galleries[ (string) $gallery->id ] = (string) $gallery->name;
		}
		return $galleries;
	}

	/**
	 * Reads the texts of a gallery and of its images.
	 *
	 * @param string $id The gallery's ID.
	 */
	protected function readObject( string $id ): void {
		foreach ( $this->galleries() as $gallery ) {
			if ( (string) $gallery->id === $id ) {
				$this->ownText( 'all', (string) $gallery->name );
				$this->ownText( 'all', (string) $gallery->description );
			}
		}
		foreach ( $this->rows( (int) $id ) as $row ) {
			$this->ownText( 'all', (string) $row->alt );
			$this->ownText( 'all', (string) $row->description );
		}
	}
}
