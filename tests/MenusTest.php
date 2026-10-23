<?php
/**
 * Tests of classic menus: the subject of a menu, its texts, and how they show translated.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use WP_REST_Request;

/**
 * A classic menu with an item that shows a page's title, an item with a label and a tooltip of its
 * own under it, a custom link, a category and an item of a page in the trash.
 */
class MenusTest extends TestCase {

	/**
	 * Menu term ID.
	 *
	 * @var int
	 */
	private $menu = 0;

	/**
	 * The page the first item shows the title of.
	 *
	 * @var \WP_Post
	 */
	private $page;

	/**
	 * Item IDs by name: title, label, custom, category, trashed.
	 *
	 * @var array<string, int>
	 */
	private $items = array();

	/**
	 * Category the menu leads to.
	 *
	 * @var int
	 */
	private $category = 0;

	/**
	 * Sets up the menu, placed in the theme's primary menu.
	 */
	public function set_up() {
		parent::set_up();
		$this->page     = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Apartman Lavanda',
				'post_name'    => 'apartman-lavanda',
				'post_content' => 'Svijetli apartman za četiri osobe.',
			)
		);
		$directions     = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kako do nas',
			)
		);
		$gone           = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Stara ponuda',
			)
		);
		$this->category = self::factory()->category->create( array( 'name' => 'Vijesti' ) );

		$this->menu              = wp_create_nav_menu( 'Glavni izbornik' );
		$this->items['title']    = $this->item(
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $this->page->ID,
			)
		);
		$this->items['label']    = $this->item(
			array(
				'menu-item-type'       => 'post_type',
				'menu-item-object'     => 'page',
				'menu-item-object-id'  => $directions,
				'menu-item-title'      => 'Upute za dolazak',
				'menu-item-attr-title' => 'Upute i karta',
				'menu-item-parent-id'  => $this->items['title'],
			)
		);
		$this->items['custom']   = $this->item(
			array(
				'menu-item-type'  => 'custom',
				'menu-item-title' => 'Rezervacije',
				'menu-item-url'   => home_url( '/apartman-lavanda/#rezervacija' ),
			)
		);
		$this->items['category'] = $this->item(
			array(
				'menu-item-type'      => 'taxonomy',
				'menu-item-object'    => 'category',
				'menu-item-object-id' => $this->category,
			)
		);
		$this->items['trashed']  = $this->item(
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $gone,
			)
		);
		wp_trash_post( $gone );

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
	 * Returns the texts of the menu as the editor lists them: owner, path, value and depth.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string, 3: int}>
	 */
	private function listed() {
		$result = swiftling_save_menu_list( swiftling_subject( 'menu:' . $this->menu ) );
		return array_map(
			static function ( $unit ) {
				return array( $unit['o'], $unit['p'], $unit['v'], $unit['depth'] );
			},
			$result['sections']['page']
		);
	}

	/**
	 * Saves the translation of a text of the menu, as the editor of the menu does.
	 *
	 * @param string $owner    Object key.
	 * @param string $path     Path.
	 * @param string $original The original.
	 * @param string $text     Translation.
	 * @return \WP_REST_Response
	 */
	private function save( $owner, $path, $original, $text ) {
		$request = new WP_REST_Request( 'POST', '/swiftling/v1/translations' );
		$request->set_body_params(
			array(
				'subject' => 'menu:' . $this->menu,
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'  => swiftling_place_key( $owner, swiftling_path_key( $path ), swiftling_hash( $original ) ),
						'text' => $text,
					),
				),
			)
		);
		return rest_get_server()->dispatch( $request );
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
	 * A menu is a subject of the editor, named after the menu and where the theme shows it; other
	 * keys name nothing.
	 */
	public function test_subject() {
		$subject = swiftling_subject( 'menu:' . $this->menu );
		$this->assertSame( 'menu', $subject['kind'] );
		$this->assertSame( 'Glavni izbornik', $subject['title'] );
		$this->assertStringContainsString( 'Primary Menu', $subject['type'] );
		$this->assertFalse( $subject['probe'] );

		$this->assertNull( swiftling_subject( 'menu:' . $this->category ) );
		$this->assertNull( swiftling_subject( 'menu:999999' ) );
		$this->assertNull( swiftling_subject( 'menu:abc' ) );
	}

	/**
	 * The editor lists every text of the menu in its order: an item's label and tooltip, and the
	 * title an item shows of its page, as the item's own; a category's name, as the category's. An
	 * item whose page is in the trash is not on the site, so it is not listed.
	 */
	public function test_texts_of_the_menu() {
		$this->assertSame(
			array(
				array( 'post:' . $this->items['title'], 'field/post_title', 'Apartman Lavanda', 0 ),
				array( 'post:' . $this->items['label'], 'field/post_title', 'Upute za dolazak', 1 ),
				array( 'post:' . $this->items['label'], 'field/post_excerpt', 'Upute i karta', 1 ),
				array( 'post:' . $this->items['custom'], 'field/post_title', 'Rezervacije', 0 ),
				array( 'term:' . $this->category, 'field/name', 'Vijesti', 0 ),
			),
			$this->listed()
		);
	}

	/**
	 * An item that shows its page's title shows the page's translated title, until the menu gives it a
	 * label of its own in the language: that one wins, in the menu only. Listing the menu leaves the
	 * page's own texts alone.
	 */
	public function test_menu_label_wins_over_the_page_title() {
		$this->check(
			'post:' . $this->page->ID,
			function () {
				get_the_title( $this->page );
				apply_filters( 'the_content', $this->page->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
			}
		);
		$before = swiftling_get_maps( array( 'post:' . $this->page->ID ) )[ 'post:' . $this->page->ID ];
		$this->listed();
		$this->assertSame( $before, swiftling_get_maps( array( 'post:' . $this->page->ID ) )[ 'post:' . $this->page->ID ] );

		$this->translate( 'post:' . $this->page->ID, 'field/post_title', 'Apartman Lavanda', 'de', 'Apartment Lavendel' );
		$this->show_in( 'de' );
		$this->assertStringContainsString( '>Apartment Lavendel</a>', $this->render_menu() );

		$this->translate( 'post:' . $this->items['title'], 'field/post_title', 'Apartman Lavanda', 'de', 'Lavendel' );
		$this->show_in( 'de' );
		$this->assertStringContainsString( '>Lavendel</a>', $this->render_menu() );
		$this->assertStringNotContainsString( 'Apartment Lavendel', $this->render_menu() );
		$this->assertSame( 'Apartment Lavendel', get_the_title( $this->page ) );

		// A language without either shows the original.
		$this->show_in( 'fr' );
		$this->assertStringContainsString( '>Apartman Lavanda</a>', $this->render_menu() );
	}

	/**
	 * Filling a menu's empty texts with the translation service leaves out an item that shows its
	 * page's title: it shows the page's translated title until someone writes a label of its own.
	 */
	public function test_machine_translation_leaves_a_page_title_alone() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );

		$done = swiftling_machine_translate_subject( 'menu:' . $this->menu, 'de' );
		$this->assertIsArray( $done, is_wp_error( $done ) ? $done->get_error_message() : '' );
		$sent = wp_list_pluck( FakeService::$sent, 'text' );
		$this->assertContains( 'Rezervacije', $sent );
		$this->assertContains( 'Vijesti', $sent );
		$this->assertNotContains( 'Apartman Lavanda', $sent );
		$this->assertSame( count( $sent ), $done['translated'] );
	}

	/**
	 * The menu's label of a page's title is of the title as it was: once the page's title changes, the
	 * menu shows the page's translated title again until the label is translated anew.
	 */
	public function test_menu_label_of_a_changed_title() {
		$this->listed();
		$this->translate( 'post:' . $this->items['title'], 'field/post_title', 'Apartman Lavanda', 'de', 'Lavendel' );
		wp_update_post(
			array(
				'ID'         => $this->page->ID,
				'post_title' => 'Apartman Lavanda Deluxe',
			)
		);
		$this->listed();
		$this->check(
			'post:' . $this->page->ID,
			function () {
				get_the_title( $this->page->ID );
			}
		);
		$this->translate( 'post:' . $this->page->ID, 'field/post_title', 'Apartman Lavanda Deluxe', 'de', 'Apartment Lavendel Deluxe' );

		$this->show_in( 'de' );
		$this->assertStringContainsString( '>Apartment Lavendel Deluxe</a>', $this->render_menu() );
		$this->assertSame( 'outdated', swiftling_subject_states( 'menu:' . $this->menu )['de'] );
	}

	/**
	 * A menu's label of a page's title counts as translated when the menu has one or the page's
	 * title is translated, as visitors then read a translation; the other texts count as always.
	 */
	public function test_state_follows_the_page_title() {
		$this->listed();
		$this->translate( 'post:' . $this->items['label'], 'field/post_title', 'Upute za dolazak', 'de', 'Anfahrt' );
		$this->translate( 'post:' . $this->items['label'], 'field/post_excerpt', 'Upute i karta', 'de', 'Anfahrt und Karte' );
		$this->translate( 'post:' . $this->items['custom'], 'field/post_title', 'Rezervacije', 'de', 'Reservierung' );
		$this->translate( 'term:' . $this->category, 'field/name', 'Vijesti', 'de', 'Neuigkeiten' );
		$key = 'menu:' . $this->menu;
		$this->assertSame( 'partial', swiftling_subject_states( $key )['de'] );
		$this->assertSame( 4, swiftling_subject_counts( $key )['de']['translated'] );

		$this->translate( 'post:' . $this->page->ID, 'field/post_title', 'Apartman Lavanda', 'de', 'Apartment Lavendel', SWIFTLING_MACHINE );
		$this->assertSame( 'machine', swiftling_subject_states( $key )['de'] );
		$this->assertSame( 5, swiftling_subject_counts( $key )['de']['translated'] );

		$this->translate( 'post:' . $this->items['title'], 'field/post_title', 'Apartman Lavanda', 'de', 'Lavendel' );
		$this->assertSame( 'reviewed', swiftling_subject_states( $key )['de'] );
		$this->assertSame( 'missing', swiftling_subject_states( $key )['fr'] );
	}

	/**
	 * Labels, tooltips and category names translated for the menu show on the site; the original
	 * stays as it was.
	 */
	public function test_translated_menu_on_the_site() {
		$this->listed();
		$this->translate( 'post:' . $this->items['label'], 'field/post_title', 'Upute za dolazak', 'de', 'Anfahrt' );
		$this->translate( 'post:' . $this->items['label'], 'field/post_excerpt', 'Upute i karta', 'de', 'Anfahrt und Karte' );
		$this->translate( 'post:' . $this->items['custom'], 'field/post_title', 'Rezervacije', 'de', 'Reservierung' );
		$this->translate( 'term:' . $this->category, 'field/name', 'Vijesti', 'de', 'Neuigkeiten' );

		$original = $this->render_menu();
		$this->assertStringContainsString( '>Upute za dolazak</a>', $original );
		$this->assertStringContainsString( 'title="Upute i karta"', $original );

		$this->show_in( 'de' );
		$html = $this->render_menu();
		$this->assertStringContainsString( '>Anfahrt</a>', $html );
		$this->assertStringContainsString( 'title="Anfahrt und Karte"', $html );
		$this->assertStringContainsString( '>Reservierung</a>', $html );
		$this->assertStringContainsString( '>Neuigkeiten</a>', $html );
		$this->assertStringNotContainsString( 'Stara ponuda', $html );
	}

	/**
	 * The editor of a menu renders its rows at once: submenu items indented, the page's title with a
	 * note that it is the page's, where a custom link leads, and SwiftLing's language switcher
	 * without a field.
	 */
	public function test_editor_of_a_menu() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		update_option(
			\SwiftLing\Switcher\Settings::OPTION,
			array(
				'menu' => array(
					'menus'    => array( $this->menu ),
					'position' => 'last',
					'style'    => 'dropdown',
				),
			)
		);
		\SwiftLing\Switcher\Settings::forget();
		$_GET = array(
			'subject' => 'menu:' . $this->menu,
			'lang'    => 'de',
		);

		ob_start();
		( new Menu() )->renderScreen( 'editor' );
		$screen = (string) ob_get_clean();
		set_current_screen( 'front' );
		$this->assertStringContainsString( 'page=swiftling-menus', $screen );

		// The rows come with the editor's settings, ready without a page check.
		$this->assertSame( 1, preg_match( '~<script type="application/json" id="swiftling-editor-config">(.*?)</script>~s', $screen, $found ) );
		$config = json_decode( $found[1], true );
		$this->assertNull( $config['probes'] );
		$html = $config['rows']['rows'];
		$this->assertStringContainsString( 'Apartman Lavanda', $html );
		// What a label of a page's title does is said once, without a link to the page.
		$this->assertSame( 1, substr_count( $html, 'shows in this menu only' ) );
		$this->assertStringNotContainsString( 'Translate the page', $html );
		$this->assertStringContainsString( 'Shows the page’s title, not translated yet', $html );
		$this->assertStringContainsString( 'data-follows="1"', $html );
		$this->assertMatchesRegularExpression( '/<tr class="[^"]*is-depth-1[^"]*"/', $html );
		$this->assertStringContainsString( 'opens in Deutsch by itself', $html );
		$this->assertStringContainsString( 'swiftling-live-info', $html );
		$this->assertStringContainsString( 'Hrvatski · Deutsch · Français', $html );
	}

	/**
	 * A label saved for a page's title in the editor of a menu is the menu's own: the page's title and
	 * the page's state stay as they were, and emptied again it shows the page's title in its state.
	 * The empty field says what visitors read meanwhile.
	 */
	public function test_saving_a_label_in_the_menu() {
		global $wpdb;
		$this->become( 'administrator' );
		$this->listed();
		$this->translate( 'post:' . $this->page->ID, 'field/post_title', 'Apartman Lavanda', 'de', 'Apartment Lavendel' );
		$place = swiftling_place_key( 'post:' . $this->items['title'], swiftling_path_key( 'field/post_title' ), swiftling_hash( 'Apartman Lavanda' ) );

		$response = $this->save( 'post:' . $this->items['title'], 'field/post_title', 'Apartman Lavanda', 'Lavendel' );
		$this->assertSame( array( $place => 'reviewed' ), $response->get_data()['states'] );
		$page = swiftling_place_key( 'post:' . $this->page->ID, swiftling_path_key( 'field/post_title' ), swiftling_hash( 'Apartman Lavanda' ) );
		$this->assertSame( 'Apartment Lavendel', swiftling_get_translations( 'de_DE', array( $page ) )[ $page ]['text'] );
		$this->assertSame( array(), swiftling_translated_ids( array( $this->page->ID ), 'de_DE' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own table, read by the test.
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM %i WHERE object_key = %s', swiftling_table( 'slugs' ), 'post:' . $this->page->ID ) ) );

		$response = $this->save( 'post:' . $this->items['title'], 'field/post_title', 'Apartman Lavanda', '' );
		$this->assertSame( array( $place => 'reviewed' ), $response->get_data()['states'] );
		$this->assertSame( array(), swiftling_get_translations( 'de_DE', array( $place ) ) );

		$html = swiftling_live_rows_html( swiftling_subject( 'menu:' . $this->menu ), 'de', swiftling_save_menu_list( swiftling_subject( 'menu:' . $this->menu ) ) );
		$this->assertStringContainsString( 'Shows the page’s title: “Apartment Lavendel”', $html );
		$this->assertStringContainsString( 'data-fallback="reviewed"', $html );
	}

	/**
	 * Someone who may not edit a menu item may not translate its label.
	 */
	public function test_label_needs_the_right_to_edit_the_item() {
		$this->become( 'administrator' );
		$this->listed();
		$item = $this->items['title'];
		$deny = static function ( $caps, $cap, $user_id, $args ) use ( $item ) {
			return 'edit_post' === $cap && isset( $args[0] ) && (int) $item === (int) $args[0] ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );

		$response = $this->save( 'post:' . $item, 'field/post_title', 'Apartman Lavanda', 'Lavendel' );
		remove_filter( 'map_meta_cap', $deny, 10 );

		$this->assertSame( array(), $response->get_data()['states'] );
		$this->assertSame( array(), swiftling_get_translations( 'de_DE', array( swiftling_place_key( 'post:' . $item, swiftling_path_key( 'field/post_title' ), swiftling_hash( 'Apartman Lavanda' ) ) ) ) );
	}

	/**
	 * A template lists no item of a classic menu among what every page shows: it points to the menus
	 * instead. A navigation menu of the Site Editor stays there.
	 */
	public function test_templates_point_to_menus() {
		$this->become( 'administrator' );
		$navigation = self::factory()->post->create( array( 'post_type' => 'wp_navigation' ) );
		$item       = array(
			'o'       => 'post:' . $this->items['label'],
			'p'       => 'field/post_title',
			'v'       => 'Upute za dolazak',
			'r'       => 'field:post_title||',
			'kind'    => 'link',
			'region'  => 'navigation',
			'content' => false,
		);
		$this->assertSame( '', swiftling_template_unit_section( $item ) );
		$this->assertSame( 'site', swiftling_template_unit_section( array( 'o' => 'post:' . $navigation ) + $item ) );

		$html = swiftling_live_rows_html(
			array(
				'key'  => 'view:page',
				'kind' => 'view',
			),
			'de',
			array(
				'sections' => array(
					'page' => array(),
					'site' => array(),
				),
				'maps'     => array(),
				'units'    => array( $item ),
			)
		);
		$this->assertStringContainsString( 'swiftling-menu-pointer', $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'menu:' . $this->menu, 'de' ) ), $html );
	}

	/**
	 * The editor of a page calls the page's own title Title, also where the page shows it only as a
	 * link of its menu, as a builder's page in a theme that leaves out the title does.
	 */
	public function test_page_editor_calls_the_title_title() {
		$this->become( 'administrator' );
		$leaves = $this->probe(
			function () {
				get_the_title( $this->page );
			}
		);
		$found  = array();
		foreach ( array_keys( $leaves ) as $id ) {
			$found[ $id ] = array(
				$this->landing(
					'text',
					1,
					array(
						'kind'   => 'link',
						'region' => 'navigation',
					)
				),
			);
		}
		$subject = swiftling_subject( 'post:' . $this->page->ID );
		$result  = swiftling_save_probe( $subject, array( 'leaves' => $leaves ), $found, array(), true );

		$html = swiftling_live_rows_html( $subject, 'de', $result );
		$this->assertMatchesRegularExpression( '~<th scope="row">\s*Title\s*</th>~', $html );
		$this->assertStringNotContainsString( 'Navigation', $html );
	}

	/**
	 * The editor of a page says which menus show its title, and which show a label of their own.
	 */
	public function test_page_editor_names_the_menus() {
		$this->become( 'administrator' );
		$result = $this->check(
			'post:' . $this->page->ID,
			function () {
				get_the_title( $this->page );
			}
		);
		$html   = swiftling_live_rows_html( swiftling_subject( 'post:' . $this->page->ID ), 'de', $result );
		$this->assertStringContainsString( 'Also the label in the menu:', $html );
		$this->assertStringContainsString( '>Glavni izbornik</a>', $html );

		// A menu with a label of its own in the language is named as such.
		$this->listed();
		$this->translate( 'post:' . $this->items['title'], 'field/post_title', 'Apartman Lavanda', 'de', 'Lavendel' );
		$html = swiftling_live_rows_html( swiftling_subject( 'post:' . $this->page->ID ), 'de', $result );
		$this->assertStringNotContainsString( 'Also the label', $html );
		$this->assertStringContainsString( 'Glavni izbornik shows a label of its own in Deutsch instead.', $html );
	}

	/**
	 * The Menus screen lists each menu with where the theme shows it and its state; without menus
	 * it says how to build one.
	 */
	public function test_menus_screen() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		ob_start();
		( new Menu() )->renderScreen( 'menus' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Glavni izbornik', $html );
		$this->assertStringContainsString( 'Primary Menu', $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'menu:' . $this->menu ) ), $html );

		wp_delete_nav_menu( $this->menu );
		ob_start();
		( new Menu() )->renderScreen( 'menus' );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );
		$this->assertStringContainsString( 'No menus yet', $html );
	}
}
