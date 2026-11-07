<?php
/**
 * Tests of the language of members.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Templates;

/**
 * A member's language is kept when they register from a page in a language, and what is written to
 * them later is written in it, with the addresses of the site's pages in it; what is written to the
 * site's owner leads to the original.
 */
class MembersTest extends TestCase {

	/**
	 * Languages in folders, with pretty permalinks.
	 */
	public function set_up() {
		parent::set_up();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
	}

	/**
	 * Puts the folder of the page back as at the start of a request.
	 */
	public function tear_down() {
		swiftling_keep_folder( '' );
		parent::tear_down();
	}

	/**
	 * A user registered from a page in a language keeps it; one registered in the original keeps none,
	 * and is written to in the language of the page they act on, unless the reader may be the site's
	 * owner. A language the site no longer has is the original.
	 */
	public function test_language_kept_at_registration() {
		$this->show_in( 'de' );
		$member = self::factory()->user->create();
		$this->reset_state();
		$other = self::factory()->user->create();

		$this->assertSame( 'de', get_user_meta( $member, SWIFTLING_MEMBER_LANGUAGE, true ) );
		$this->assertSame( '', get_user_meta( $other, SWIFTLING_MEMBER_LANGUAGE, true ) );
		$this->assertSame( 'de', swiftling_member_language( $member ) );
		$this->assertSame( 'de', swiftling_member_language( get_userdata( $member )->user_email ) );
		$this->assertSame( '', swiftling_member_language( $other ) );

		$this->show_in( 'de' );
		$this->assertSame( 'de', swiftling_member_language( $other ), 'On a page in the language' );
		$this->assertSame( '', swiftling_member_language( $other, false ), 'Maybe the owner' );
		$this->assertSame( '', get_user_meta( $other, 'locale', true ), 'The language of the admin screens is left alone' );

		update_user_meta( $member, SWIFTLING_MEMBER_LANGUAGE, 'xx' );
		$this->reset_state();
		$this->assertSame( '', swiftling_member_language( $member ) );
	}

	/**
	 * What is written in a language outside its page, as an email from the admin, leads to the pages in
	 * that language, with their folder; what is written in the original from a page in a language leads
	 * to the original. After it, the request builds addresses as before.
	 */
	public function test_addresses_follow_what_is_written() {
		$site = untrailingslashit( get_option( 'home' ) );
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'prijava',
			)
		);
		swiftling_save_slug( 'post:' . $page, 'de_DE', 'anmeldung' );

		// The admin, a scheduled task: no folder of its own.
		swiftling_switch_translation( 'de' );
		$this->assertSame( $site . '/de/anmeldung/', get_permalink( $page ) );
		swiftling_resume_translation();
		$this->assertSame( $site . '/prijava/', get_permalink( $page ) );

		// A page in the language, writing to the site's owner.
		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		$this->assertSame( $site . '/de/anmeldung/', get_permalink( $page ) );
		swiftling_pause_translation();
		$this->assertSame( $site . '/prijava/', get_permalink( $page ) );
		$this->assertSame( $site . '/', home_url( '/' ) );
		swiftling_resume_translation();
		$this->assertSame( $site . '/de/anmeldung/', get_permalink( $page ) );
	}

	/**
	 * The addresses of the site's pages in a text are put in a language, or in the original; files, the
	 * admin and other sites stay as they are.
	 */
	public function test_addresses_in_a_text() {
		$site = untrailingslashit( get_option( 'home' ) );
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'prijava',
			)
		);
		swiftling_save_slug( 'post:' . $page, 'de_DE', 'anmeldung' );
		$text = 'Prijava: ' . $site . '/prijava/ ili <a href="' . $site . '/?act=da">ovdje</a>, ' . $site . '/wp-admin/ ' . $site . '/wp-content/a.png https://example.com/prijava/';

		$this->assertSame( 'Prijava: ' . $site . '/de/anmeldung/ ili <a href="' . $site . '/de/?act=da">ovdje</a>, ' . $site . '/wp-admin/ ' . $site . '/wp-content/a.png https://example.com/prijava/', swiftling_addresses_in( $text, 'de' ) );
		$this->assertSame( $site . '/prijava/', swiftling_addresses_in( $site . '/de/anmeldung/', '' ) );
	}

	/**
	 * A text written from a template is written again from the template's translation with what the
	 * plugin filled in, once for a placeholder used twice; a text from another template is not.
	 */
	public function test_refill() {
		$placeholder = '/\{[a-z_]+\}/i';
		$this->assertSame( 'Hallo Ana, Ana!', Templates::refill( 'Bok {name}, {name}!', 'Hallo {name}, {name}!', 'Bok Ana, Ana!', $placeholder ) );
		$this->assertSame( 'Am 5. Mai: Massage', Templates::refill( '{service}, {date}', 'Am {date}: {service}', 'Massage, 5. Mai', $placeholder ) );
		$this->assertNull( Templates::refill( 'Bok {name}!', 'Hallo {name}!', 'Pozdrav Ana!', $placeholder ) );
		$this->assertNull( Templates::refill( 'Bok {name}, {name}!', 'Hallo {name}, {name}!', 'Bok Ana, Iva!', $placeholder ), 'One placeholder, two values' );
		$this->assertSame( 'Link: X/de', Templates::refill( 'Poveznica: {link}', 'Link: {link}', 'Poveznica: X', $placeholder, static fn ( $value ) => $value . '/de' ) );
		$this->assertSame( 'Bok X/de', Templates::refill( 'Bok {link}', 'Bok {link}', 'Bok X', $placeholder, static fn ( $value ) => $value . '/de' ), 'An untranslated template, its values changed' );

		$items = Templates::keepPlaceholders(
			array(
				'a' => array(
					'text' => 'Bok {name}, {name}',
					'keep' => array( 'X' ),
				),
			),
			$placeholder
		);
		$this->assertSame( array( 'X', '{name}' ), $items['a']['keep'] );
	}

	/**
	 * The classes of plugins keep the placeholders of their templates only through Templates, so a fix
	 * reaches them all.
	 */
	public function test_placeholders_kept_in_one_place() {
		foreach ( (array) glob( dirname( __DIR__ ) . '/src/Builders/*.php' ) as $file ) {
			if ( 'Templates.php' === basename( $file ) ) {
				continue;
			}
			$code = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin itself.
			$this->assertStringNotContainsString( "items[ \$key ]['keep']", $code, basename( $file ) . ' keeps placeholders with Templates::keepPlaceholders()' );
			$this->assertStringNotContainsString( '(?P=', $code, basename( $file ) . ' refills with Templates::refill()' );
		}
	}
}
