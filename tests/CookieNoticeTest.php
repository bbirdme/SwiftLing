<?php
/**
 * Tests of the class of Cookie Notice.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\CookieNotice;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeCookieNotice.php';

/**
 * What SwiftLing translates of Cookie Notice: the texts of its banner, from the copy of its settings
 * the plugin read when it loaded, and a page check that sees the banner the plugin hides from bots.
 */
class CookieNoticeTest extends TestCase {

	/**
	 * The class, with the plugin's main object.
	 *
	 * @var FakeCookieNotice
	 */
	private $cn;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin, with the plugin's
	 * settings.
	 */
	public function set_up() {
		parent::set_up();
		$this->cn = new FakeCookieNotice();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof CookieNotice ? $this->cn : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->cn->register();
		update_option(
			CookieNotice::OPTION,
			array(
				'position'            => 'bottom',
				'message_text'        => 'Koristimo kolačiće kako bismo vam pružili najbolje iskustvo.',
				'accept_text'         => 'U redu',
				'refuse_text'         => 'Ne',
				'revoke_message_text' => 'Možete opozvati pristanak.',
				'revoke_text'         => 'Opozovi pristanak',
				'see_more_opt'        => array(
					'text'      => 'Pravila privatnosti',
					'link_type' => 'custom',
					'id'        => 'empty',
					'link'      => 'https://example.org/privatnost/',
				),
				'colors'              => array( 'text' => '#ffffff' ),
				'css_class'           => 'button',
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
	 * Runs what the plugin's hooks run when the page starts rendering, as WordPress does.
	 */
	private function page_renders() {
		$this->assertSame( 0, has_action( 'template_redirect', array( $this->cn, 'translateSettings' ) ) );
		$this->cn->translateSettings();
	}

	/**
	 * The subject of the plugin's settings offers the texts of the banner, named, and no colour,
	 * position or link. Translated, they show in the language in the plugin's copy of its settings,
	 * which it read before SwiftLing started; the original page keeps them as stored.
	 */
	public function test_banner_texts_translated_in_the_plugins_copy() {
		$subject = swiftling_subject( 'cookie-notice-settings' );
		$this->assertSame( 'fields', $subject['kind'] );
		$this->assertSame( 'Cookie Notice settings', $subject['title'] );
		$this->assertSame( 'manage_options', $subject['cap'] );
		$this->assertSame( 'cookie-notice-settings', Registry::subjects()['Cookie Notice'][0]['subjects'][0]['key'], 'On the Templates screen' );

		$places = array();
		foreach ( swiftling_save_field_list( $subject )['units'] as $unit ) {
			$places[ $unit['v'] ] = Registry::fieldLabel( $unit['o'], $unit['p'] );
		}
		$this->assertSame(
			array(
				'Koristimo kolačiće kako bismo vam pružili najbolje iskustvo.' => 'Message',
				'U redu'                     => 'Accept button',
				'Ne'                         => 'Refuse button',
				'Možete opozvati pristanak.' => 'Message to revoke consent',
				'Opozovi pristanak'          => 'Revoke button',
				'Pravila privatnosti'        => 'Privacy policy link',
			),
			$places
		);
		$this->assertTrue( Registry::fieldHoldsText( 'options', 'option/' . CookieNotice::OPTION . '/see_more_opt/text' ) );
		$this->assertFalse( Registry::fieldHoldsText( 'options', 'option/' . CookieNotice::OPTION . '/css_class' ) );
		$this->assertNull( Registry::fieldHoldsText( 'options', 'option/' . CookieNotice::OPTION . '/see_more_opt/link' ), 'An address, left to SwiftLing' );

		$path = 'option/' . CookieNotice::OPTION;
		$this->translate( 'options', $path . '/message_text', 'Koristimo kolačiće kako bismo vam pružili najbolje iskustvo.', 'de', 'Wir verwenden Cookies.' );
		$this->translate( 'options', $path . '/see_more_opt/text', 'Pravila privatnosti', 'de', 'Datenschutzerklärung' );

		// The plugin reads its settings when it loads, before SwiftLing starts.
		$this->cn->load();
		$this->show_in( 'de' );
		$this->page_renders();
		$general = $this->cn->main->options['general'];
		$this->assertSame( 'Wir verwenden Cookies.', $general['message_text'] );
		$this->assertSame( 'Datenschutzerklärung', $general['see_more_opt']['text'] );
		$this->assertSame( 'U redu', $general['accept_text'], 'Not translated yet' );
		$this->assertSame( 'https://example.org/privatnost/', $general['see_more_opt']['link'] );
		$this->assertSame( '#ffffff', $general['colors']['text'] );

		$this->reset_state();
		$this->cn->load();
		$this->page_renders();
		$this->assertSame( 'Koristimo kolačiće kako bismo vam pružili najbolje iskustvo.', $this->cn->main->options['general']['message_text'], 'The original page' );
	}

	/**
	 * A page check marks the texts of the plugin's copy, under the paths the subject of the settings
	 * lists them with, and counts as no bot, so it sees the banner; a visitor the plugin takes for a
	 * bot stays one.
	 */
	public function test_page_check_marks_the_copy_and_sees_the_banner() {
		$listed = array();
		foreach ( Registry::listTexts( swiftling_subject( 'cookie-notice-settings' ) ) as $text ) {
			$listed[ $text['v'] ] = $text['p'];
		}
		$this->cn->load();
		$seen   = array();
		$leaves = $this->probe(
			function () use ( &$seen ) {
				// The server's request of a page check, see swiftling_maybe_start_probe().
				$state          = &swiftling_state();
				$state['probe'] = -1;
				$this->page_renders();
				$seen = array(
					'message' => $this->cn->main->options['general']['message_text'],
					'link'    => $this->cn->main->options['general']['see_more_opt']['link'],
					'bot'     => apply_filters( 'cn_is_bot', true ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
				);
			}
		);
		$this->assertNotSame( 'Koristimo kolačiće kako bismo vam pružili najbolje iskustvo.', $seen['message'], 'Marked' );
		$this->assertSame( 'https://example.org/privatnost/', $seen['link'] );
		$this->assertFalse( $seen['bot'] );
		$marked = array();
		foreach ( $leaves as $leaf ) {
			$marked[ $leaf['v'] ] = $leaf['p'];
		}
		foreach ( array( 'Koristimo kolačiće kako bismo vam pružili najbolje iskustvo.', 'Pravila privatnosti', 'U redu' ) as $text ) {
			$this->assertSame( $listed[ $text ], $marked[ $text ] ?? null, $text );
		}

		$this->assertTrue( apply_filters( 'cn_is_bot', true ), 'A bot outside a page check' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
	}
}
