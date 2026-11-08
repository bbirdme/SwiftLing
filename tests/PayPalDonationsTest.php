<?php
/**
 * Tests of the class of Donations via PayPal.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\PayPalDonations;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakePayPalDonations.php';

/**
 * What SwiftLing translates of Donations via PayPal: the purpose and reference of a donation, the page
 * PayPal sends the donor back to, and the language of the button and of PayPal's pages.
 */
class PayPalDonationsTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakePayPalDonations
	 */
	private $paypal;

	/**
	 * German in folders, the fake in the registry and the plugin's settings with the page of thanks.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'hu_HU' => 'hu',
			),
			'directory'
		);
		$this->use_pretty_permalinks();
		$this->paypal = new FakePayPalDonations();
		$builders     = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof PayPalDonations ? $this->paypal : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->paypal->register();
		$thanks = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'hvala',
				'post_title' => 'Hvala',
			)
		);
		update_option(
			PayPalDonations::OPTION,
			array(
				'purpose'          => 'Posadi drvo uz rijeku',
				'reference'        => 'Fond za drveće',
				'return_page'      => get_permalink( $thanks ),
				'button_localized' => 'en_US',
				'currency_code'    => 'EUR',
			)
		);
	}

	/**
	 * Gives the registry back its own builders, and the request its folder.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * The settings offer the purpose and the reference, not the page or the currency. In a language the
	 * plugin reads them translated, the page PayPal sends the donor back to in the language, and PayPal's
	 * pages in the language; in the original as the site's owner set them.
	 */
	public function test_settings() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'paypal-donations-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Posadi drvo uz rijeku', 'Fond za drveće' ), array_keys( $units ) );
		$this->translate( $units['Posadi drvo uz rijeku']['o'], $units['Posadi drvo uz rijeku']['p'], 'Posadi drvo uz rijeku', 'de', 'Pflanz einen Baum am Fluss' );
		$site = untrailingslashit( (string) get_option( 'home' ) );

		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		$options = get_option( PayPalDonations::OPTION );
		$this->assertSame( 'Pflanz einen Baum am Fluss', $options['purpose'] );
		$this->assertSame( 'Fond za drveće', $options['reference'], 'Not translated yet: the original' );
		$this->assertSame( $site . '/de/hvala/', $options['return_page'] );
		$this->assertSame( 'true', $options['set_checkout_language'] );
		$this->assertSame( 'DE', $options['checkout_language'] );
		$this->assertSame( 'EUR', $options['currency_code'] );

		$this->show_in( 'hu' );
		swiftling_keep_folder( 'hu' );
		$options = get_option( PayPalDonations::OPTION );
		$this->assertArrayNotHasKey( 'checkout_language', $options, 'A language PayPal\'s pages are not offered in' );

		$this->reset_state();
		swiftling_keep_folder( '' );
		$options = get_option( PayPalDonations::OPTION );
		$this->assertSame( $site . '/hvala/', $options['return_page'] );
		$this->assertArrayNotHasKey( 'set_checkout_language', $options );
	}

	/**
	 * In a language the button is PayPal's picture in the language, in its own country first; where
	 * PayPal has none, and in the original, what the site's owner chose.
	 */
	public function test_button() {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->assertSame( 'en_US', apply_filters( 'pd_button_localized_value', 'en_US' ), 'The original' );
		$this->show_in( 'de' );
		$this->assertSame( 'de_DE/DE', apply_filters( 'pd_button_localized_value', 'en_US' ) );
		$this->show_in( 'fr' );
		$this->assertSame( 'fr_FR/FR', apply_filters( 'pd_button_localized_value', 'en_US' ) );
		$this->show_in( 'hu' );
		$this->assertSame( 'nl_NL/BE', apply_filters( 'pd_button_localized_value', 'nl_NL/BE' ), 'No picture in the language' );
		// phpcs:enable
	}
}
