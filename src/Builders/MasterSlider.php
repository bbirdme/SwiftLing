<?php
/**
 * Master Slider: the texts of its sliders in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Master Slider. A slider is a row of the plugin's own table "masterslider_sliders", its slides JSON in
 * base64 in the column "params": each slide's text (its "info", HTML), the alt text of its picture and
 * the title of its link. The plugin makes the slider of those, or takes the copy of it it keeps, and hands
 * it out to the filter masterslider_slider_content, where the texts are put in the language, see
 * translateSlider(). Its copy, made without them, stays in the original for every language.
 *
 * Checked against the code of Master Slider 3.11 (the free plugin), in tests/GalleriesTest.php, and by
 * hand on a site with the plugin.
 */
class MasterSlider extends GalleryPlugin {
	/**
	 * The texts of a slide a visitor reads, by their name in the plugin's data.
	 */
	const TEXTS = array( 'info', 'bgAlt', 'linkTitle' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'master-slider';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Master Slider';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return defined( 'MSWP_AVERTA_VERSION' );
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
	 * Hooks where the plugin hands out a slider.
	 */
	public function register(): void {
		add_filter( 'masterslider_slider_content', array( $this, 'translateSlider' ), 10, 2 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter.
	}

	/**
	 * Returns a slider as the plugin printed it with its texts in the mode of the moment.
	 *
	 * @param mixed $html The slider.
	 * @param mixed $id   The slider's ID.
	 * @return mixed
	 */
	public function translateSlider( $html, $id = 0 ) {
		if ( ! is_string( $html ) || ! is_numeric( $id ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $html;
		}
		return $this->replaceTexts( $html, (string) (int) $id, $this->texts( (int) $id ) );
	}

	/**
	 * Returns the texts of a slider's slides.
	 *
	 * @param int $id The slider's ID.
	 * @return string[]
	 */
	protected function texts( int $id ): array {
		$texts = array();
		foreach ( $this->slides( $id ) as $slide ) {
			foreach ( self::TEXTS as $name ) {
				if ( isset( $slide[ $name ] ) && is_string( $slide[ $name ] ) && '' !== trim( $slide[ $name ] ) ) {
					$texts[] = wp_unslash( $slide[ $name ] );
				}
			}
		}
		return $texts;
	}

	/**
	 * Returns a slider's slides, as the plugin keeps them.
	 *
	 * @param int $id The slider's ID.
	 * @return array[]
	 */
	protected function slides( int $id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'masterslider_sliders';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the plugin reads it the same way for each slider it prints.
		$params = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT params FROM %i WHERE ID = %d', $table, $id ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The plugin keeps its slides so.
		$data   = json_decode( (string) base64_decode( $params, true ), true );
		$slides = array();
		foreach ( (array) ( is_array( $data ) ? ( $data['MSPanel.Slide'] ?? array() ) : array() ) as $slide ) {
			$slide = is_string( $slide ) ? json_decode( $slide, true ) : $slide;
			if ( is_array( $slide ) ) {
				$slides[] = $slide;
			}
		}
		return $slides;
	}

	/**
	 * Returns the plugin's sliders: their titles, by ID.
	 *
	 * @return array<string, string>
	 */
	protected function objects(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'masterslider_sliders';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		$sliders = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the Templates screen.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, title FROM %i WHERE status NOT IN ( 'deleted', 'trash' ) ORDER BY ID LIMIT %d", $table, self::LIMIT ) ) as $row ) {
			$sliders[ (string) $row->ID ] = (string) $row->title;
		}
		return $sliders;
	}

	/**
	 * Reads the texts of a slider's slides.
	 *
	 * @param string $id The slider's ID.
	 */
	protected function readObject( string $id ): void {
		foreach ( $this->texts( (int) $id ) as $text ) {
			$this->ownText( $id, $text );
		}
	}
}
