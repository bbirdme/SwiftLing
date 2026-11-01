<?php
/**
 * Tests of the class of Newsletter.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Newsletter;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeNewsletter.php';

/**
 * What SwiftLing translates of Newsletter: the texts of its settings, the languages it is told, the
 * language it switches to for a subscriber, and the page a form was sent from.
 */
class NewsletterTest extends TestCase {

	/**
	 * The class.
	 *
	 * @var FakeNewsletter
	 */
	private $newsletter;

	/**
	 * Puts the fake in the registry and hooks it, with texts and settings of the plugin.
	 */
	public function set_up() {
		parent::set_up();
		$this->newsletter = new FakeNewsletter();
		$builders         = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Newsletter ? $this->newsletter : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->newsletter->register();
		update_option(
			'newsletter_form',
			array(
				'subscribe'   => 'Pretplati se',
				'name_status' => '1',
				'privacy_url' => 'http://example.org/privatnost/',
			)
		);
		update_option(
			'newsletter_subscription',
			array(
				'confirmed_subject' => 'Dobro došli, {name}',
				'noconfirmation'    => '1',
				'notify_email'      => 'vrt@example.org',
			)
		);
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
	 * The subject of the settings offers the texts the owner wrote, not settings or addresses; in a
	 * language the plugin reads them translated, in the original as written.
	 */
	public function test_texts_of_the_settings() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'newsletter-settings' ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		$this->assertSame( array( 'Pretplati se', 'Dobro došli, {name}' ), array_keys( $units ) );
		$this->assertSame( 'Form › Subscribe', Registry::fieldLabel( $units['Pretplati se']['o'], $units['Pretplati se']['p'] ) );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/newsletter_subscription/notify_email' ) );
		$this->assertNull( Registry::fieldHoldsText( 'options', 'option/newsletter_form/privacy_url' ), 'An address, as any' );

		$this->translate( $units['Pretplati se']['o'], $units['Pretplati se']['p'], 'Pretplati se', 'de', 'Abonnieren' );
		$this->show_in( 'de' );
		$this->assertSame( 'Abonnieren', get_option( 'newsletter_form' )['subscribe'] );
		$this->reset_state();
		$this->assertSame( 'Pretplati se', get_option( 'newsletter_form' )['subscribe'] );

		$items = apply_filters( 'swiftling_machine_items', array( 'a' => array( 'text' => 'Dobro došli, {name}' ) ) );
		$this->assertSame( array( '{name}' ), $items['a']['keep'] );
	}

	/**
	 * The plugin is told the site's languages and the language of the request: the original is its
	 * "all languages".
	 */
	public function test_languages_told_to_the_plugin() {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filters, as it asks them.
		$this->assertTrue( apply_filters( 'newsletter_is_multilanguage', false ) );
		set_current_screen( 'newsletter_main_main' );
		$this->assertFalse( apply_filters( 'newsletter_is_multilanguage', false ), 'The plugin\'s own screens: one set of settings' );
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertTrue( apply_filters( 'newsletter_is_multilanguage', false ), 'A visitor subscribing through admin-ajax.php' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		set_current_screen( 'front' );
		$this->assertSame( array( 'de' => 'Deutsch' ), array_intersect_key( apply_filters( 'newsletter_languages', array() ), array( 'de' => 1 ) ) );
		$this->assertSame( '', apply_filters( 'newsletter_current_language', '' ) );
		$this->show_in( 'de' );
		$this->assertSame( 'de', apply_filters( 'newsletter_current_language', '' ) );
		// phpcs:enable
	}

	/**
	 * When the plugin switches to a subscriber's language, as for an email or a link of one that arrives
	 * with no page in a language, the request is in that language until the plugin puts its own back.
	 * Another plugin running the same action changes nothing.
	 */
	public function test_switched_with_the_plugin() {
		$module = new \NewsletterModuleBase();
		$module->switch_language( 'de' );
		$this->assertSame( 'translate:de', swiftling_mode() . ':' . swiftling_state()['language'] );
		$module->restore_language();
		$this->assertSame( '', swiftling_mode() );

		do_action( 'wpml_switch_language', 'de' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Another plugin's call of the action.
		$this->assertSame( '', swiftling_mode(), 'Not the plugin' );

		// The plugin putting back a language it did not switch leaves what other code switched as it is.
		$this->show_in( 'de' );
		swiftling_pause_translation();
		$module->restore_language();
		$this->assertSame( '', swiftling_mode(), 'Still paused by the other code' );
		swiftling_resume_translation();
		$this->assertSame( 'translate', swiftling_mode() );
	}

	/**
	 * The plugin's page against bots sends the form again from admin-ajax.php: the page the form names
	 * is the one it was sent from. A link of an email lands, in a language the plugin switched to, at
	 * that language's address.
	 */
	public function test_page_of_a_form_and_of_a_link() {
		$_REQUEST = array(
			'action' => 'tnp',
			'nhr'    => 'http://example.org/novosti/?lang=de',
		);
		$this->assertSame( 'http://example.org/novosti/?lang=de', Registry::sentFrom( 'http://example.org/wp-admin/admin-ajax.php?action=tnp&na=s' ) );
		$_REQUEST['action'] = 'other';
		$this->assertSame( 'http://example.org/wp-admin/admin-ajax.php', Registry::sentFrom( 'http://example.org/wp-admin/admin-ajax.php' ), 'Another action' );
		$_REQUEST = array();
		$this->assertContains( 'tnp', $this->newsletter->sendingActions() );

		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		$this->assertSame( 'http://example.org/newsletter/', apply_filters( 'newsletter_message_url', 'http://example.org/newsletter/' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->show_in( 'de' );
		$this->assertSame( 'http://example.org/de/newsletter/', apply_filters( 'newsletter_message_url', 'http://example.org/newsletter/' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
	}
}
