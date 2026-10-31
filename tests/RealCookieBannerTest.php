<?php
/**
 * Tests of the class of Real Cookie Banner.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\RealCookieBanner;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeRealCookieBanner.php';

/**
 * What SwiftLing translates of Real Cookie Banner: the texts of its settings, each an option of its
 * own, its services, content blockers and links, posts visitors never open, and its service groups.
 */
class RealCookieBannerTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeRealCookieBanner
	 */
	private $rcb;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin, with texts of its
	 * settings and a service in a group.
	 */
	public function set_up() {
		parent::set_up();
		FakeRealCookieBanner::install();
		$this->rcb = new FakeRealCookieBanner();
		$builders  = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof RealCookieBanner ? $this->rcb : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->rcb->register();

		update_option( 'rcb-banner-texts-headline', 'Postavke privatnosti' );
		update_option( 'rcb-banner-texts-description', 'Koristimo kolačiće. {{privacyPolicy}}Pravila privatnosti{{/privacyPolicy}}' );
		update_option( 'rcb-cookie-policy-instruction', 'Promijenite odluku ovdje: [rcb-consent type="change" tag="a" text="Promijeni postavke"]' );
		update_option( 'rcb-cookie-policy-id', '12' );
		update_option( 'rcb-operator-contact-address', 'Mlinska 12, Varaždin' );
		update_option( 'rcb-banner-position', 'bottom' );
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
	 * Makes a service of the plugin in a group.
	 *
	 * @return array{service: int, group: int}
	 */
	private function service() {
		$group   = self::factory()->term->create(
			array(
				'taxonomy'    => RealCookieBanner::GROUPS,
				'name'        => 'Nužni',
				'description' => 'Nužne usluge potrebne su za rad stranice.',
			)
		);
		$service = self::factory()->post->create(
			array(
				'post_type'    => 'rcb-cookie',
				'post_title'   => 'Google Analytics',
				'post_content' => 'Usluga za statistiku posjeta.',
			)
		);
		wp_set_object_terms( $service, array( $group ), RealCookieBanner::GROUPS );
		update_post_meta( $service, 'provider', 'Google Ireland Limited' );
		update_post_meta( $service, 'technicalDefinitions', '[{"name":"_ga","duration":2}]' );
		update_post_meta( $service, 'codeOptIn', '<script>gtag("config", "G-1");</script>' );
		return array(
			'service' => $service,
			'group'   => $group,
		);
	}

	/**
	 * Returns the plugin's services, as it asks for them: with the filters of a query.
	 *
	 * @return \WP_Post[]
	 */
	private static function services() {
		return get_posts(
			array(
				'post_type'        => 'rcb-cookie',
				'numberposts'      => -1,
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Returns the units of the subject of the plugin's settings, by original, stored as the editor
	 * stores them.
	 *
	 * @return array<string, array>
	 */
	private function units() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'real-cookie-banner-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		return $units;
	}

	/**
	 * The subject of the plugin's settings offers the texts of its options of texts, the texts of the
	 * shortcode in them a visitor reads, the operator's address, and the names, descriptions and
	 * provider of a service and its group. No setting, page, name of a cookie or code.
	 */
	public function test_settings_and_services_offered() {
		$ids   = $this->service();
		$units = $this->units();
		foreach ( array( 'Postavke privatnosti', 'Promijeni postavke', 'Mlinska 12, Varaždin', 'Nužni', 'Nužne usluge potrebne su za rad stranice.', 'Google Analytics', 'Usluga za statistiku posjeta.', 'Google Ireland Limited' ) as $text ) {
			$this->assertArrayHasKey( $text, $units, $text );
		}
		foreach ( array( '12', 'bottom', 'change', 'a' ) as $setting ) {
			$this->assertArrayNotHasKey( $setting, $units, $setting );
		}
		$this->assertSame( 'Banner texts headline', Registry::fieldLabel( 'options', $units['Postavke privatnosti']['p'] ) );
		$this->assertSame( 'Service: Google Analytics › Name', Registry::fieldLabel( 'post:' . $ids['service'], 'field/post_title' ) );
		$this->assertSame( 'Service group: Nužni › Description', Registry::fieldLabel( 'term:' . $ids['group'], 'field/description' ) );

		$owner = 'post:' . $ids['service'];
		$this->assertTrue( Registry::fieldHoldsText( $owner, 'meta/provider' ) );
		$this->assertFalse( Registry::fieldHoldsText( $owner, 'meta/technicalDefinitions' ) );
		$this->assertFalse( Registry::fieldHoldsText( $owner, 'meta/codeOptIn' ) );
		$this->assertNull( Registry::fieldHoldsText( 'post:' . self::factory()->post->create(), 'meta/provider' ), 'A post of another type' );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/rcb-cookie-policy-id' ) );
		$this->assertNull( Registry::fieldHoldsText( 'options', 'option/rcb-banner-position' ), 'An option of no texts' );
	}

	/**
	 * Translated, the texts of the settings and the service the plugin's query hands out show in the
	 * language; the post as WordPress keeps it and the original page keep their originals.
	 */
	public function test_settings_and_services_translated() {
		$ids   = $this->service();
		$units = $this->units();
		$this->translate( 'options', $units['Postavke privatnosti']['p'], 'Postavke privatnosti', 'de', 'Datenschutz-Einstellungen' );
		$this->translate( 'post:' . $ids['service'], 'field/post_title', 'Google Analytics', 'de', 'Google Analytics (DE)' );
		$this->translate( 'term:' . $ids['group'], 'field/name', 'Nužni', 'de', 'Essenziell' );

		$this->show_in( 'de' );
		$this->assertSame( 'Datenschutz-Einstellungen', get_option( 'rcb-banner-texts-headline' ) );
		$this->assertSame( 'bottom', get_option( 'rcb-banner-position' ) );
		$this->assertSame( 'Google Analytics (DE)', self::services()[0]->post_title );
		$this->assertSame( 'Google Analytics', get_post( $ids['service'] )->post_title, 'The post as WordPress keeps it' );
		$this->assertSame( 'Essenziell', get_term( $ids['group'], RealCookieBanner::GROUPS )->name );

		$this->reset_state();
		$this->assertSame( 'Postavke privatnosti', get_option( 'rcb-banner-texts-headline' ) );
		$this->assertSame( 'Google Analytics', self::services()[0]->post_title, 'The original page' );
	}

	/**
	 * A translation that leaves out a placeholder of its original, where the plugin puts a link or a
	 * list, shows the original; one that keeps them shows. Machine translation keeps them as they are.
	 */
	public function test_placeholders_kept() {
		$units       = $this->units();
		$description = 'Koristimo kolačiće. {{privacyPolicy}}Pravila privatnosti{{/privacyPolicy}}';
		$this->assertArrayHasKey( $description, $units );
		$path = $units[ $description ]['p'];

		$this->translate( 'options', $path, $description, 'de', 'Wir verwenden Cookies. Datenschutzerklärung' );
		$this->show_in( 'de' );
		$this->assertSame( $description, get_option( 'rcb-banner-texts-description' ), 'Without its placeholders' );

		$this->translate( 'options', $path, $description, 'de', 'Wir verwenden Cookies. {{privacyPolicy}}Datenschutzerklärung{{/privacyPolicy}}' );
		$this->show_in( 'de' );
		$this->assertSame( 'Wir verwenden Cookies. {{privacyPolicy}}Datenschutzerklärung{{/privacyPolicy}}', get_option( 'rcb-banner-texts-description' ) );

		$items = apply_filters(
			'swiftling_machine_items',
			array(
				'a' => array(
					'text' => 'Usluge: {{services}} i {{services}}',
					'keep' => array( '%s' ),
				),
				'b' => array( 'text' => 'Bez oznaka' ),
			)
		);
		$this->assertSame( array( '%s', '{{services}}' ), $items['a']['keep'] );
		$this->assertArrayNotHasKey( 'keep', $items['b'] );
	}
}
