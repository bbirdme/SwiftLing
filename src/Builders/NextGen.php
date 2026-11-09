<?php
/**
 * NextGEN Gallery: the texts of its galleries in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * NextGEN Gallery. Its galleries and their images are rows of its own tables, "ngg_gallery" and
 * "ngg_pictures", each image with an alt text (shown as its title) and a description. The plugin hands
 * each of these texts, while it gets the images of a gallery to show, to the filter "localization" that
 * plugins of languages answer, see translateText(): known by their words, as the filter hands out no more.
 * The title and description of a gallery the plugin shows its visitors nowhere.
 * The same text is so one text of all the plugin's galleries, "nextgen:gallery:all". The plugin keeps a
 * gallery it showed for half an hour, as it printed it: under a key the language is made part of, see
 * cacheLanguage(), and emptied once a translation of one of its texts is saved, see translationsSaved().
 *
 * Checked against the code of NextGEN Gallery 4.5 (the free plugin), in tests/GalleriesTest.php, and by
 * hand on a site with the plugin.
 */
class NextGen extends GalleryPlugin {
	/**
	 * How many images the texts of the plugin are known from.
	 */
	const TEXTS_LIMIT = 5000;

	/**
	 * The texts of the plugin's images, known by their words, read once a request.
	 *
	 * @var array<string, true>|null
	 */
	private $known = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'nextgen';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'NextGEN Gallery';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'NGG_PLUGIN_VERSION' );
	}

	/**
	 * Hooks where the plugin hands out a text to translate and works out the key it keeps a gallery
	 * under, and where translations are saved.
	 */
	public function register(): void {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters.
		add_filter( 'localization', array( $this, 'translateText' ), 10, 1 );
		add_filter( 'ngg_displayed_gallery_cache_params', array( $this, 'cacheLanguage' ), 10, 1 );
		// phpcs:enable
		add_action( 'swiftling_translations_saved', array( $this, 'translationsSaved' ), 10, 2 );
	}

	/**
	 * Empties the galleries the plugin keeps once a translation of one of its texts is saved: they hold
	 * the translation they were printed with.
	 *
	 * @param mixed $lang  Locale.
	 * @param mixed $items Place key => the translation.
	 */
	public function translationsSaved( $lang, $items ): void {
		unset( $lang );
		if ( ! is_array( $items ) || ! $items || ! class_exists( '\Imagely\NGG\Util\Transient' ) ) {
			return;
		}
		foreach ( array_keys( $this->known() ) as $text ) {
			if ( isset( $items[ swiftling_place_key( $this->owner( 'all' ), swiftling_path_key( 'texts/' . HtmlTexts::key( $text ) ), swiftling_hash( $text ) ) ] ) ) {
				\Imagely\NGG\Util\Transient::flush( 'displayed_gallery_rendering' );
				return;
			}
		}
	}

	/**
	 * Returns the object key of the plugin's texts: one for all its galleries, as the plugin hands out a
	 * text without its gallery.
	 *
	 * @param string $id The gallery's ID.
	 */
	protected function owner( string $id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- One object for every gallery.
		return 'nextgen:gallery:all';
	}

	/**
	 * Returns a text the plugin hands out in the mode of the moment: one of its images.
	 *
	 * @param mixed $text The text.
	 * @return mixed
	 */
	public function translateText( $text ) {
		if ( ! is_string( $text ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) || ! isset( $this->known()[ $text ] ) ) {
			return $text;
		}
		return $this->ownText( 'all', $text );
	}

	/**
	 * Returns the parameters of the key the plugin keeps a shown gallery under, with the language.
	 *
	 * @param mixed $params The parameters.
	 * @return mixed
	 */
	public function cacheLanguage( $params ) {
		if ( is_array( $params ) && '' !== swiftling_writing_language() ) {
			$params['swiftling_language'] = swiftling_writing_language();
		}
		return $params;
	}

	/**
	 * Returns the texts of the plugin's images, by their words.
	 *
	 * @return array<string, true>
	 */
	private function known(): array {
		if ( null === $this->known ) {
			$this->known = array();
			foreach ( $this->rows( 0 ) as $row ) {
				foreach ( array( 'alttext', 'description' ) as $field ) {
					if ( '' !== trim( (string) $row->$field ) ) {
						$this->known[ (string) $row->$field ] = true;
					}
				}
			}
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
		$table = $wpdb->prefix . 'ngg_pictures';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table: the texts it hands out, read once a request.
		return (array) ( $gallery
			? $wpdb->get_results( $wpdb->prepare( 'SELECT pid, galleryid, alttext, description FROM %i WHERE galleryid = %d ORDER BY sortorder, pid LIMIT %d', $table, $gallery, self::TEXTS_LIMIT ) )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT pid, galleryid, alttext, description FROM %i ORDER BY pid LIMIT %d', $table, self::TEXTS_LIMIT ) ) );
		// phpcs:enable
	}

	/**
	 * Returns the plugin's galleries.
	 *
	 * @return object[]
	 */
	private function galleries(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ngg_gallery';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT gid, title, galdesc FROM %i ORDER BY gid LIMIT %d', $table, self::LIMIT ) );
	}

	/**
	 * Returns the plugin's galleries: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function objects(): array {
		$galleries = array();
		foreach ( $this->galleries() as $gallery ) {
			$galleries[ (string) $gallery->gid ] = (string) $gallery->title;
		}
		return $galleries;
	}

	/**
	 * Reads the texts of the images of a gallery, as the plugin hands them out.
	 *
	 * @param string $id The gallery's ID.
	 */
	protected function readObject( string $id ): void {
		foreach ( $this->rows( (int) $id ) as $row ) {
			$this->translateText( (string) $row->alttext );
			$this->translateText( (string) $row->description );
		}
	}
}
