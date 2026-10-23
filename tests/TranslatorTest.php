<?php
/**
 * Tests of the right to translate, apart from managing the site.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;

/**
 * A translator, whom another plugin names through swiftling_can_translate, translates every text
 * in every language, also of what they may not edit, and sees the Translations screens; the
 * settings stay the site manager's. A role given the capability by a role editor is no translator.
 * The site manager translates as before.
 */
class TranslatorTest extends TestCase {

	/**
	 * Makes a subscriber who may translate, as SwiftLing Pro's Translator role would.
	 *
	 * @return int User ID.
	 */
	private function translator() {
		add_filter(
			'swiftling_can_translate',
			static function ( $can, $user ) {
				return $can || in_array( 'subscriber', (array) $user->roles, true );
			},
			10,
			2
		);
		return $this->become( 'subscriber' );
	}

	/**
	 * Registers the menu as WordPress does, and returns its items: slug => name.
	 *
	 * @return array<string, string>
	 */
	private function menu_items() {
		global $submenu;
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh admin menu.
		$menu    = new Menu();
		$menu->registerMenu();
		$menu->hideScreens();
		return isset( $submenu['swiftling'] ) ? array_combine( wp_list_pluck( $submenu['swiftling'], 2 ), wp_list_pluck( $submenu['swiftling'], 0 ) ) : array();
	}

	/**
	 * Whoever manages the site may translate; another user only when a plugin says so, not because
	 * their role has the capability saved.
	 */
	public function test_the_right_to_translate() {
		$id = $this->become( 'subscriber' );
		$this->assertFalse( current_user_can( SWIFTLING_TRANSLATE ) );

		get_role( 'subscriber' )->add_cap( SWIFTLING_TRANSLATE );
		wp_set_current_user( 0 );
		wp_set_current_user( $id );
		$this->assertFalse( current_user_can( SWIFTLING_TRANSLATE ), 'A role editor alone gives no right' );
		get_role( 'subscriber' )->remove_cap( SWIFTLING_TRANSLATE );

		$this->translator();
		$this->assertTrue( current_user_can( SWIFTLING_TRANSLATE ) );
		$this->assertTrue( swiftling_is_translator() );

		$this->become( 'administrator' );
		$this->assertTrue( current_user_can( SWIFTLING_TRANSLATE ) );
		$this->assertFalse( swiftling_is_translator(), 'Who manages the site translates as before' );
	}

	/**
	 * A translator translates every text, also of what they may not edit: a page of another, a list
	 * of terms, the site's texts. A subscriber who may not translate translates nothing.
	 */
	public function test_translator_translates_everything() {
		$author = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = self::factory()->post->create( array( 'post_author' => $author ) );

		$this->become( 'subscriber' );
		$this->assertFalse( swiftling_can_translate_subject( swiftling_subject( 'post:' . $post ) ) );

		$this->translator();
		$this->assertFalse( current_user_can( 'edit_post', $post ) );
		$this->assertTrue( swiftling_can_translate_subject( swiftling_subject( 'post:' . $post ) ) );
		$this->assertTrue( swiftling_can_translate_subject( swiftling_subject( 'terms:category' ) ) );
		$this->assertTrue( swiftling_can_translate_object( 'post:' . $post ) );
		$this->assertTrue( swiftling_can_translate_object( 'options' ) );
		$this->assertTrue( swiftling_can_translate_object( 'gettext:twentytwentyfive' ) );

		$request            = new \WP_REST_Request( 'POST', '/swiftling/v1/translations' );
		$request['subject'] = 'post:' . $post;
		$this->assertTrue( swiftling_rest_can_edit( $request ) );
	}

	/**
	 * The menu and the list beside a screen show a translator the Translations screens alone, without
	 * the offer to ask for support; the settings screens stay closed.
	 */
	public function test_translator_sees_the_translations() {
		$this->translator();
		$items = $this->menu_items();
		$this->assertArrayHasKey( 'swiftling-content', $items );
		foreach ( array( 'swiftling', 'swiftling-languages', 'swiftling-translate-all', 'swiftling-log' ) as $slug ) {
			$this->assertArrayNotHasKey( $slug, $items, $slug );
		}
		$screens = Menu::screens();
		$this->assertTrue( current_user_can( Menu::capability( $screens['swiftling-translation'] ) ), 'The editor' );
		$this->assertFalse( current_user_can( Menu::capability( $screens['swiftling-settings'] ) ) );

		$html = $this->render_screen( 'content' );
		$this->assertStringContainsString( 'page=swiftling-terms', $html );
		$this->assertStringNotContainsString( 'page=swiftling-request', $html, 'No offer to ask for support' );

		$this->become( 'administrator' );
		$this->assertArrayHasKey( 'swiftling-languages', $this->menu_items(), 'Who manages the site sees everything' );
	}

	/**
	 * A translator translates the texts of themes and plugins found so far, but does not find them:
	 * recording and scanning are the site manager's, as is saving a setting.
	 */
	public function test_settings_stay_the_managers() {
		$this->set_languages();
		$this->translator();
		$html = $this->render_screen( 'themes-plugins' );
		$this->assertStringNotContainsString( 'swiftling-find-title', $html );

		$_GET = array(
			'package' => 'theme/' . get_stylesheet(),
			'view'    => 'choose',
		);
		$this->assertStringContainsString( 'Only whoever manages the site scans themes and plugins', $this->render_screen( 'themes-plugins' ) );
		$_GET = array();

		$this->post_form( 'swiftling_save_settings', array() );
		$this->expectException( \WPDieException::class );
		swiftling_save_settings();
	}

	/**
	 * Links to screens a translator may not open, as the settings, are left out of buttons and
	 * notices; links anywhere else stay. Whoever manages the site sees them all.
	 */
	public function test_links_to_closed_screens_are_left_out() {
		$this->translator();
		$this->assertTrue( \SwiftLing\Admin\Ui::opens( admin_url( 'admin.php?page=swiftling-content' ) ) );
		$this->assertFalse( \SwiftLing\Admin\Ui::opens( admin_url( 'admin.php?page=swiftling-addresses&lang=de' ) ) );
		$this->assertFalse( \SwiftLing\Admin\Ui::opens( admin_url( 'admin.php?page=swiftling' ) ) );
		$this->assertTrue( \SwiftLing\Admin\Ui::opens( home_url( '/kontakt/' ) ) );

		ob_start();
		\SwiftLing\Admin\Ui::button( 'Set up', 'secondary', admin_url( 'admin.php?page=swiftling-service' ) );
		\SwiftLing\Admin\Ui::notice(
			'info',
			array(
				'text' => 'Pick one.',
				'link' => array( 'Switcher', admin_url( 'admin.php?page=swiftling-switcher' ) ),
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'swiftling-service', $html );
		$this->assertStringNotContainsString( 'swiftling-switcher', $html );
		$this->assertStringContainsString( 'Pick one.', $html );

		$this->become( 'administrator' );
		$this->assertTrue( \SwiftLing\Admin\Ui::opens( admin_url( 'admin.php?page=swiftling-addresses' ) ) );
	}

	/**
	 * A translator machine translates unless the site's manager says no: then the machine route
	 * refuses them, while whoever manages the site still may.
	 */
	public function test_translator_machine_translation_can_be_off() {
		$post = self::factory()->post->create();
		$this->translator();
		$this->assertTrue( swiftling_can_machine_translate(), 'By default they may' );
		add_filter( 'swiftling_translator_machine', '__return_false' );
		$this->assertFalse( swiftling_can_machine_translate() );

		$request = new \WP_REST_Request( 'POST', '/swiftling/v1/machine' );
		$request->set_body_params(
			array(
				'subject' => 'post:' . $post,
				'lang'    => 'de',
				'items'   => array(),
			)
		);
		$this->set_languages();
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		$this->become( 'administrator' );
		$this->assertTrue( swiftling_can_machine_translate(), 'Whoever manages the site always may' );
		$this->assertNotSame( 403, rest_do_request( $request )->get_status() );
	}
}
