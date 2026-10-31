<?php
/**
 * Tests of the class of GDPR Cookie Compliance.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\GdprCookieCompliance;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeGdprCookieCompliance.php';

/**
 * What SwiftLing translates of GDPR Cookie Compliance: the texts changed in its settings, an option
 * the plugin hands out itself from a table of its own.
 */
class GdprCookieComplianceTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeGdprCookieCompliance
	 */
	private $moove;

	/**
	 * The plugin's settings, as its table keeps them.
	 *
	 * @var array
	 */
	private $settings = array(
		'moove_gdpr_info_bar_content'            => '<p>Ova stranica koristi kolačiće.</p>',
		'moove_gdpr_infobar_accept_button_label' => 'Prihvati',
		'moove_gdpr_brand_colour'                => '#0C4DA2',
		'moove_gdpr_infobar_position'            => 'bottom',
		'moove_gdpr_company_logo'                => 'https://example.org/logo.png',
	);

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin, which hands out its
	 * option from its table (99), so WordPress's options table has none.
	 */
	public function set_up() {
		parent::set_up();
		$this->moove = new FakeGdprCookieCompliance();
		$builders    = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof GdprCookieCompliance ? $this->moove : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->moove->register();
		add_filter( 'pre_option_' . GdprCookieCompliance::OPTION, array( $this, 'from_table' ), 99 );
		delete_option( GdprCookieCompliance::OPTION );
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Hands out the plugin's option from its table, as GDPR Cookie Compliance 5.2 does.
	 *
	 * @return array
	 */
	public function from_table() {
		return $this->settings;
	}

	/**
	 * The subject of the plugin's settings offers the texts it hands out, and no colour, choice or
	 * logo. Translated, the option the plugin hands out has them in the language, the rest as stored;
	 * the original page reads the option as stored.
	 */
	public function test_texts_of_the_plugins_table_translated() {
		$subject = swiftling_subject( 'gdpr-cookie-compliance-settings' );
		$this->assertSame( 'GDPR Cookie Compliance settings', $subject['title'] );
		$units = array();
		foreach ( swiftling_save_field_list( $subject )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( '<p>Ova stranica koristi kolačiće.</p>', 'Prihvati' ), array_keys( $units ) );
		$this->assertSame( 'Infobar accept button label', Registry::fieldLabel( 'options', $units['Prihvati']['p'] ) );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/' . GdprCookieCompliance::OPTION . '/moove_gdpr_company_logo' ) );

		$this->translate( 'options', $units['<p>Ova stranica koristi kolačiće.</p>']['p'], '<p>Ova stranica koristi kolačiće.</p>', 'de', '<p>Diese Seite verwendet Cookies.</p>' );
		$this->translate( 'options', $units['Prihvati']['p'], 'Prihvati', 'de', 'Akzeptieren' );

		$this->show_in( 'de' );
		$shown = get_option( GdprCookieCompliance::OPTION );
		$this->assertSame( '<p>Diese Seite verwendet Cookies.</p>', $shown['moove_gdpr_info_bar_content'] );
		$this->assertSame( 'Akzeptieren', $shown['moove_gdpr_infobar_accept_button_label'] );
		$this->assertSame( '#0C4DA2', $shown['moove_gdpr_brand_colour'] );
		$this->assertSame( 'https://example.org/logo.png', $shown['moove_gdpr_company_logo'] );

		$this->reset_state();
		$this->assertSame( $this->settings, get_option( GdprCookieCompliance::OPTION ), 'The original page' );
	}

	/**
	 * A page check marks the texts the plugin hands out once the page renders, not while WordPress
	 * loads, when the plugin reads its settings for itself.
	 */
	public function test_page_check_marks_texts_once_the_page_renders() {
		swiftling_start( 'probe' );
		$this->assertSame( $this->settings, get_option( GdprCookieCompliance::OPTION ), 'Before the page renders' );
		$this->reset_state();

		$seen   = array();
		$leaves = $this->probe(
			static function () use ( &$seen ) {
				$seen = get_option( GdprCookieCompliance::OPTION );
			}
		);
		$this->assertNotSame( 'Prihvati', $seen['moove_gdpr_infobar_accept_button_label'] );
		$this->assertSame( '#0C4DA2', $seen['moove_gdpr_brand_colour'] );
		$this->assertContains( 'Prihvati', wp_list_pluck( $leaves, 'v' ) );
	}
}
