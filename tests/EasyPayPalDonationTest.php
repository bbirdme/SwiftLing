<?php
/**
 * Tests of the class of Accept Donations with PayPal & Stripe.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\EasyPayPalDonation;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeEasyPayPalDonation.php';

/**
 * What SwiftLing translates of Accept Donations with PayPal & Stripe: a button's name and the names of its
 * amounts, the pages PayPal sends the donor back to, and PayPal's language.
 */
class EasyPayPalDonationTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeEasyPayPalDonation
	 */
	private $wpedon;

	/**
	 * A button of the tests.
	 *
	 * @var int
	 */
	private $button;

	/**
	 * German in folders, the fake in the registry, the plugin's type of buttons with a button, and its
	 * settings.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'hu_HU' => 'hu',
			),
			'directory'
		);
		$this->use_pretty_permalinks();
		register_post_type( EasyPayPalDonation::POST_TYPE );
		$this->wpedon = new FakeEasyPayPalDonation();
		$builders     = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof EasyPayPalDonation ? $this->wpedon : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->wpedon->register();
		$this->button = self::factory()->post->create(
			array(
				'post_type'  => EasyPayPalDonation::POST_TYPE,
				'post_title' => 'Klupa uz jezero',
			)
		);
		update_post_meta( $this->button, 'wpedon_button_scpriceprice', 'Odaberite dar' );
		update_post_meta( $this->button, 'wpedon_button_scpriceaname', 'Mala cigla' );
		update_post_meta( $this->button, 'wpedon_button_scpricea', '10' );
		update_post_meta( $this->button, 'wpedon_button_id', 'klupa' );
		$thanks = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'hvala',
			)
		);
		update_option(
			'wpedon_settings',
			array(
				'return'   => get_permalink( $thanks ),
				'cancel'   => home_url( '/' ),
				'language' => '3',
				'currency' => '25',
			)
		);
	}

	/**
	 * Gives the registry back its own builders, and the request its folder.
	 */
	public function tear_down() {
		unregister_post_type( EasyPayPalDonation::POST_TYPE );
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * A button is a subject of its own with its name and the names of its amounts, not its amounts or
	 * its ID. In a language the plugin reads them translated, and the name where its shortcode prints it.
	 */
	public function test_button() {
		$key = 'wpedon-button:' . $this->button;
		$this->assertSame( $key, $this->wpedon->subjects()[0]['subjects'][0]['key'] );
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( $key ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Klupa uz jezero', 'Odaberite dar', 'Mala cigla' ), array_keys( $units ) );
		$this->assertSame( 'Amount 1', $units['Mala cigla']['place'] );
		$button = $this->button;
		$found  = wp_list_pluck(
			$this->probe(
				static function () use ( $button ) {
					get_post_meta( $button, 'wpedon_button_id', true );
					get_post_meta( $button, 'wpedon_button_scpriceaname', true );
				}
			),
			'v'
		);
		$this->assertContains( 'Mala cigla', $found, 'A page check that shows the button' );
		$this->assertNotContains( 'klupa', $found, 'The button\'s ID is no text' );
		foreach ( array(
			'Klupa uz jezero' => 'Bank am See',
			'Mala cigla'      => 'Ein kleiner Ziegel',
		) as $original => $german ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $german );
		}
		$printed = "<label>Klupa uz jezero</label><input type='hidden' name='item_name' value='Klupa uz jezero' />";

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's filter of a shortcode, as it runs it.
		$this->assertSame( $printed, apply_filters( 'do_shortcode_tag', $printed, 'wpedon', array( 'id' => $this->button ), array() ), 'The original' );
		$this->show_in( 'de' );
		$this->assertSame( 'Ein kleiner Ziegel', get_post_meta( $this->button, 'wpedon_button_scpriceaname', true ) );
		$this->assertSame( '10', get_post_meta( $this->button, 'wpedon_button_scpricea', true ) );
		$this->assertSame( "<label>Bank am See</label><input type='hidden' name='item_name' value='Bank am See' />", apply_filters( 'do_shortcode_tag', $printed, 'wpedon', array( 'id' => $this->button ), array() ) );
		$this->assertSame(
			$printed,
			apply_filters(
				'do_shortcode_tag',
				$printed,
				'wpedon',
				array(
					'id'   => $this->button,
					'name' => 'Klupa uz jezero',
				),
				array()
			),
			'A name given in the shortcode is the page\'s text'
		);
		$this->assertSame( 'x', apply_filters( 'do_shortcode_tag', 'x', 'gallery', array( 'id' => $this->button ), array() ) );
		// phpcs:enable
	}

	/**
	 * In a language, PayPal sends the donor back to the pages in the language, and its pages are in the
	 * language, the plugin's choice of it in the settings and on a button; where the plugin offers no
	 * such language, and in the original, what the site's owner chose.
	 */
	public function test_paypal() {
		$site = untrailingslashit( (string) get_option( 'home' ) );
		update_post_meta( $this->button, 'wpedon_button_language', '7' );
		$this->assertSame( '7', get_post_meta( $this->button, 'wpedon_button_language', true ), 'The original' );

		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		$settings = get_option( 'wpedon_settings' );
		$this->assertSame( $site . '/de/hvala/', $settings['return'] );
		$this->assertSame( '5', $settings['language'] );
		$this->assertSame( '25', $settings['currency'] );
		$this->assertSame( '5', get_post_meta( $this->button, 'wpedon_button_language', true ) );
		$this->assertSame( 'Mala cigla', get_post_meta( $this->button, 'wpedon_button_scpriceaname', true ), 'Another meta of the button' );

		$this->show_in( 'hu' );
		swiftling_keep_folder( 'hu' );
		$this->assertSame( '3', get_option( 'wpedon_settings' )['language'], 'A language the plugin does not offer' );
		$this->assertSame( '7', get_post_meta( $this->button, 'wpedon_button_language', true ) );

		$this->reset_state();
		swiftling_keep_folder( '' );
		$this->assertSame( $site . '/hvala/', get_option( 'wpedon_settings' )['return'] );
	}
}
