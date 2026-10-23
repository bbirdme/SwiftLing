<?php
/**
 * Tests of the language switcher.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Switcher\Flags;
use SwiftLing\Switcher\NavMenu;
use SwiftLing\Switcher\Settings;
use SwiftLing\Switcher\Switcher;

/**
 * The switcher in every place a site can put one, and what it lists.
 */
class SwitcherTest extends TestCase {

	/**
	 * Saves the Switcher screen's settings.
	 *
	 * @param array $settings Settings.
	 */
	private function settings( $settings ) {
		update_option( Settings::OPTION, $settings );
		Settings::forget();
	}

	/**
	 * Without options, a switcher is a dropdown of names, current language included.
	 */
	public function test_default_options() {
		$this->assertSame(
			array(
				'style'     => 'dropdown',
				'flag'      => false,
				'name'      => true,
				'code'      => false,
				'current'   => true,
				'class'     => '',
				'flag_size' => 'medium',
			),
			Switcher::args( array() )
		);
		$args = Switcher::args(
			array(
				'style' => 'sideways',
				'name'  => false,
			)
		);
		$this->assertSame( 'dropdown', $args['style'] );
		$this->assertTrue( $args['name'], 'A switcher showing nothing shows names' );

		$this->settings(
			array(
				'show' => array(
					'flag' => true,
					'name' => false,
				),
			)
		);
		$this->assertSame( array( true, false ), array( Switcher::args( array() )['flag'], Switcher::args( array() )['name'] ) );
	}

	/**
	 * With one language there is nothing to switch to.
	 */
	public function test_nothing_to_switch_to() {
		$this->set_languages( array() );
		$this->assertSame( '', Switcher::render() );
	}

	/**
	 * Another plugin adds to a switcher's HTML, knowing its options, as SwiftLing Pro adds the
	 * currency switcher beside it; a place without a switcher stays empty.
	 */
	public function test_html_filter() {
		add_filter(
			'swiftling_switcher_html',
			static function ( $html, $args ) {
				return $html . '<span class="added">' . $args['style'] . '</span>';
			},
			10,
			2
		);
		$this->assertStringEndsWith( '</div><span class="added">horizontal</span>', Switcher::render( array( 'style' => 'horizontal' ) ) );

		$this->set_languages( array() );
		$this->assertSame( '', Switcher::render(), 'Nothing to switch to: nothing at all' );
	}

	/**
	 * A list links every language, marks the current one and is never translated itself.
	 */
	public function test_list() {
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringContainsString( 'class="swiftling-switcher is-horizontal"', $html );
		$this->assertStringContainsString( 'translate="no"', $html );
		$this->assertSame( 3, substr_count( $html, '<li ' ) );
		$this->assertStringContainsString( 'href="http://example.org/?lang=de"', $html );
		$this->assertStringContainsString( 'href="http://example.org/" data-locale="hr" aria-current="true"', $html );
		$this->assertStringContainsString( '<span lang="de-DE">Deutsch</span>', $html );

		$html = Switcher::render(
			array(
				'style'   => 'vertical',
				'current' => false,
			)
		);
		$this->assertSame( 2, substr_count( $html, '<li ' ) );
		$this->assertStringNotContainsString( 'aria-current', $html );
	}

	/**
	 * A dropdown shows the current language and opens the others.
	 */
	public function test_dropdown() {
		$this->show_in( 'de' );
		$html = Switcher::render();
		$this->assertStringContainsString( '<details class="swiftling-switcher__dropdown">', $html );
		$this->assertMatchesRegularExpression( '~<summary[^>]*data-locale="de-DE"[^>]*>.*Deutsch.*</summary>~', $html );
		$this->assertSame( 2, substr_count( $html, '<li ' ) );
		$this->assertStringContainsString( 'href="http://example.org/"', $html );
		$this->assertStringContainsString( 'href="http://example.org/?lang=fr"', $html );
	}

	/**
	 * A name the user wrote is escaped like any other text.
	 */
	public function test_names_are_escaped() {
		$this->set_languages(
			array(
				'de_DE' => array(
					'slug'  => 'de',
					'label' => '<img src=x onerror=alert(1)>',
				),
			)
		);
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
	}

	/**
	 * A language hidden from visitors is listed only for people who can edit content, with a note.
	 */
	public function test_hidden_language() {
		$this->set_languages(
			array(
				'de_DE' => array(
					'slug'    => 'de',
					'visible' => false,
				),
				'fr_FR' => 'fr',
			)
		);
		$this->assertStringNotContainsString( 'lang=de', Switcher::render( array( 'style' => 'horizontal' ) ) );

		$this->become( 'editor' );
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringContainsString( 'lang=de', $html );
		$this->assertStringContainsString( 'is-hidden-language', $html );
		$this->assertStringContainsString( 'title="Hidden from visitors.', $html );
	}

	/**
	 * On a post hidden in a language because it is not translated, that language is left out.
	 */
	public function test_hidden_post_leaves_its_languages_out() {
		update_option( 'swiftling_settings', array( 'untranslated' => array( 'post' => 'hide' ) ) );
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
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'fr', 'Contact' );
		swiftling_refresh_translated( array( $post->ID ) );

		$this->go_to( get_permalink( $post ) );
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringContainsString( 'lang=fr', $html );
		$this->assertStringNotContainsString( 'lang=de', $html );
	}

	/**
	 * Flags: chosen per language, the country of the locale, a language's usual country, or none.
	 */
	public function test_flags() {
		$this->assertStringEndsWith( 'assets/flags/de.svg', Flags::url( 'de_DE' ) );
		$this->assertSame( 'at', Flags::automatic( 'de_AT' ) );
		$this->assertSame( 'hr', Flags::automatic( 'hr' ) );
		$this->assertSame( '', Flags::automatic( 'xx_ZZ' ), 'No such flag file' );

		$this->settings(
			array(
				'flags' => array(
					'de_DE' => array( 'country' => 'none' ),
					'fr_FR' => array( 'image' => 'https://example.org/fr.png' ),
				),
			)
		);
		$this->assertSame( '', Flags::url( 'de_DE' ) );
		$this->assertSame( 'https://example.org/fr.png', Flags::url( 'fr_FR' ) );

		$html = Switcher::render(
			array(
				'style' => 'horizontal',
				'flag'  => true,
				'name'  => false,
			)
		);
		$this->assertStringContainsString( 'src="https://example.org/fr.png" alt="Français"', $html, 'A flag alone names the language for screen readers' );
		$this->assertStringContainsString( '<span class="swiftling-switcher__code">DE</span>', $html, 'A language without a flag shows its code instead' );
	}

	/**
	 * Flags::sanitize() keeps only the languages the site has, and a country the site has flags for,
	 * "none" or an image address, which is what both screens post.
	 */
	public function test_flags_are_sanitized() {
		$clean = Flags::sanitize(
			array(
				'de_DE' => array(
					'country' => 'AT',
					'image'   => '',
				),
				'fr_FR' => array(
					'country' => 'none',
					'image'   => '',
				),
				'it_IT' => array(
					'country' => 'zz',
					'image'   => '',
				),
				'es_ES' => array(
					'country' => '',
					'image'   => 'javascript:alert(1)',
				),
				'pt_PT' => array(
					'country' => '',
					'image'   => ' https://example.org/pt.png ',
				),
				'xx_XX' => array( 'country' => 'de' ),
				'hr'    => 'not a row',
			),
			array( 'de_DE', 'fr_FR', 'it_IT', 'es_ES', 'pt_PT', 'hr' )
		);
		$this->assertSame(
			array(
				'de_DE' => array(
					'country' => 'at',
					'image'   => '',
				),
				'fr_FR' => array(
					'country' => 'none',
					'image'   => '',
				),
				'pt_PT' => array(
					'country' => '',
					'image'   => 'https://example.org/pt.png',
				),
			),
			$clean
		);
	}

	/**
	 * Flags come in three sizes. The Switcher screen sets the size of every switcher; a block,
	 * shortcode, widget or theme can ask for another. Medium is the size flags always had.
	 */
	public function test_flag_size() {
		$flags = static function ( $args ) {
			preg_match_all( '~<img class="([^"]+)"[^>]* width="(\d+)" height="(\d+)"~', Switcher::render( $args + array( 'flag' => true ) ), $found, PREG_SET_ORDER );
			return array_unique( array_map( static fn( $img ) => $img[1] . ' ' . $img[2] . 'x' . $img[3], $found ) );
		};

		$this->assertSame( array( 'swiftling-switcher__flag 18x12' ), $flags( array() ), 'Medium, as flags always were' );
		$this->assertSame( array( 'swiftling-switcher__flag is-large 27x18' ), $flags( array( 'flag_size' => 'large' ) ) );
		$this->assertSame( array( 'swiftling-switcher__flag is-small 15x10' ), $flags( array( 'flag_size' => 'small' ) ) );
		$this->assertSame( 'medium', Switcher::args( array( 'flag_size' => 'huge' ) )['flag_size'], 'A size it does not know is the default' );

		// The Switcher screen's size is every switcher's, unless one asks for another.
		$this->settings( array( 'show' => array( 'flag_size' => 'large' ) ) );
		$this->assertSame( array( 'swiftling-switcher__flag is-large 27x18' ), $flags( array() ) );
		$this->assertSame( array( 'swiftling-switcher__flag is-small 15x10' ), $flags( array( 'flag_size' => 'small' ) ) );
		$this->settings( array( 'show' => array( 'flag_size' => 'huge' ) ) );
		$this->assertSame( 'medium', Settings::get()['show']['flag_size'], 'A size saved by hand that it does not know is the default' );

		$this->settings( array() );
		$this->assertStringContainsString( 'swiftling-switcher__flag is-large', do_shortcode( '[swiftling_switcher flags="yes" flag_size="large"]' ) );
		$this->assertStringContainsString( 'swiftling-switcher__flag is-small', do_blocks( '<!-- wp:swiftling/language-switcher {"flag":true,"flagSize":"small"} /-->' ) );

		$widget   = new \SwiftLing\Switcher\Widget();
		$instance = $widget->update(
			array(
				'flag'      => '1',
				'flag_size' => 'large',
			),
			array()
		);
		$this->assertSame( 'large', $instance['flag_size'] );
		$this->assertSame( 'medium', $widget->update( array( 'flag_size' => 'huge' ), array() )['flag_size'] );
		ob_start();
		$widget->widget(
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			),
			$instance
		);
		$this->assertStringContainsString( 'swiftling-switcher__flag is-large', ob_get_clean() );
	}

	/**
	 * The Switcher screen saves the size of flags, and only one it knows.
	 */
	public function test_save_flag_size() {
		$this->become( 'administrator' );
		$save = array( new Settings(), 'save' );

		$this->post_form(
			'swiftling_save_switcher',
			array(
				'show' => array(
					'flag'      => '1',
					'flag_size' => 'large',
				),
			)
		);
		$this->redirect_of( $save );
		$saved = get_option( Settings::OPTION );
		$this->assertSame( 'large', $saved['show']['flag_size'] );
		$this->assertTrue( $saved['show']['flag'] );

		$this->post_form( 'swiftling_save_switcher', array( 'show' => array( 'flag_size' => 'huge' ) ) );
		$this->redirect_of( $save );
		$this->assertSame( 'medium', get_option( Settings::OPTION )['show']['flag_size'] );
	}

	/**
	 * Languages can be named in the page's language too.
	 */
	public function test_names_in_the_page_language() {
		if ( ! class_exists( 'Locale' ) ) {
			$this->markTestSkipped( 'PHP has no intl extension.' );
		}
		$this->set_languages( array( 'de_DE' => 'de' ), 'parameter', array( 'names' => 'both' ) );
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringContainsString( '<span lang="de-DE">Deutsch</span> <span class="swiftling-switcher__translated">(Njemački)</span>', $html );
	}

	/**
	 * A name written for a language on the Languages screen, for the language of a page, wins over
	 * the one intl gives, also beside a name of its own in the switcher; it names also a language
	 * intl does not know. Names in their own language leave it out.
	 */
	public function test_names_written_for_each_page_language() {
		$targets = array(
			'de_DE' => array(
				'slug'  => 'de',
				'label' => 'Deutsch (DE)',
			),
			'fr_FR' => 'fr',
			'xx_ZZ' => array(
				'slug' => 'xx',
				'name' => 'Xanadu',
			),
		);
		$written = array(
			'de_DE' => array( 'hr' => 'Njemački jezik' ),
			'xx_ZZ' => array(
				'hr'    => 'Ksanadski',
				'de_DE' => 'Xanadisch',
			),
		);
		$this->set_languages(
			$targets,
			'parameter',
			array(
				'names'             => 'page',
				'name_translations' => $written,
			)
		);
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringContainsString( '<span class="swiftling-switcher__name">Njemački jezik</span>', $html, 'Written, beside a name of its own' );
		$this->assertStringContainsString( '<span class="swiftling-switcher__name">Ksanadski</span>', $html, 'A language intl does not know' );
		if ( class_exists( 'Locale' ) ) {
			$this->assertStringContainsString( '<span class="swiftling-switcher__name">Francuski</span>', $html, 'Not written: the automatic name' );
		}

		$this->show_in( 'de' );
		$this->assertStringContainsString( '<span class="swiftling-switcher__name">Xanadisch</span>', Switcher::render( array( 'style' => 'horizontal' ) ) );

		$this->set_languages(
			$targets,
			'parameter',
			array(
				'names'             => 'both',
				'name_translations' => $written,
			)
		);
		$this->show_in( '' );
		$this->assertStringContainsString( '<span lang="de-DE">Deutsch (DE)</span> <span class="swiftling-switcher__translated">(Njemački jezik)</span>', Switcher::render( array( 'style' => 'horizontal' ) ) );

		$this->set_languages(
			$targets,
			'parameter',
			array(
				'names'             => 'native',
				'name_translations' => $written,
			)
		);
		$html = Switcher::render( array( 'style' => 'horizontal' ) );
		$this->assertStringNotContainsString( 'Njemački jezik', $html );
		$this->assertStringContainsString( '<span lang="de-DE">Deutsch (DE)</span>', $html );
	}

	/**
	 * The shortcode reads its options in a person's words.
	 */
	public function test_shortcode() {
		$html = do_shortcode( '[swiftling_switcher style="vertical" flags="yes" names="no"]' );
		$this->assertStringContainsString( 'is-vertical is-shortcode', $html );
		$this->assertStringContainsString( 'swiftling-switcher__flag', $html );
		$this->assertStringNotContainsString( 'swiftling-switcher__name', $html );
	}

	/**
	 * The block renders on the server, with the block's classes joining the switcher's.
	 */
	public function test_block() {
		$html = do_blocks( '<!-- wp:swiftling/language-switcher {"type":"horizontal"} /-->' );
		$this->assertStringContainsString( 'class="swiftling-switcher is-horizontal is-block wp-block-swiftling-language-switcher"', $html );
		$this->assertContains( 'swiftling/language-switcher', apply_filters( 'block_core_navigation_listable_blocks', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
	}

	/**
	 * Themes print a switcher with an action, which prints nothing when SwiftLing is not active.
	 */
	public function test_php_action() {
		ob_start();
		do_action( 'swiftling_language_switcher', array( 'style' => 'vertical' ) );
		$this->assertStringContainsString( 'is-vertical', ob_get_clean() );
		$this->assertStringContainsString( 'is-vertical', swiftling_get_language_switcher( array( 'style' => 'vertical' ) ) );
	}

	/**
	 * The footer prints a switcher only when asked; floating in a corner it opens as a dropdown.
	 */
	public function test_footer() {
		$footer = new \SwiftLing\Switcher\Footer();
		ob_start();
		$footer->render();
		$this->assertSame( '', ob_get_clean() );

		$this->settings(
			array(
				'footer' => array(
					'enabled'  => true,
					'position' => 'floating-left',
					'style'    => 'horizontal',
				),
			)
		);
		ob_start();
		$footer->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'is-dropdown is-footer is-floating is-floating-left', $html );
	}

	/**
	 * The switcher of the footer brings its stylesheet, and the dropdown its script, also when it is
	 * the only switcher of the page. Printed at the end of the page, it came after WordPress had
	 * printed the stylesheets and scripts of the footer, so it showed as a bare list, and the
	 * floating one sat at the end of the page instead of in a corner. On a site with a switcher in
	 * the menu too, the menu brought the stylesheet in time and hid that.
	 *
	 * The page renders as a theme renders it: wp_head, then wp_footer.
	 *
	 * @dataProvider footer_positions
	 *
	 * @param string $position Position on the Switcher screen.
	 * @param string $style    Style on the Switcher screen.
	 * @param bool   $script   Whether the switcher is a dropdown, which needs the script.
	 */
	public function test_footer_brings_its_stylesheet( $position, $style, $script ) {
		$this->settings(
			array(
				'footer' => array(
					'enabled'  => true,
					'position' => $position,
					'style'    => $style,
				),
			)
		);
		$this->go_to( home_url( '/' ) );
		// A fresh queue, as on a new request; the stylesheet and script are registered on init.
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of stylesheets.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
		Switcher::registerAssets();

		ob_start();
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own actions, as a theme fires them.
		do_action( 'wp_head' );
		do_action( 'wp_footer' );
		// phpcs:enable
		$page = ob_get_clean();

		$this->assertStringContainsString( 'class="swiftling-switcher is-', $page, 'The footer prints its switcher' );
		$this->assertStringContainsString( "id='swiftling-switcher-css'", $page );
		$this->assertSame( $script, (bool) preg_match( '/id=.swiftling-switcher-js./', $page ) );
	}

	/**
	 * Positions and styles of the footer's switcher.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public function footer_positions() {
		return array(
			'end of the page, a list'   => array( 'end', 'horizontal', false ),
			'end of the page, dropdown' => array( 'end', 'dropdown', true ),
			'floating right'            => array( 'floating-right', 'horizontal', true ),
			'floating left'             => array( 'floating-left', 'dropdown', true ),
		);
	}

	/**
	 * A globe in the menu leads nowhere: it opens the other languages, never the one shown, which
	 * stay its submenu. With the codes on, the code of the language shown stands beside it. For
	 * screen readers it is a button that opens a list.
	 */
	public function test_menu_globe() {
		$menu = wp_create_nav_menu( 'Glavni' );
		$this->settings(
			array(
				'show' => array( 'code' => true ),
				'menu' => array(
					'menus'    => array( $menu ),
					'position' => 'last',
					'style'    => 'globe',
				),
			)
		);
		$items = ( new NavMenu() )->addItems(
			array(),
			(object) array(
				'menu'  => $menu,
				'depth' => 0,
			)
		);
		$this->assertCount( 3, $items, 'The globe, German and French' );
		$globe = $items[0];
		$this->assertSame( '#', $globe->url );
		$this->assertContains( 'swiftling-menu-language-globe', $globe->classes );
		$this->assertContains( 'menu-item-has-children', $globe->classes );
		$this->assertStringContainsString( '<svg', $globe->title );
		$this->assertStringContainsString( '>HR<', $globe->title );
		$this->assertSame( array( $globe->db_id, $globe->db_id ), array( $items[1]->menu_item_parent, $items[2]->menu_item_parent ) );
		$this->assertSame( array( 'http://example.org/?lang=de', 'http://example.org/?lang=fr' ), array( $items[1]->url, $items[2]->url ) );

		$atts = ( new NavMenu() )->linkAttributes( array( 'href' => '#' ), $globe );
		$this->assertSame( 'button', $atts['role'] );
		$this->assertSame( 'true', $atts['aria-haspopup'] );
		$this->assertSame( 'false', $atts['aria-expanded'] );
		$this->assertSame( 'Choose a language', $atts['aria-label'] );

		// Saved from the Switcher screen.
		$this->assertSame( 'globe', \SwiftLing\Switcher\Settings::get()['menu']['style'] );
	}

	/**
	 * A menu the Switcher screen names gets the languages as items.
	 */
	public function test_menu_items() {
		$menu = wp_create_nav_menu( 'Glavni' );
		$this->settings(
			array(
				'menu' => array(
					'menus'    => array( $menu ),
					'position' => 'last',
					'style'    => 'list',
				),
			)
		);
		$items = ( new NavMenu() )->addItems(
			array(),
			(object) array(
				'menu'  => $menu,
				'depth' => 0,
			)
		);
		$this->assertCount( 3, $items );
		$this->assertSame( 'http://example.org/?lang=de', $items[1]->url );

		$other = wp_create_nav_menu( 'Podnožje' );
		$this->assertSame(
			array(),
			( new NavMenu() )->addItems(
				array(),
				(object) array(
					'menu'  => $other,
					'depth' => 0,
				)
			)
		);
	}

	/**
	 * The menu, the end of the page and everything else (block, widget, shortcode, PHP) each show
	 * what the Switcher screen chose for them. A site saved before they had their own takes what it
	 * saved for all of them, so nothing changes until the user chooses.
	 */
	public function test_each_place_shows_its_own() {
		$legacy = array(
			'flag'      => true,
			'name'      => false,
			'code'      => true,
			'current'   => false,
			'flag_size' => 'large',
		);
		$this->settings( array( 'show' => $legacy ) );
		$this->assertSame( $legacy, Settings::show( 'menu' ), 'The menu takes the old settings' );
		$this->assertSame( $legacy, Settings::show( 'footer' ), 'The end of the page takes the old settings' );
		$this->assertSame( $legacy, Settings::show( '' ) );

		$menu = wp_create_nav_menu( 'Glavni' );
		$this->settings(
			array(
				'show'   => $legacy,
				'menu'   => array(
					'menus' => array( $menu ),
					'style' => 'list',
					'show'  => array(
						'flag' => false,
						'name' => true,
					),
				),
				'footer' => array(
					'enabled'  => true,
					'position' => 'end',
					'style'    => 'horizontal',
					'show'     => array(
						'flag'      => true,
						'name'      => true,
						'flag_size' => 'small',
						'current'   => 'yes',
					),
				),
			)
		);
		$this->assertSame(
			array(
				'flag'      => false,
				'name'      => true,
				'code'      => false,
				'current'   => false,
				'flag_size' => 'medium',
			),
			Settings::show( 'menu' ),
			'What the menu does not save is off, not the old settings'
		);
		$this->assertTrue( Settings::show( 'footer' )['current'] );

		// The menu.
		$items = ( new NavMenu() )->addItems(
			array(),
			(object) array(
				'menu'  => $menu,
				'depth' => 0,
			)
		);
		$this->assertStringNotContainsString( 'swiftling-switcher__flag', $items[1]->title );
		$this->assertStringContainsString( 'swiftling-switcher__name', $items[1]->title );

		// The end of the page.
		ob_start();
		( new \SwiftLing\Switcher\Footer() )->render();
		$footer = ob_get_clean();
		$this->assertStringContainsString( 'swiftling-switcher__flag is-small', $footer );
		$this->assertStringContainsString( 'swiftling-switcher__name', $footer );
		$this->assertStringNotContainsString( 'swiftling-switcher__code', $footer );

		// Everything else.
		$shortcode = do_shortcode( '[swiftling_switcher style="horizontal"]' );
		$this->assertStringContainsString( 'swiftling-switcher__flag is-large', $shortcode );
		$this->assertStringNotContainsString( 'swiftling-switcher__name', $shortcode );
	}

	/**
	 * The Switcher screen saves what each place shows, and comes back to the tab it was on.
	 */
	public function test_save_each_place() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_save_switcher',
			array(
				'tab'    => 'footer',
				'show'   => array( 'code' => '1' ),
				'menu'   => array(
					'show' => array(
						'flag'      => '1',
						'flag_size' => 'small',
					),
				),
				'footer' => array(
					'enabled' => '1',
					'show'    => array(
						'name'      => '1',
						'flag_size' => 'huge',
					),
				),
			)
		);
		$location = $this->redirect_of( array( new Settings(), 'save' ) );
		$saved    = get_option( Settings::OPTION );
		$this->assertSame(
			array(
				'flag'      => false,
				'name'      => false,
				'code'      => true,
				'current'   => false,
				'flag_size' => 'medium',
			),
			$saved['show']
		);
		$this->assertTrue( $saved['menu']['show']['flag'] );
		$this->assertSame( 'small', $saved['menu']['show']['flag_size'] );
		$this->assertTrue( $saved['footer']['show']['name'] );
		$this->assertSame( 'medium', $saved['footer']['show']['flag_size'], 'A size it does not know' );
		$this->assertStringContainsString( 'swiftling_tab=footer', $location );

		$this->post_form( 'swiftling_save_switcher', array( 'tab' => 'elsewhere"><script>' ) );
		$this->assertStringNotContainsString( 'swiftling_tab', $this->redirect_of( array( new Settings(), 'save' ) ), 'Only a tab it knows' );
	}

	/**
	 * The Switcher screen goes in four steps: where (only places), how it looks (each place's
	 * style and parts), flags, the visitor's language. A menu names the places of the theme it
	 * shows in.
	 */
	public function test_screen_steps() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		register_nav_menus( array( 'swiftling-test-header' => 'Header' ) );
		$menu = wp_create_nav_menu( 'Glavni' );
		set_theme_mod( 'nav_menu_locations', array( 'swiftling-test-header' => $menu ) );

		$html = $this->render_screen( 'switcher' );
		set_current_screen( 'front' );
		unregister_nav_menu( 'swiftling-test-header' );

		$this->assertStringContainsString( 'Shown in: Header', $html );

		$where  = strpos( $html, 'Where visitors find it' );
		$looks  = strpos( $html, 'How it looks' );
		$flags  = strpos( $html, '>Flags</h2>' );
		$people = strpos( $html, 'The visitor&#039;s language</h2>' );
		$this->assertNotFalse( $where );
		$this->assertGreaterThan( $where, $looks );
		$this->assertGreaterThan( $looks, $flags );
		$this->assertGreaterThan( $flags, $people );

		// Step 1 chooses places only; their styles are in step 2.
		$this->assertGreaterThan( $looks, strpos( $html, 'name="menu[style]"' ) );
		$this->assertGreaterThan( $looks, strpos( $html, 'name="footer[style]"' ) );
		$this->assertLessThan( $looks, strpos( $html, 'name="menu[enabled]"' ) );
		$this->assertLessThan( $looks, strpos( $html, 'name="footer[position]"' ) );
	}

	/**
	 * The menu has a switch of its own: off, no menu shows the languages, and the menus ticked are
	 * kept for when it is on again. A site saved before the switch has it on.
	 */
	public function test_menu_switch() {
		$menu  = wp_create_nav_menu( 'Glavni' );
		$items = function () use ( $menu ) {
			return ( new NavMenu() )->addItems(
				array(),
				(object) array(
					'menu'  => $menu,
					'depth' => 0,
				)
			);
		};

		$this->settings( array( 'menu' => array( 'menus' => array( $menu ) ) ) );
		$this->assertTrue( Settings::get()['menu']['enabled'] );
		$this->assertNotEmpty( $items() );

		$this->settings(
			array(
				'menu' => array(
					'menus'   => array( $menu ),
					'enabled' => false,
				),
			)
		);
		$this->assertSame( array(), $items() );

		$this->become( 'administrator' );
		$this->post_form( 'swiftling_save_switcher', array( 'menu' => array( 'menus' => array( (string) $menu ) ) ) );
		$this->redirect_of( array( new Settings(), 'save' ) );
		$saved = get_option( Settings::OPTION )['menu'];
		$this->assertFalse( $saved['enabled'], 'A switch left off is not in the form' );
		$this->assertSame( array( $menu ), $saved['menus'], 'The menus stay ticked' );

		$this->post_form(
			'swiftling_save_switcher',
			array(
				'menu' => array(
					'enabled' => '1',
					'menus'   => array( (string) $menu ),
				),
			)
		);
		$this->redirect_of( array( new Settings(), 'save' ) );
		$this->assertTrue( get_option( Settings::OPTION )['menu']['enabled'] );
	}

	/**
	 * A switcher that shows only flags is marked, so its dropdown is as narrow as a flag: themes
	 * give a submenu a width of its own, such as 150 or 240 pixels, and a flag sat at the start of it.
	 * With a name or a code beside the flag, it is not.
	 */
	public function test_flag_only() {
		$flag_only = array(
			'flag' => true,
			'name' => false,
			'code' => false,
		);
		$this->assertStringContainsString( 'class="swiftling-switcher is-dropdown is-flag-only"', Switcher::render( $flag_only ) );
		$this->assertStringNotContainsString( 'is-flag-only', Switcher::render( array( 'code' => true ) + $flag_only ) );
		$this->assertStringNotContainsString( 'is-flag-only', Switcher::render( array( 'flag' => false ) + $flag_only ) );

		$menu = wp_create_nav_menu( 'Glavni' );
		$this->settings(
			array(
				'show' => $flag_only,
				'menu' => array(
					'menus' => array( $menu ),
					'style' => 'dropdown',
				),
			)
		);
		$items = ( new NavMenu() )->addItems(
			array(),
			(object) array(
				'menu'  => $menu,
				'depth' => 0,
			)
		);
		$this->assertCount( 3, $items );
		foreach ( $items as $item ) {
			$this->assertContains( 'swiftling-menu-language-flag-only', $item->classes, $item->db_id );
		}

		$this->settings(
			array(
				'show' => array( 'code' => true ) + $flag_only,
				'menu' => array(
					'menus' => array( $menu ),
					'style' => 'globe',
				),
			)
		);
		$items = ( new NavMenu() )->addItems(
			array(),
			(object) array(
				'menu'  => $menu,
				'depth' => 0,
			)
		);
		$this->assertNotContains( 'swiftling-menu-language-flag-only', $items[0]->classes, 'Flags with codes' );
	}

	/**
	 * In a block theme the switcher goes in a menu as the Language switcher block, inside the
	 * Navigation block. The screen says where it is, published, in a menu of the Navigation block or
	 * a template part, instead of "In no menu" and of asking to add it there; a draft does not count.
	 * Without the block it asks as before.
	 */
	public function test_screen_knows_the_block_in_a_navigation() {
		switch_theme( 'twentytwentyfive' );
		$this->become( 'administrator' );
		$screen = function () {
			set_current_screen( 'toplevel_page_swiftling' );
			$html = $this->render_screen( 'switcher' );
			set_current_screen( 'front' );
			return $html;
		};
		$block  = '<!-- wp:navigation-link {"label":"O nama","url":"/o-nama/"} /--><!-- wp:swiftling/language-switcher {"type":"dropdown"} /-->';
		self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'draft',
				'post_title'   => 'Skica',
				'post_content' => $block,
			)
		);

		$html = $screen();
		$this->assertStringContainsString( 'In no menu', $html, 'Without the block, a draft does not count' );
		$this->assertStringContainsString( 'add the Language switcher block to it', $html );

		self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Zaglavlje',
				'post_content' => $block,
			)
		);
		$part = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_title'   => 'Podnožje',
				'post_name'    => 'footer',
				'post_content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:swiftling/language-switcher /--></div><!-- /wp:group -->',
			)
		);
		// The theme the Site Editor saved it for, as it does.
		wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
		$other = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_status'  => 'publish',
				'post_title'   => 'Stara tema',
				'post_content' => '<!-- wp:swiftling/language-switcher /-->',
			)
		);
		wp_set_object_terms( $other, 'twentytwentyfour', 'wp_theme' );

		$html = $screen();
		$this->assertStringNotContainsString( 'In no menu', $html );
		$this->assertStringContainsString( 'As a block in: Zaglavlje and Podnožje', $html );
		$this->assertStringNotContainsString( 'add the Language switcher block to it', $html, 'Not asked to add what is there' );
		$this->assertStringContainsString( 'The Language switcher block is in: Zaglavlje and Podnožje', $html );
		$this->assertStringNotContainsString( 'Stara tema', $html, 'A template part of another theme' );
	}
}
