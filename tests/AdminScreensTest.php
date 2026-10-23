<?php
/**
 * Tests that every admin screen renders.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;

/**
 * Every SwiftLing screen, rendered as an administrator sees it, without a notice or a warning.
 */
class AdminScreensTest extends TestCase {

	/**
	 * Every screen of the menu renders on a site with content and translations.
	 *
	 * @dataProvider data_screens
	 *
	 * @param string $view View.
	 */
	public function test_screen_renders( $view ) {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt' );
		self::factory()->category->create( array( 'name' => 'Vijesti' ) );
		$_GET = array(
			'post' => (string) $post->ID,
			'lang' => 'de',
		);

		$html = $this->render_screen( $view );
		$this->assertStringStartsWith( '<div class="wrap swiftling">', $html );
		$this->assertStringEndsWith( '</div>', $html );
		// WordPress moves the notices of other plugins after the page's .wp-header-end
		// (wp-admin/js/common.js): the screen's only one comes first, so they stand above the screen,
		// never between its header and the rest.
		$this->assertStringStartsWith( '<div class="wrap swiftling"><hr class="wp-header-end">', $html );
		$this->assertSame( 1, substr_count( $html, 'wp-header-end' ) );
		set_current_screen( 'front' );
	}

	/**
	 * The screens of the menu.
	 *
	 * @return array
	 */
	public function data_screens() {
		$views = array();
		foreach ( array( 'overview', 'content', 'translate-all', 'templates', 'menus', 'forms', 'terms', 'themes-plugins', 'languages', 'addresses', 'service', 'switcher', 'settings', 'setup', 'editor' ) as $view ) {
			$views[ $view ] = array( $view );
		}
		return $views;
	}

	/**
	 * The editor of the terms of a taxonomy renders their rows at once.
	 */
	public function test_terms_editor_renders() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		self::factory()->category->create( array( 'name' => 'Vijesti' ) );
		$_GET = array(
			'subject' => 'terms:category',
			'lang'    => 'de',
		);
		$this->assertStringContainsString( 'Vijesti', $this->render_screen( 'editor' ) );
		set_current_screen( 'front' );
	}

	/**
	 * A table with a column for every language scrolls sideways inside its card, so a site with
	 * many languages does not make the whole screen wider than the window.
	 *
	 * @dataProvider data_language_tables
	 *
	 * @param string $view View.
	 */
	public function test_table_per_language_scrolls_in_its_card( $view ) {
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
		set_current_screen( 'toplevel_page_swiftling' );
		self::factory()->post->create( array( 'post_title' => 'Kontakt' ) );
		self::factory()->category->create( array( 'name' => 'Vijesti' ) );
		$menu = wp_create_nav_menu( 'Glavni izbornik' );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Početna',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);
		$_GET = array();

		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $this->render_screen( $view ) );
		libxml_clear_errors();
		$xpath  = new \DOMXPath( $document );
		$tables = 0;
		foreach ( $xpath->query( '//table' ) as $table ) {
			if ( $xpath->query( './/thead//span[contains(concat(" ", @class, " "), " swiftling-lang ")]', $table )->length < count( $targets ) ) {
				continue;
			}
			++$tables;
			$this->assertSame( 1, $xpath->query( 'ancestor::div[contains(concat(" ", @class, " "), " swiftling-table-wrap ")]', $table )->length, $view . ': a table with a column per language is not in .swiftling-table-wrap' );
		}
		$this->assertGreaterThan( 0, $tables, $view . ' shows no table with a column per language' );
		set_current_screen( 'front' );
	}

	/**
	 * The screens with a column for every language.
	 *
	 * @return array
	 */
	public function data_language_tables() {
		$views = array();
		// Menus has one cell for every language, see MenuLanguagesTest::test_menus_screen_with_many_languages().
		foreach ( array( 'content', 'templates', 'terms' ) as $view ) {
			$views[ $view ] = array( $view );
		}
		return $views;
	}

	/**
	 * The menu is only for people who manage the site. It has five items, by name only, with no icon: a group of
	 * screens shows as one item, under the group's name, which opens its first screen.
	 */
	public function test_menu() {
		global $submenu;
		$this->become( 'administrator' );
		$menu = new Menu();
		$menu->registerMenu();
		$menu->hideScreens();
		$this->assertSame(
			array(
				'swiftling'               => 'Overview',
				'swiftling-content'       => 'Translations',
				'swiftling-translate-all' => 'Translate everything',
				'swiftling-languages'     => 'Settings',
				'swiftling-log'           => 'Logs',
			),
			array_combine( wp_list_pluck( $submenu['swiftling'], 2 ), wp_list_pluck( $submenu['swiftling'], 0 ) )
		);
		foreach ( wp_list_pluck( $submenu['swiftling'], 0 ) as $name ) {
			$this->assertSame( wp_strip_all_tags( $name ), $name, 'No icon in the menu' );
		}

		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh admin menu.
		$this->become( 'editor' );
		( new Menu() )->registerMenu();
		$this->assertArrayNotHasKey( 'swiftling', $submenu );
	}

	/**
	 * A screen with a count shows it beside its menu item, as WordPress shows pending comments; a
	 * screen of a group adds it to the group's item, and SwiftLing's own item shows them all, so
	 * the count is seen with the menu closed. No count, or 0, shows nothing.
	 */
	public function test_menu_shows_counts() {
		global $submenu, $menu;
		$this->become( 'administrator' );
		add_filter(
			'swiftling_admin_screens',
			static function ( $screens ) {
				$screens['swiftling-log']['count']      = 2;
				$screens['swiftling-service']['count']  = 1;
				$screens['swiftling-switcher']['count'] = 0;
				return $screens;
			}
		);
		$menu    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh admin menu.
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh admin menu.
		$admin   = new Menu();
		$admin->registerMenu();
		$admin->hideScreens();
		$names = array_combine( wp_list_pluck( $submenu['swiftling'], 2 ), wp_list_pluck( $submenu['swiftling'], 0 ) );

		$this->assertSame( 'Logs <span class="awaiting-mod count-2"><span class="pending-count">2</span></span>', $names['swiftling-log'] );
		$this->assertSame( 'Settings <span class="awaiting-mod count-1"><span class="pending-count">1</span></span>', $names['swiftling-languages'], 'The group of the screen' );
		$this->assertSame( 'Overview', $names['swiftling'] );
		$top = wp_list_filter( $menu, array( 2 => 'swiftling' ) );
		$this->assertSame( 'SwiftLing <span class="awaiting-mod count-3"><span class="pending-count">3</span></span>', reset( $top )[0] );
		$titles = array_combine( wp_list_pluck( $submenu['swiftling'], 2 ), wp_list_pluck( $submenu['swiftling'], 3 ) );
		$this->assertSame( 'Logs', $titles['swiftling-log'], 'The page title stays plain' );
	}

	/**
	 * A screen of a group is out of the menu but keeps its address, so links and bookmarks to it
	 * still open it.
	 */
	public function test_screens_of_a_group_keep_their_address() {
		$this->become( 'administrator' );
		$menu = new Menu();
		$menu->registerMenu();
		$menu->hideScreens();
		foreach ( array( 'swiftling-terms', 'swiftling-menus', 'swiftling-templates', 'swiftling-forms', 'swiftling-comments', 'swiftling-themes-plugins', 'swiftling-addresses', 'swiftling-service', 'swiftling-switcher', 'swiftling-settings' ) as $slug ) {
			$this->assertNotNull( get_plugin_page_hook( $slug, 'swiftling' ), $slug );
		}
	}

	/**
	 * On a screen of a group, the menu shows the group's item as the current one; on a screen of
	 * its own, WordPress's choice stays.
	 */
	public function test_menu_marks_the_group_of_the_screen() {
		global $plugin_page;
		( new Menu() )->register();
		$was = $plugin_page;

		$expected = array(
			'swiftling-addresses'      => 'swiftling-languages',
			'swiftling-settings'       => 'swiftling-languages',
			'swiftling-languages'      => 'swiftling-languages',
			'swiftling-forms'          => 'swiftling-content',
			'swiftling-themes-plugins' => 'swiftling-content',
			'swiftling-log'            => null,
			'swiftling-translate-all'  => null,
			'woocommerce'              => null,
		);
		foreach ( $expected as $page => $item ) {
			$plugin_page = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The screen being shown.
			$this->assertSame( $item, apply_filters( 'submenu_file', null, 'swiftling' ), $page ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as the admin menu runs it.
		}
		$plugin_page = $was; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Put back.
	}

	/**
	 * A screen of a group shows its group's screens in a list beside it, in parts under their names,
	 * with its own marked; a screen of its own shows no list.
	 *
	 * @dataProvider data_rail
	 *
	 * @param string $view    View.
	 * @param array  $parts   Names of the screens, keyed by the screens they open, by the names of the parts.
	 * @param string $current Screen that is marked.
	 */
	public function test_rail( $view, $parts, $current ) {
		$this->become( 'administrator' );
		$_GET = array();

		$xpath  = $this->screen_xpath( $view );
		$found  = array();
		$marked = array();
		foreach ( $xpath->query( '//nav[contains(concat(" ", @class, " "), " swiftling-rail ")]/div' ) as $part ) {
			$name = trim( $xpath->query( './/p', $part )->item( 0 )->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- A property of PHP's DOMNode.
			foreach ( $xpath->query( './/a', $part ) as $link ) {
				$slug                    = substr( (string) wp_parse_url( $link->getAttribute( 'href' ), PHP_URL_QUERY ), strlen( 'page=' ) );
				$found[ $name ][ $slug ] = trim( $link->textContent ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- A property of PHP's DOMNode.
				if ( 'page' === $link->getAttribute( 'aria-current' ) ) {
					$marked[] = $slug;
				}
			}
		}
		$this->assertSame( $parts, $found );
		$this->assertSame( $current ? array( $current ) : array(), $marked );
	}

	/**
	 * Screens and the lists beside them.
	 *
	 * @return array
	 */
	public function data_rail() {
		$translations = array(
			'Content'    => array(
				'swiftling-content'  => 'Pages & posts',
				'swiftling-terms'    => 'Categories & tags',
				'swiftling-comments' => 'Comments',
				'swiftling-forms'    => 'Forms',
			),
			'Site parts' => array(
				'swiftling-menus'          => 'Menus',
				'swiftling-templates'      => 'Templates',
				'swiftling-themes-plugins' => 'Themes & plugins',
			),
		);
		$settings     = array(
			'Site'      => array(
				'swiftling-languages' => 'Languages',
				'swiftling-addresses' => 'URLs',
				'swiftling-switcher'  => 'Switcher',
			),
			'SwiftLing' => array(
				'swiftling-service'  => 'Translation service',
				'swiftling-settings' => 'General',
			),
		);
		return array(
			'content'       => array( 'content', $translations, 'swiftling-content' ),
			'forms'         => array( 'forms', $translations, 'swiftling-forms' ),
			'languages'     => array( 'languages', $settings, 'swiftling-languages' ),
			'addresses'     => array( 'addresses', $settings, 'swiftling-addresses' ),
			'settings'      => array( 'settings', $settings, 'swiftling-settings' ),
			'overview'      => array( 'overview', array(), '' ),
			'translate-all' => array( 'translate-all', array(), '' ),
			'log'           => array( 'log', array(), '' ),
		);
	}

	/**
	 * A screen of a group is titled with its group, as the menu names it, and its own part with the
	 * name it has in the list beside it, so the title and the marked screen agree. Its own header has
	 * no path, as the group's title says where it is.
	 *
	 * @dataProvider data_section_titles
	 *
	 * @param string $view  View.
	 * @param string $group Name of the group.
	 * @param string $title Title of the screen.
	 */
	public function test_screen_says_its_group_and_name( $view, $group, $title ) {
		$this->become( 'administrator' );
		$this->set_languages( array( 'de_DE' => 'de' ) );
		$_GET = array();

		$xpath = $this->screen_xpath( $view );
		$this->assertSame( 1, $xpath->query( '//h1' )->length );
		$this->assertSame( $group, trim( $xpath->query( '//h1' )->item( 0 )->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- A property of PHP's DOMNode.
		$this->assertSame( $title, trim( $xpath->query( '//h2[contains(concat(" ", @class, " "), " swiftling-title ")]' )->item( 0 )->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- A property of PHP's DOMNode.
		$this->assertSame( 0, $xpath->query( '//*[contains(concat(" ", @class, " "), " swiftling-breadcrumb ")]' )->length );
	}

	/**
	 * Screens of a group, the names of their groups and their titles. The switcher's title says
	 * what it is about, where its name in the list is short.
	 *
	 * @return array
	 */
	public function data_section_titles() {
		return array(
			'content'        => array( 'content', 'Translations', 'Pages & posts' ),
			'terms'          => array( 'terms', 'Translations', 'Categories & tags' ),
			'menus'          => array( 'menus', 'Translations', 'Menus' ),
			'templates'      => array( 'templates', 'Translations', 'Templates' ),
			'forms'          => array( 'forms', 'Translations', 'Forms' ),
			'comments'       => array( 'comments', 'Translations', 'Comments' ),
			'themes-plugins' => array( 'themes-plugins', 'Translations', 'Themes & plugins' ),
			'languages'      => array( 'languages', 'Settings', 'Languages' ),
			'addresses'      => array( 'addresses', 'Settings', 'URLs' ),
			'service'        => array( 'service', 'Settings', 'Translation service' ),
			'switcher'       => array( 'switcher', 'Settings', 'Language switcher' ),
			'settings'       => array( 'settings', 'Settings', 'General' ),
		);
	}

	/**
	 * A screen of its own keeps its path above its title, which is the page's title.
	 */
	public function test_screen_of_its_own_keeps_its_header() {
		$this->become( 'administrator' );
		$_GET  = array();
		$xpath = $this->screen_xpath( 'log' );
		$this->assertSame( 'Logs', trim( $xpath->query( '//h1' )->item( 0 )->textContent ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- A property of PHP's DOMNode.
		$this->assertSame( 1, $xpath->query( '//p[@class="swiftling-breadcrumb"]' )->length );
	}

	/**
	 * A screen, rendered and read as a document.
	 *
	 * @param string $view View.
	 * @return \DOMXPath
	 */
	private function screen_xpath( $view ) {
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $this->render_screen( $view ) );
		libxml_clear_errors();
		return new \DOMXPath( $document );
	}
}
