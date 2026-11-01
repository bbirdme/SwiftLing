<?php
/**
 * Tests of the class of Popup Maker.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\PopupMaker;
use SwiftLing\Builders\Registry;

/**
 * What SwiftLing translates of Popup Maker: each popup a subject of its own, its content where the
 * plugin prints it, its title and close text as meta, and the close texts of the themes in the
 * plugin's option.
 */
class PopupMakerTest extends TestCase {

	/**
	 * The popup of the tests.
	 *
	 * @var int
	 */
	private $popup;

	/**
	 * Registers the plugin's types of post and makes a popup with a title, a close text and other
	 * settings, and the plugin's option of the close texts of its themes.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type(
			'popup',
			array(
				'public'             => true,
				'publicly_queryable' => false,
			)
		);
		register_post_type( 'popup_theme' );
		self::reset_static( Registry::class, 'active' );
		Registry::get( 'popup-maker' )->register();
		$this->popup = self::factory()->post->create(
			array(
				'post_type'    => 'popup',
				'post_title'   => 'Ponuda',
				'post_content' => '<!-- wp:paragraph --><p>Pridružite se vrtnom klubu.</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $this->popup, 'popup_title', 'Vrtni klub' );
		update_post_meta(
			$this->popup,
			'popup_settings',
			array(
				'close_text'     => 'Zatvori',
				'animation_type' => 'fade',
				'location'       => 'center top',
			)
		);
		$theme = self::factory()->post->create( array( 'post_type' => 'popup_theme' ) );
		update_option(
			PopupMaker::THEME_TEXTS,
			array(
				$theme     => 'ZATVORI',
				$theme + 1 => '&times;',
			)
		);
	}

	/**
	 * Unregisters the plugin's types of post.
	 */
	public function tear_down() {
		unregister_post_type( 'popup' );
		unregister_post_type( 'popup_theme' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Returns the texts a subject offers, by original.
	 *
	 * @param string $key Subject key.
	 * @return array<string, array>
	 */
	private function units( $key ) {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( $key ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		return $units;
	}

	/**
	 * Every popup is a subject on the Templates screen, and the close texts of the themes one more, as a
	 * theme's close text is a word: a popup visitors never open by its address has no page to translate.
	 */
	public function test_subjects() {
		$this->assertFalse( is_post_type_viewable( 'popup' ) );
		$this->assertNull( swiftling_subject( 'post:' . $this->popup ) );
		$keys = wp_list_pluck( Registry::subjects()['Popup Maker'][0]['subjects'], 'key' );
		$this->assertSame( array( 'popup-maker:' . $this->popup, 'popup-maker-themes' ), $keys );

		update_option( PopupMaker::THEME_TEXTS, array( 5 => '&times;' ) );
		$keys = wp_list_pluck( Registry::subjects()['Popup Maker'][0]['subjects'], 'key' );
		$this->assertSame( array( 'popup-maker:' . $this->popup ), $keys, 'Only signs: no subject of themes' );
	}

	/**
	 * A popup offers its title, content and close text, not its other settings. Translated, the plugin
	 * prints them in the language of the page; the original keeps them, and another post's content handed
	 * to the filter stays.
	 */
	public function test_popup_translated() {
		$units = $this->units( 'popup-maker:' . $this->popup );
		$this->assertSame( array( 'Vrtni klub', '<p>Pridružite se vrtnom klubu.</p>', 'Zatvori' ), array_keys( $units ), 'A block whole, as on a page' );
		$this->assertSame( 'Close button', Registry::fieldLabel( $units['Zatvori']['o'], $units['Zatvori']['p'] ) );
		$this->assertSame( 'Content', Registry::fieldLabel( $units['<p>Pridružite se vrtnom klubu.</p>']['o'], $units['<p>Pridružite se vrtnom klubu.</p>']['p'] ) );

		foreach ( array(
			'Vrtni klub'                         => 'Gartenclub',
			'<p>Pridružite se vrtnom klubu.</p>' => '<p>Werden Sie Mitglied im Gartenclub.</p>',
			'Zatvori'                            => 'Schließen',
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
		$content = get_post( $this->popup )->post_content;
		$this->show_in( 'de' );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertStringContainsString( '<p>Werden Sie Mitglied im Gartenclub.</p>', apply_filters( 'pum_popup_content', $content, $this->popup ) );
		$this->assertSame( 'Gartenclub', get_post_meta( $this->popup, 'popup_title', true ) );
		$settings = get_post_meta( $this->popup, 'popup_settings', true );
		$this->assertSame( 'Schließen', $settings['close_text'] );
		$this->assertSame( 'fade', $settings['animation_type'] );

		// In a page check the plugin's settings other than its texts are left as they are: a marker in its
		// position or its animation would stop the popup.
		$this->reset_state();
		$read = function () {
			get_post_meta( $this->popup, 'popup_settings', true );
		};
		$this->assertSame( array( 'Zatvori' ), array_values( wp_list_pluck( swiftling_collect( $read ), 'v' ) ) );
		update_post_meta( $this->popup, 'popup_settings', array( 'location' => 'Center top of the page' ) );
		$this->assertSame( array(), array_values( wp_list_pluck( swiftling_collect( $read ), 'v' ) ), 'Even one that reads as text' );
		$this->show_in( 'de' );

		$this->reset_state();
		$this->assertSame( $content, apply_filters( 'pum_popup_content', $content, $this->popup ), 'The original' );
		// phpcs:enable
	}

	/**
	 * The close texts of the themes are translated in the plugin's option, a sign such as "&times;" is
	 * not offered, and the plugin writing the option again in a language keeps the original.
	 */
	public function test_theme_close_texts() {
		$units = $this->units( 'popup-maker-themes' );
		$this->assertSame( array( 'ZATVORI' ), array_keys( $units ) );
		$this->translate( $units['ZATVORI']['o'], $units['ZATVORI']['p'], 'ZATVORI', 'de', 'SCHLIESSEN' );
		$this->show_in( 'de' );
		$texts = get_option( PopupMaker::THEME_TEXTS );
		$this->assertSame( 'SCHLIESSEN', reset( $texts ) );
		$this->reset_state();
		$texts = get_option( PopupMaker::THEME_TEXTS );
		$this->assertSame( 'ZATVORI', reset( $texts ) );
	}
}
