<?php
/**
 * Tests of the class of Donation Platform for WooCommerce.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\WcDonationPlatform;

require_once __DIR__ . '/stubs/FakeWcDonationPlatform.php';

/**
 * What SwiftLing translates of Donation Platform for WooCommerce: the titles of its settings.
 */
class WcDonationPlatformTest extends TestCase {

	/**
	 * Gives the registry back its own builders.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * The settings offer the titles the site's owner wrote, each an option of one text, not a setting of
	 * another kind; in a language the plugin reads them translated, and a placeholder of the leaderboard
	 * stays as it is in machine translation.
	 */
	public function test_titles() {
		$this->set_languages();
		$class    = new FakeWcDonationPlatform();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof WcDonationPlatform ? $class : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		update_option( 'wcdp_contribution_title', 'Vaš dar za drveće' );
		update_option( 'wcdp_lb_title', '{firstname} je darovao {amount}' );
		update_option( 'wcdp_main_color', '#00753a' );

		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'wc-donation-platform-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Vaš dar za drveće', '{firstname} je darovao {amount}' ), array_keys( $units ) );
		$this->assertSame( 'Title of the amount', $units['Vaš dar za drveće']['place'] );
		$this->translate( $units['Vaš dar za drveće']['o'], $units['Vaš dar za drveće']['p'], 'Vaš dar za drveće', 'de', 'Deine Spende für die Bäume' );

		$this->show_in( 'de' );
		$this->assertSame( 'Deine Spende für die Bäume', get_option( 'wcdp_contribution_title' ) );
		$this->reset_state();
		$this->assertSame( 'Vaš dar za drveće', get_option( 'wcdp_contribution_title' ) );

		$machine = $class->keepPlaceholders( array( 'a' => array( 'text' => '{firstname} je darovao {amount}' ) ) );
		$this->assertSame( array( '{firstname}', '{amount}' ), $machine['a']['keep'] );
	}
}
