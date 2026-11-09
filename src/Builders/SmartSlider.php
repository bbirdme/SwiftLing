<?php
/**
 * Smart Slider 3: the texts of its sliders in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Smart Slider 3. A slider is a row of the plugin's own table "nextend2_smartslider3_sliders", its slides
 * rows of "nextend2_smartslider3_slides": a slide's title and description, and its layers as JSON, each
 * layer's item with its texts, as a heading, a text or the label of a button. On a page the plugin prints
 * a copy of the slider it keeps for a while, made once, and hands it out whole to the filter
 * translate_text on every page, the filter plugins of languages answer: the texts are put in the
 * language there, see translateText(), known by their words, as the filter hands out no more. The same
 * text is so one text of all the plugin's sliders, "smart-slider:slider:all".
 *
 * The copy the plugin keeps is left in the original language: a translation changed shows at once, and
 * every language shares the one copy. The plugin's other filter, nextend_translation, hands out a
 * slide's title while it makes the copy, so it is not answered.
 *
 * Checked against the code of Smart Slider 3.5 (the free plugin), in tests/GalleriesTest.php, and by hand
 * on a site with the plugin.
 */
class SmartSlider extends GalleryPlugin {
	/**
	 * The texts of a layer's item a visitor reads, by their name in the plugin's data.
	 */
	const ITEM_TEXTS = array( 'heading', 'content', 'alt', 'title' );

	/**
	 * How many slides the texts of the plugin are known from.
	 */
	const SLIDES_LIMIT = 1000;

	/**
	 * The texts of the plugin's slides, by their words, read once a request.
	 *
	 * @var array<string, true>|null
	 */
	private $known = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'smart-slider';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Smart Slider 3';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'NEXTEND_SMARTSLIDER_3' );
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
	 * Returns the object key of the plugin's texts: one for all its sliders.
	 *
	 * @param string $id The slider's ID.
	 */
	protected function owner( string $id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- One object for every slider.
		return 'smart-slider:slider:all';
	}

	/**
	 * Hooks where the plugin hands out a slider to translate.
	 */
	public function register(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
		add_filter( 'translate_text', array( $this, 'translateText' ), 10, 1 );
	}

	/**
	 * Returns a slider the plugin printed, or a text of one, in the mode of the moment.
	 *
	 * @param mixed $text The slider, as HTML, or one of its texts.
	 * @return mixed
	 */
	public function translateText( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $text;
		}
		if ( isset( $this->known()[ $text ] ) ) {
			return $this->ownText( 'all', $text );
		}
		return false !== strpos( $text, '<' ) ? $this->replaceTexts( $text, 'all', array_keys( $this->known() ) ) : $text;
	}

	/**
	 * Returns the texts of the plugin's slides, by their words.
	 *
	 * @return array<string, true>
	 */
	private function known(): array {
		if ( null === $this->known ) {
			$this->known = array();
			foreach ( $this->slides( 0 ) as $slide ) {
				foreach ( self::slideTexts( $slide ) as $text ) {
					$this->known[ $text ] = true;
				}
			}
		}
		return $this->known;
	}

	/**
	 * Returns the texts of a slide: its title, its description and the texts of its layers.
	 *
	 * @param object $slide The slide, as a row of the plugin's table.
	 * @return string[]
	 */
	private static function slideTexts( $slide ): array {
		$texts = array( (string) $slide->title, (string) $slide->description );
		self::layerTexts( json_decode( (string) $slide->slide, true ), $texts );
		return array_values( array_unique( array_filter( $texts, array( self::class, 'isText' ) ) ) );
	}

	/**
	 * Adds the texts of the items of layers, at any depth.
	 *
	 * @param mixed    $node  A layer, or a list of them.
	 * @param string[] $texts Texts so far.
	 */
	private static function layerTexts( $node, array &$texts ): void {
		if ( ! is_array( $node ) ) {
			return;
		}
		if ( isset( $node['item']['values'] ) && is_array( $node['item']['values'] ) ) {
			foreach ( self::ITEM_TEXTS as $name ) {
				if ( isset( $node['item']['values'][ $name ] ) && is_string( $node['item']['values'][ $name ] ) ) {
					$texts[] = $node['item']['values'][ $name ];
				}
			}
		}
		foreach ( $node as $key => $child ) {
			if ( 'item' !== $key && is_array( $child ) ) {
				self::layerTexts( $child, $texts );
			}
		}
	}

	/**
	 * Whether a value is a text: it has letters, and is no address.
	 *
	 * @param mixed $value The value.
	 */
	private static function isText( $value ): bool {
		return is_string( $value ) && preg_match( '/\p{L}/u', wp_strip_all_tags( $value ) ) && ! preg_match( '#^(https?:|//|\$|\{)#', trim( $value ) );
	}

	/**
	 * Returns the plugin's slides, of one slider or of all.
	 *
	 * @param int $slider The slider, or 0 for all.
	 * @return object[]
	 */
	private function slides( int $slider ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'nextend2_smartslider3_slides';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table: the texts it hands out, read once a request.
		return (array) ( $slider
			? $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, description, slide FROM %i WHERE slider = %d ORDER BY ordering, id LIMIT %d', $table, $slider, self::SLIDES_LIMIT ) )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, description, slide FROM %i WHERE published = 1 ORDER BY id LIMIT %d', $table, self::SLIDES_LIMIT ) ) );
		// phpcs:enable
	}

	/**
	 * Returns the plugin's sliders: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function objects(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'nextend2_smartslider3_sliders';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		$sliders = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the Templates screen.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM %i WHERE type <> 'group' AND slider_status <> 'trash' ORDER BY id LIMIT %d", $table, self::LIMIT ) ) as $row ) {
			$sliders[ (string) $row->id ] = (string) $row->title;
		}
		return $sliders;
	}

	/**
	 * Reads the texts of a slider's slides.
	 *
	 * @param string $id The slider's ID.
	 */
	protected function readObject( string $id ): void {
		foreach ( $this->slides( (int) $id ) as $slide ) {
			foreach ( self::slideTexts( $slide ) as $text ) {
				$this->ownText( 'all', $text );
			}
		}
	}
}
