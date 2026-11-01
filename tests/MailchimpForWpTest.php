<?php
/**
 * Tests of the class of Mailchimp for WordPress.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Forms\MailchimpForWp;

/**
 * What SwiftLing translates of Mailchimp for WordPress: the form itself, text by text, where the
 * plugin prints it, its messages, read as meta, under the plugin's own right to edit forms, and the
 * language Mailchimp is told a visitor subscribed in.
 */
class MailchimpForWpTest extends TestCase {

	/**
	 * The form of the tests: a label, a placeholder, a hidden field and a button.
	 *
	 * @var int
	 */
	private $form;

	/**
	 * Registers the plugin's type of post as the plugin does, with rights of its own, and makes a form
	 * with a message.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type(
			'mc4wp-form',
			array(
				'public'          => false,
				'capability_type' => 'mc4wp-form',
				'map_meta_cap'    => true,
			)
		);
		self::reset_static( Registry::class, 'active' );
		Registry::get( 'mc4wp' )->register();
		// As the plugin's screen saves a form, with its fields, which WordPress's filter of HTML would drop.
		kses_remove_filters();
		$this->form = self::factory()->post->create(
			array(
				'post_type'    => 'mc4wp-form',
				'post_title'   => 'Vrt',
				'post_content' => '<p><label>Email adresa:<input type="email" name="EMAIL" placeholder="Vaša adresa" required /></label></p><input type="hidden" name="IZVOR" value="Naslovnica" /><p><input type="submit" value="Prijava" /></p>',
			)
		);
		kses_init_filters();
		update_post_meta( $this->form, 'text_subscribed', 'Hvala na prijavi!' );
	}

	/**
	 * Gives the registry back its own builders' state.
	 */
	public function tear_down() {
		unregister_post_type( 'mc4wp-form' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Returns the texts the subject of the form offers, by original.
	 *
	 * @return array<string, array>
	 */
	private function units() {
		$units = array();
		foreach ( swiftling_save_field_list( swiftling_subject( 'mc4wp-form:' . $this->form ) )['units'] as $unit ) {
			$units[ $unit['v'] ] = $unit;
		}
		return $units;
	}

	/**
	 * Prints the form as the plugin does: its content handed to the plugin's filter.
	 */
	private function printed() {
		$form = (object) array( 'ID' => $this->form );
		return apply_filters( 'mc4wp_form_content', get_post( $this->form )->post_content, $form, null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
	}

	/**
	 * The subject of a form offers each text of the form, the label, the placeholder and the button, and
	 * its messages; not the markup nor the value of a field, which Mailchimp reads. Translated, the form
	 * is printed in the language of the page, its markup as it was; the original keeps it.
	 */
	public function test_form_text_by_text() {
		$units = $this->units();
		foreach ( array( 'Email adresa:', 'Vaša adresa', 'Prijava', 'Hvala na prijavi!' ) as $text ) {
			$this->assertArrayHasKey( $text, $units, $text );
		}
		$this->assertArrayNotHasKey( 'Naslovnica', $units, 'The value of a field' );
		$this->assertCount( 4, $units );
		$this->assertSame( 'Form', Registry::fieldLabel( $units['Prijava']['o'], $units['Prijava']['p'] ) );
		$this->assertSame( 'Message: subscribed', Registry::fieldLabel( $units['Hvala na prijavi!']['o'], $units['Hvala na prijavi!']['p'] ) );

		foreach ( array(
			'Email adresa:'     => 'E-Mail-Adresse:',
			'Vaša adresa'       => 'Ihre Adresse',
			'Prijava'           => 'Anmelden',
			'Hvala na prijavi!' => 'Danke für die Anmeldung!',
		) as $original => $text ) {
			$this->translate( $units[ $original ]['o'], $units[ $original ]['p'], $original, 'de', $text );
		}
		$this->show_in( 'de' );
		$this->assertSame( '<p><label>E-Mail-Adresse:<input type="email" name="EMAIL" placeholder="Ihre Adresse" required /></label></p><input type="hidden" name="IZVOR" value="Naslovnica" /><p><input type="submit" value="Anmelden" /></p>', $this->printed() );
		$this->assertSame( 'Danke für die Anmeldung!', get_post_meta( $this->form )['text_subscribed'][0], 'The plugin reads every meta at once' );

		$this->reset_state();
		$this->assertSame( get_post( $this->form )->post_content, $this->printed(), 'The original' );
	}

	/**
	 * The plugin guards its forms with a right of its own, which WordPress's right to edit posts does not
	 * grant even an administrator: the forms are translated by who may manage the plugin.
	 */
	public function test_translated_under_the_plugin_right() {
		$this->become( 'administrator' );
		$this->assertFalse( current_user_can( 'edit_post', $this->form ), 'As on a site with the plugin' );
		$this->assertTrue( swiftling_can_translate_object( 'post:' . $this->form ) );
		$this->assertSame( 'manage_options', swiftling_subject( 'mc4wp-form:' . $this->form )['cap'] );

		$this->become( 'editor' );
		$this->assertFalse( swiftling_can_translate_object( 'post:' . $this->form ) );
		$post = self::factory()->post->create();
		$this->assertTrue( swiftling_can_translate_object( 'post:' . $post ), 'Another post, as WordPress decides' );
	}

	/**
	 * Mailchimp is told the language a visitor subscribed in, from a page in a language, unless the
	 * form names one; from the original, nothing.
	 */
	public function test_language_told_to_mailchimp() {
		$data = array( 'EMAIL' => 'ana@example.org' );
		$this->assertSame( $data, apply_filters( 'mc4wp_form_data', $data, null ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
		$this->show_in( 'de' );
		$this->assertSame( 'de_DE', apply_filters( 'mc4wp_form_data', $data, null )['MC_LANGUAGE'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
		$this->assertSame( 'fr', apply_filters( 'mc4wp_form_data', $data + array( 'MC_LANGUAGE' => 'fr' ), null )['MC_LANGUAGE'], 'The form names one' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
	}

	/**
	 * The plugin's forms are on the Forms screen, with the pages that show them by the shortcode.
	 */
	public function test_listed_on_the_forms_screen() {
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[mc4wp_form id="' . $this->form . '"]',
			)
		);
		$forms = Registry::forms()['mc4wp']->forms();
		$this->assertSame( 'mc4wp-form:' . $this->form, $forms[0]['key'] );
		$this->assertSame( 1, $forms[0]['pages'] );
		$this->assertInstanceOf( MailchimpForWp::class, Registry::get( 'mc4wp' ) );
	}
}
