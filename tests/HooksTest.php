<?php
/**
 * Tests of the hooks SwiftLing offers other plugins, SwiftLing Pro first among them.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;

/**
 * Another plugin adds screens and groups to SwiftLing's menu and loads its assets on them; every
 * hook SwiftLing offers is in docs/hooks.md.
 */
class HooksTest extends TestCase {

	/**
	 * The view file of the screen the tests add.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * The screen the tests add, as another plugin would.
	 */
	public function set_up() {
		parent::set_up();
		$this->file = __DIR__ . '/fixtures/added-screen.php';
	}

	/**
	 * Adds a screen to the menu through the filter.
	 *
	 * @param array $screen The screen, without its file.
	 */
	private function add_screen( $screen ) {
		$file = $this->file;
		add_filter(
			'swiftling_admin_screens',
			static function ( $screens ) use ( $screen, $file ) {
				$screens['swiftling-glossary'] = $screen + array(
					'menu' => 'Glossary',
					'view' => 'glossary',
					'file' => $file,
				);
				return $screens;
			}
		);
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
		return array_combine( wp_list_pluck( $submenu['swiftling'], 2 ), wp_list_pluck( $submenu['swiftling'], 0 ) );
	}

	/**
	 * SwiftLing says which version of these hooks it offers, so a plugin can tell whether it has
	 * what it needs.
	 */
	public function test_hooks_have_a_version() {
		$this->assertSame( 26, SWIFTLING_HOOKS );
	}

	/**
	 * A screen of another plugin joins a group: it is in the list beside the group's screens, in its
	 * part, out of the menu but at its own address, and shows its own view under the group's header.
	 */
	public function test_screen_joins_a_group() {
		$this->become( 'administrator' );
		$this->add_screen(
			array(
				'group' => 'settings',
				'part'  => 'swiftling',
				'icon'  => 'M2 4h12M2 8h12',
				'tint'  => 'blue',
			)
		);

		$this->assertArrayNotHasKey( 'swiftling-glossary', $this->menu_items() );
		$this->assertNotNull( get_plugin_page_hook( 'swiftling-glossary', 'swiftling' ) );

		$_GET = array();
		$html = $this->render_screen( 'glossary' );
		$this->assertStringContainsString( 'A screen of another plugin.', $html );
		$this->assertStringContainsString( '<h1 class="swiftling-title">Settings</h1>', $html );
		$this->assertMatchesRegularExpression( '~SwiftLing</p>.*?page=swiftling-glossary" aria-current="page"~s', $html, 'In the part SwiftLing, marked' );
	}

	/**
	 * A screen of another plugin without a group is an item of the menu; a group of its own is one
	 * item under the group's name.
	 */
	public function test_screen_and_group_in_the_menu() {
		$this->become( 'administrator' );
		$this->add_screen( array() );
		$this->assertSame( 'Glossary', $this->menu_items()['swiftling-glossary'] );

		remove_all_filters( 'swiftling_admin_screens' );
		add_filter(
			'swiftling_admin_groups',
			static function ( $groups ) {
				$groups['pro'] = array(
					'name'  => 'Pro',
					'parts' => array( 'account' => 'Account' ),
				);
				return $groups;
			}
		);
		$this->add_screen(
			array(
				'group' => 'pro',
				'part'  => 'account',
			)
		);
		$items = $this->menu_items();
		$this->assertSame( 'Pro', $items['swiftling-glossary'] );
		$this->assertSame( 'Overview', $items['swiftling'] );
	}

	/**
	 * A screen that lacks a name, a view or a readable file is left out, and one that names a group
	 * SwiftLing does not have is a screen of its own.
	 */
	public function test_broken_screens() {
		add_filter(
			'swiftling_admin_screens',
			static function ( $screens ) {
				$screens['swiftling-nameless'] = array( 'view' => 'nameless' );
				$screens['swiftling-no-file']  = array(
					'menu' => 'No file',
					'view' => 'no-file',
					'file' => '/nowhere/screen.php',
				);
				$screens['swiftling-lost']     = array(
					'menu'  => 'Lost',
					'view'  => 'lost',
					'group' => 'nowhere',
					'part'  => 'nowhere',
				);
				return $screens;
			}
		);
		$screens = Menu::screens();
		$this->assertArrayNotHasKey( 'swiftling-nameless', $screens );
		$this->assertArrayNotHasKey( 'swiftling-no-file', $screens );
		$this->assertArrayNotHasKey( 'group', $screens['swiftling-lost'] );
		$this->assertArrayNotHasKey( 'part', $screens['swiftling-lost'] );
	}

	/**
	 * The assets of a screen are loaded where SwiftLing loads its own, and another plugin loads its
	 * own there, knowing which screen it is.
	 */
	public function test_screen_assets() {
		$seen = array();
		add_action(
			'swiftling_admin_screen_assets',
			static function ( $slug, $screen ) use ( &$seen ) {
				$seen[] = array( $slug, $screen['view'] );
			},
			10,
			2
		);
		( new Menu() )->enqueueAssets( Menu::screens()['swiftling-languages'] + array( 'slug' => 'swiftling-languages' ) );
		$this->assertSame( array( array( 'swiftling-languages', 'languages' ) ), $seen );
	}

	/**
	 * SwiftLing alone runs Translate everything one way, without tabs; another plugin adds a way,
	 * and the screen shows both as tabs, the added one printed by the plugin's file.
	 */
	public function test_translate_all_modes() {
		$this->become( 'administrator' );
		\SwiftLing\Tests\FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$_GET = array();
		$html = $this->render_screen( 'translate-all' );
		$this->assertStringNotContainsString( 'swiftling-all-modes', $html, 'No tabs in SwiftLing alone' );
		$this->assertSame( array( 'fill' ), array_keys( swiftling_translate_all_modes() ) );

		$file = __DIR__ . '/fixtures/added-mode.php';
		add_filter(
			'swiftling_translate_all_modes',
			static function ( $modes ) use ( $file ) {
				$modes['again'] = array(
					'label'       => 'Translate again',
					'description' => 'Translate a language again.',
					'hint'        => 'Replace what is translated with a new translation.',
					'icon'        => 'M2 8h12',
					'tint'        => 'violet',
					'file'        => $file,
				);
				return $modes;
			}
		);
		$html = $this->render_screen( 'translate-all' );
		// Two cards, each with its picture, name and what it does; the current one marked.
		$this->assertMatchesRegularExpression( '~<nav class="swiftling-all-modes"[^>]*>\s*<a class="swiftling-all-mode is-current" aria-current="page"[^>]*>.*?<strong>Fill empty texts</strong>\s*<span class="swiftling-muted">Translate the texts that have no translation yet\.</span>~s', $html );
		$this->assertMatchesRegularExpression( '~<a class="swiftling-all-mode" href="[^"]*page=swiftling-translate-all&(amp;|#038;)?mode=again">\s*<span class="swiftling-rail__tile is-violet" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><path d="M2 8h12"/></svg></span>.*?<strong>Translate again</strong>\s*<span class="swiftling-muted">Replace what is translated with a new translation\.</span>~s', $html );

		$_GET = array( 'mode' => 'again' );
		$html = $this->render_screen( 'translate-all' );
		$this->assertStringContainsString( 'Translated again by another plugin.', $html );
		$this->assertStringContainsString( 'Translate a language again.', $html );
		$this->assertStringNotContainsString( 'swiftling-all-config', $html, 'Not the way SwiftLing fills' );
	}

	/**
	 * Another plugin prints a card of its own at the top of the side column of Translate
	 * everything, above How it works, once a service is connected; not before, when the screen only
	 * asks for one.
	 */
	public function test_translate_all_aside() {
		$this->become( 'administrator' );
		add_action(
			'swiftling_translate_all_aside',
			static function () {
				echo '<section class="added-card">A card of another plugin.</section>';
			}
		);
		$_GET = array();
		$this->assertStringNotContainsString( 'added-card', $this->render_screen( 'translate-all' ), 'No service yet' );

		\SwiftLing\Tests\FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$html = $this->render_screen( 'translate-all' );
		$this->assertMatchesRegularExpression( '~<aside class="swiftling-translate-all__aside swiftling-stack">\s*<section class="added-card">A card of another plugin\.</section>\s*<section class="swiftling-card swiftling-side" aria-labelledby="swiftling-all-how-title">~', $html );
	}

	/**
	 * Another plugin prints actions in the head of the translation editor, knowing the subject and
	 * the language.
	 */
	public function test_editor_actions() {
		$this->become( 'administrator' );
		$this->set_languages();
		$post = self::factory()->post->create( array( 'post_title' => 'Kontakt' ) );
		add_action(
			'swiftling_editor_actions',
			static function ( $subject, $language ) {
				echo '<button id="added-action">' . esc_html( $subject['key'] . ' ' . $language['locale'] ) . '</button>';
			},
			10,
			2
		);
		$_GET = array(
			'post' => (string) $post,
			'lang' => 'de',
		);
		$this->assertStringContainsString( '<button id="added-action">post:' . $post . ' de_DE</button>', $this->render_screen( 'editor' ) );
	}

	/**
	 * Every hook SwiftLing offers is described in docs/hooks.md, and every hook described there is
	 * one SwiftLing offers.
	 */
	public function test_every_hook_is_documented() {
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SWIFTLING_DIR, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( '.php' !== substr( $path, -4 ) || preg_match( '~/(vendor|tests|node_modules)/~', $path ) ) {
				continue;
			}
			preg_match_all( "~(?:apply_filters|do_action)\\(\\s*'(swiftling_[a-z0-9_]+)'~", (string) file_get_contents( $path ), $matches ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own source.
			foreach ( $matches[1] as $hook ) {
				$found[ $hook ] = true;
			}
		}
		ksort( $found );

		preg_match_all( '~^### `(swiftling_[a-z0-9_]+)`~m', (string) file_get_contents( SWIFTLING_DIR . 'docs/hooks.md' ), $documented ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading the plugin's own documentation.
		$documented = array_fill_keys( $documented[1], true );
		ksort( $documented );

		$this->assertSame( array_keys( $found ), array_keys( $documented ) );
	}
}
