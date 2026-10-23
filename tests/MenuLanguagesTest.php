<?php
/**
 * Tests of the items a classic menu shows in each language.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A menu of a site in Croatian, translated into German and French: Početna, Blog with the category
 * Novosti under it, Kako do nas, and Impressum.
 */
class MenuLanguagesTest extends TestCase {

	/**
	 * Menu term ID.
	 *
	 * @var int
	 */
	private $menu = 0;

	/**
	 * Item IDs by name: home, blog, news, directions, imprint.
	 *
	 * @var array<string, int>
	 */
	private $items = array();

	/**
	 * Sets up the menu, placed in the theme's primary menu.
	 */
	public function set_up() {
		parent::set_up();
		$blog       = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Blog',
			)
		);
		$directions = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kako do nas',
			)
		);
		$news       = self::factory()->category->create( array( 'name' => 'Novosti' ) );

		$this->menu                = wp_create_nav_menu( 'Glavni izbornik' );
		$this->items['home']       = $this->item(
			array(
				'menu-item-type'  => 'custom',
				'menu-item-title' => 'Početna',
				'menu-item-url'   => home_url( '/' ),
			)
		);
		$this->items['blog']       = $this->item(
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $blog,
			)
		);
		$this->items['news']       = $this->item(
			array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $news,
				'menu-item-parent-id' => $this->items['blog'],
			)
		);
		$this->items['directions'] = $this->item(
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $directions,
				'menu-item-title'     => 'Upute za dolazak',
			)
		);
		$this->items['imprint']    = $this->item(
			array(
				'menu-item-type'  => 'custom',
				'menu-item-title' => 'Impressum',
				'menu-item-url'   => home_url( '/impressum/' ),
			)
		);

		register_nav_menus( array( 'primary' => 'Primary Menu' ) );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $this->menu ) );
	}

	/**
	 * Takes away the place of the theme the test registered.
	 */
	public function tear_down() {
		unregister_nav_menu( 'primary' );
		remove_theme_mod( 'nav_menu_locations' );
		parent::tear_down();
	}

	/**
	 * Adds a published item to the menu.
	 *
	 * @param array $data Item data, as wp_update_nav_menu_item() takes it.
	 * @return int Item ID.
	 */
	private function item( $data ) {
		return (int) wp_update_nav_menu_item( $this->menu, 0, $data + array( 'menu-item-status' => 'publish' ) );
	}

	/**
	 * Renders the menu as a theme places it.
	 *
	 * @return string
	 */
	private function render_menu() {
		return (string) wp_nav_menu(
			array(
				'theme_location' => 'primary',
				'echo'           => false,
				'fallback_cb'    => false,
			)
		);
	}

	/**
	 * Every item shows in every language until a choice is made.
	 */
	public function test_every_item_shows_everywhere_at_first() {
		$this->assertStringContainsString( '>Blog</a>', $this->render_menu() );
		$this->assertTrue( swiftling_menu_item_shows( $this->items['blog'], 'hr' ) );
		$this->assertTrue( swiftling_menu_item_shows( $this->items['blog'], 'de_DE' ) );
		$this->show_in( 'de' );
		$this->assertStringContainsString( '>Blog</a>', $this->render_menu() );
		$this->assertStringContainsString( '>Novosti</a>', $this->render_menu() );
	}

	/**
	 * An item left out in a language is left out there with its whole submenu; the original and the
	 * other languages keep it.
	 */
	public function test_left_out_item_takes_its_submenu_along() {
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr', 'fr_FR' ) );

		$original = $this->render_menu();
		$this->assertStringContainsString( '>Blog</a>', $original );
		$this->assertStringContainsString( '>Novosti</a>', $original );

		$this->show_in( 'de' );
		$german = $this->render_menu();
		$this->assertStringNotContainsString( '>Blog</a>', $german );
		// Not moved up to the top level, as WordPress does with an item whose parent is missing.
		$this->assertStringNotContainsString( '>Novosti</a>', $german );
		$this->assertStringContainsString( '>Početna</a>', $german );

		$this->show_in( 'fr' );
		$this->assertStringContainsString( '>Novosti</a>', $this->render_menu() );
	}

	/**
	 * An item for one language only shows there, not in the original.
	 */
	public function test_item_for_one_language() {
		swiftling_save_menu_item_languages( $this->items['imprint'], array( 'de_DE' ) );

		$this->assertStringNotContainsString( 'Impressum', $this->render_menu() );
		$this->show_in( 'de' );
		$this->assertStringContainsString( '>Impressum</a>', $this->render_menu() );
		$this->show_in( 'fr' );
		$this->assertStringNotContainsString( 'Impressum', $this->render_menu() );
	}

	/**
	 * A language added later shows an item when the original does.
	 */
	public function test_new_language_follows_the_original() {
		swiftling_save_menu_item_languages( $this->items['imprint'], array( 'de_DE' ) );
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr' ) );
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => 'it',
			)
		);

		$this->assertFalse( swiftling_menu_item_shows( $this->items['imprint'], 'it_IT' ) );
		$this->assertTrue( swiftling_menu_item_shows( $this->items['blog'], 'it_IT' ) );

		// Saving the menu's choices again names the new language too.
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr' ) );
		$this->assertFalse( swiftling_menu_item_shows( $this->items['blog'], 'it_IT' ) );
	}

	/**
	 * Showing an item in every language again forgets the choice.
	 */
	public function test_every_language_forgets_the_choice() {
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr' ) );
		$this->assertNotSame( '', get_post_meta( $this->items['blog'], SWIFTLING_MENU_LANGUAGES, true ) );
		$this->assertSame( 1, swiftling_menu_differing_count( $this->menu ) );

		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr', 'de_DE', 'fr_FR' ) );
		$this->assertSame( '', get_post_meta( $this->items['blog'], SWIFTLING_MENU_LANGUAGES, true ) );
		$this->assertSame( 0, swiftling_menu_differing_count( $this->menu ) );
	}

	/**
	 * The settings of an item under Appearance → Menus show a tick per language, and saving the
	 * menu saves them. A save from elsewhere, without those fields, leaves the choice alone.
	 */
	public function test_appearance_menus_fields() {
		$this->become( 'administrator' );
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr' ) );

		$item = wp_setup_nav_menu_item( get_post( $this->items['blog'] ) );
		ob_start();
		do_action( 'wp_nav_menu_item_custom_fields', $this->items['blog'], $item, 0, null, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The hook WordPress runs under Appearance → Menus.
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Show in', $html );
		$this->assertMatchesRegularExpression( '/name="swiftling_show_in\[' . $this->items['blog'] . '\]\[\]" value="hr" checked/', $html );
		$this->assertMatchesRegularExpression( '/name="swiftling_show_in\[' . $this->items['blog'] . '\]\[\]" value="de_DE"(?! checked)/', $html );

		$_POST = array(
			'update-nav-menu-nonce'     => wp_create_nonce( 'update-nav_menu' ),
			'swiftling_show_in_present' => array( $this->items['imprint'] => '1' ),
			'swiftling_show_in'         => array( $this->items['imprint'] => array( 'de_DE' ) ),
		);
		wp_update_nav_menu_item(
			$this->menu,
			$this->items['imprint'],
			array(
				'menu-item-type'   => 'custom',
				'menu-item-title'  => 'Impressum',
				'menu-item-url'    => home_url( '/impressum/' ),
				'menu-item-status' => 'publish',
			)
		);
		$this->assertFalse( swiftling_menu_item_shows( $this->items['imprint'], 'hr' ) );
		$this->assertTrue( swiftling_menu_item_shows( $this->items['imprint'], 'de_DE' ) );
		$this->assertTrue( swiftling_purge_queue()['all'] );

		// A save from elsewhere, such as an import, has no SwiftLing fields.
		$_POST = array();
		wp_update_nav_menu_item(
			$this->menu,
			$this->items['blog'],
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => (int) get_post_meta( $this->items['blog'], '_menu_item_object_id', true ),
				'menu-item-status'    => 'publish',
			)
		);
		$this->assertFalse( swiftling_menu_item_shows( $this->items['blog'], 'de_DE' ) );
	}

	/**
	 * Someone who may not edit menus cannot change the choice, even with the fields.
	 */
	public function test_appearance_menus_needs_the_right_to_edit_menus() {
		$this->become( 'author' );
		$_POST = array(
			'update-nav-menu-nonce'     => wp_create_nonce( 'update-nav_menu' ),
			'swiftling_show_in_present' => array( $this->items['imprint'] => '1' ),
			'swiftling_show_in'         => array( $this->items['imprint'] => array( 'de_DE' ) ),
		);
		swiftling_save_menu_item_fields( $this->menu, $this->items['imprint'] );
		$this->assertTrue( swiftling_menu_item_shows( $this->items['imprint'], 'hr' ) );
	}

	/**
	 * The Choose items screen saves every item's ticks. A cell left out with the item above
	 * it sends nothing, and the item keeps its own choice for that language.
	 */
	public function test_items_screen_saves() {
		$this->become( 'administrator' );
		swiftling_save_menu_item_languages( $this->items['news'], array( 'hr', 'de_DE' ) );
		$this->post_form(
			'swiftling_save_menu_items_' . $this->menu,
			array(
				'action' => 'swiftling_save_menu_items',
				'menu'   => (string) $this->menu,
				'items'  => array_map( 'strval', array_values( $this->items ) ),
				'shown'  => array(
					$this->items['home']       => array( 'hr', 'de_DE', 'fr_FR' ),
					$this->items['blog']       => array( 'hr' ),
					// Under Blog, which no longer shows in German or French: those cells send nothing.
					$this->items['news']       => array( 'hr' ),
					$this->items['directions'] => array( 'hr', 'de_DE' ),
				),
			)
		);
		$location = $this->redirect_of( 'swiftling_save_menu_items' );
		$this->assertStringContainsString( 'page=swiftling-menus', $location );
		$this->assertStringContainsString( 'items=' . $this->menu, $location );
		$this->assertStringContainsString( 'swiftling_notice=saved', $location );

		$this->assertTrue( swiftling_menu_item_shows( $this->items['home'], 'fr_FR' ) );
		$this->assertFalse( swiftling_menu_item_shows( $this->items['blog'], 'de_DE' ) );
		$this->assertFalse( swiftling_menu_item_shows( $this->items['directions'], 'fr_FR' ) );
		// No ticks at all: no language.
		$this->assertFalse( swiftling_menu_item_shows( $this->items['imprint'], 'hr' ) );
		$this->assertFalse( swiftling_menu_item_shows( $this->items['imprint'], 'de_DE' ) );
		// Its own choice: German yes, French no.
		$this->assertTrue( swiftling_menu_item_shows( $this->items['news'], 'de_DE' ) );
		$this->assertFalse( swiftling_menu_item_shows( $this->items['news'], 'fr_FR' ) );
		$this->assertTrue( swiftling_purge_queue()['all'] );
	}

	/**
	 * The screen refuses someone who may not edit menus, and a form without its nonce.
	 */
	public function test_items_screen_refuses() {
		$this->become( 'editor' );
		$this->post_form(
			'swiftling_save_menu_items_' . $this->menu,
			array(
				'menu'  => (string) $this->menu,
				'items' => array( (string) $this->items['blog'] ),
				'shown' => array(),
			)
		);
		$this->expectException( \WPDieException::class );
		swiftling_save_menu_items();
	}

	/**
	 * Texts of items left out in a language do not count toward the menu's state there.
	 */
	public function test_state_leaves_out_left_out_items() {
		$key = 'menu:' . $this->menu;
		swiftling_save_menu_list( swiftling_subject( $key ) );
		$this->translate( 'post:' . $this->items['home'], 'field/post_title', 'Početna', 'de', 'Startseite' );
		$this->translate( 'post:' . $this->items['directions'], 'field/post_title', 'Upute za dolazak', 'de', 'Anfahrt' );
		$this->translate( 'post:' . $this->items['imprint'], 'field/post_title', 'Impressum', 'de', 'Impressum' );
		$this->assertSame( 'partial', swiftling_subject_states( $key )['de'] );

		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr', 'fr_FR' ) );
		$this->assertSame( 'reviewed', swiftling_subject_states( $key )['de'] );
		$this->assertSame(
			array(
				'translated' => 3,
				'total'      => 3,
			),
			swiftling_subject_counts( $key )['de']
		);
		$this->assertSame( 'missing', swiftling_subject_states( $key )['fr'] );
		$this->assertSame( 5, swiftling_subject_counts( $key )['fr']['total'] );
	}

	/**
	 * The editor of a menu in a language has no fields for items left out there, and names them in
	 * a closed list.
	 */
	public function test_editor_leaves_out_left_out_items() {
		$this->become( 'administrator' );
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr', 'fr_FR' ) );
		$subject = swiftling_subject( 'menu:' . $this->menu );

		$german = swiftling_live_rows_html( $subject, 'de', swiftling_save_menu_list( $subject ) );
		$this->assertStringContainsString( 'Upute za dolazak', $german );
		$this->assertStringNotContainsString( 'data-original="Novosti"', $german );
		$this->assertStringContainsString( 'Left out in Deutsch (2)', $german );
		$this->assertStringContainsString( 'Blog, Novosti', $german );
		$this->assertStringContainsString( 'items=' . $this->menu, $german );
		$this->assertStringContainsString( '>Choose items</a>', $german );

		$french = swiftling_live_rows_html( $subject, 'fr', swiftling_save_menu_list( $subject ) );
		$this->assertStringContainsString( 'data-original="Novosti"', $french );
		$this->assertStringNotContainsString( 'Left out in', $french );
	}

	/**
	 * The Menus screen says which menus leave items out, leads to their table, and names a language
	 * added since the choices were made.
	 */
	public function test_menus_screen() {
		$this->become( 'administrator' );
		$html = $this->render_screen( 'menus' );
		$this->assertStringContainsString( 'Same items in every language', $html );
		$this->assertStringContainsString( 'items=' . $this->menu, $html );
		// Two jobs, each named for what it does, in a group that says what it changes.
		$this->assertMatchesRegularExpression( '~Texts</span>.*the labels of the items, in each language~s', $html );
		$this->assertMatchesRegularExpression( '~Items</span>.*which ones each language shows~s', $html );
		$this->assertStringContainsString( '>Translate texts</a>', $html );
		$this->assertStringContainsString( '>Choose items</a>', $html );
		$this->assertStringNotContainsString( 'Items per language', $html );
		$this->assertDoesNotMatchRegularExpression( '~>\s*Translate\s*</a>~', $html );

		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr' ) );
		$html = $this->render_screen( 'menus' );
		$this->assertStringContainsString( '1 item not in every language', $html );

		// A language added later is not announced: it shows the same items as the original.
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
				'it_IT' => 'it',
			)
		);
		$html = $this->render_screen( 'menus' );
		$this->assertStringNotContainsString( 'was added', $html );
		$this->assertStringNotContainsString( 'swiftling-notice', $html );
	}

	/**
	 * With many languages, the Menus screen keeps its width: every language of the texts is in one
	 * cell that wraps, not a column each, so Choose items stays on screen.
	 */
	public function test_menus_screen_with_many_languages() {
		$targets = array(
			'de_DE' => 'de',
			'fr_FR' => 'fr',
			'it_IT' => 'it',
			'es_ES' => 'es',
			'es_MX' => 'es-mx',
			'en_GB' => 'en',
			'pt_PT' => 'pt',
		);
		$this->set_languages( $targets );
		$this->become( 'administrator' );
		$html = $this->render_screen( 'menus' );
		$this->assertSame( 1, preg_match( '~<span class="swiftling-menus-table__languages">(.*?)</span>\s*<a~s', $html, $cell ) );
		$this->assertSame( count( $targets ), substr_count( $cell[1], 'swiftling-menus-table__language"' ) );
		$this->assertStringNotContainsString( 'class="swiftling-lang', $html );
		$this->assertStringContainsString( '>Choose items</a>', $html );
	}

	/**
	 * The language tabs of the translation editor are under its bar, not in it: the bar stays on
	 * screen and keeps to one line, with its buttons together, however many languages there are.
	 */
	public function test_editor_languages_under_the_bar() {
		$this->become( 'administrator' );
		$_GET = array(
			'subject' => 'menu:' . $this->menu,
			'lang'    => 'de',
		);
		$html = $this->render_screen( 'editor' );
		$bar  = strpos( $html, 'class="swiftling-editor-bar"' );
		$end  = strpos( $html, 'wp-header-end' );
		$tabs = strpos( $html, 'swiftling-editor-languages' );
		$this->assertNotFalse( $tabs );
		$this->assertGreaterThan( $end, $tabs );
		$this->assertMatchesRegularExpression( '~<div class="swiftling-editor-bar__actions">.*View original.*View in Deutsch.*Save and mark reviewed.*</button>\s*</div>~s', substr( $html, $bar, $end - $bar ) );
	}

	/**
	 * The table of a menu: a tick per item and language, the original first; a cell under an item
	 * left out in that language says so instead.
	 */
	public function test_items_table() {
		$this->become( 'administrator' );
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr', 'fr_FR' ) );
		$_GET = array( 'items' => (string) $this->menu );
		$html = $this->render_screen( 'menus' );

		$this->assertStringContainsString( 'Glavni izbornik: choose items', $html );
		$this->assertStringContainsString( '>Translate texts</a>', $html );
		$this->assertStringContainsString( 'value="swiftling_save_menu_items"', $html );
		$this->assertMatchesRegularExpression( '/name="shown\[' . $this->items['blog'] . '\]\[\]" value="hr" checked/', $html );
		$this->assertMatchesRegularExpression( '/name="shown\[' . $this->items['blog'] . '\]\[\]" value="de_DE"(?! checked)/', $html );
		$this->assertStringContainsString( 'with Blog', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="shown\[' . $this->items['news'] . '\]\[\]" value="de_DE"/', $html );
		// The ticks say where an item shows: no summary next to them.
		$this->assertStringNotContainsString( 'Not in Deutsch', $html );
		$this->assertStringNotContainsString( 'Every language', $html );
	}

	/**
	 * Above the table, a panel says how it works, with a way to Appearance → Menus. Whoever hides it
	 * has a link to open it again; other people still see it.
	 */
	public function test_how_it_works() {
		$this->become( 'administrator' );
		$_GET = array( 'items' => (string) $this->menu );
		$html = $this->render_screen( 'menus' );
		$this->assertStringContainsString( 'One menu, a different mix in each language', $html );
		$this->assertStringContainsString( 'Tick where visitors see it', $html );
		$this->assertStringContainsString( 'A submenu goes along', $html );
		$this->assertStringContainsString( 'An item for one language', $html );
		$this->assertStringContainsString( esc_url( admin_url( 'nav-menus.php?menu=' . $this->menu ) ), $html );
		$this->assertStringContainsString( 'Got it, hide this', $html );

		$this->post_form(
			'swiftling_menu_help',
			array(
				'show' => '0',
				'menu' => (string) $this->menu,
			)
		);
		$this->assertStringContainsString( 'items=' . $this->menu, $this->redirect_of( 'swiftling_save_menu_help' ) );
		$_GET = array( 'items' => (string) $this->menu );
		$html = $this->render_screen( 'menus' );
		$this->assertStringNotContainsString( 'Tick where visitors see it', $html );
		$this->assertStringContainsString( 'How it works', $html );

		$me = get_current_user_id();
		$this->become( 'administrator' );
		$this->assertStringContainsString( 'Tick where visitors see it', $this->render_screen( 'menus' ) );

		wp_set_current_user( $me );
		$this->post_form(
			'swiftling_menu_help',
			array(
				'show' => '1',
				'menu' => (string) $this->menu,
			)
		);
		$this->redirect_of( 'swiftling_save_menu_help' );
		$this->assertStringContainsString( 'Tick where visitors see it', $this->render_screen( 'menus' ) );
	}

	/**
	 * Starting over, removing everything, removes the choices.
	 */
	public function test_start_over_removes_the_choices() {
		swiftling_save_menu_item_languages( $this->items['blog'], array( 'hr' ) );
		$user = self::factory()->user->create();
		update_user_meta( $user, SWIFTLING_MENU_HELP_HIDDEN, 1 );
		require_once SWIFTLING_DIR . 'includes/setup.php';
		swiftling_remove_everything();
		wp_cache_flush();
		$this->assertSame( '', get_post_meta( $this->items['blog'], '_swiftling_show_in', true ) );
		$this->assertSame( '', get_user_meta( $user, 'swiftling_menu_help_hidden', true ) );
	}
}
