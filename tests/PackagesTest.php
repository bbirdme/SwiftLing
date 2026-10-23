<?php
/**
 * Tests of the Themes & plugins screen: finding and translating texts of themes and plugins.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use SwiftLing\Packages\Catalogue;
use SwiftLing\Packages\CodeScanner;
use SwiftLing\Packages\Packages;
use SwiftLing\Packages\Recorder;
use SwiftLing\Packages\Settings;
use SwiftLing\Packages\Texts;
use WP_REST_Request;

/**
 * Texts of the active theme, found by a recording or a scan, chosen and translated.
 */
class PackagesTest extends TestCase {

	/**
	 * Package ID of the active theme.
	 *
	 * @var string
	 */
	private $theme = '';

	/**
	 * Runs every test with a known theme active.
	 */
	public function set_up() {
		parent::set_up();
		switch_theme( 'twentytwentyfive' );
		Packages::forget();
		$this->theme = 'theme/twentytwentyfive';
	}

	/**
	 * The active theme is a package, with its text domain; SwiftLing is never one.
	 */
	public function test_packages() {
		$package = Packages::get( $this->theme );
		$this->assertNotNull( $package );
		$this->assertSame( 'theme', $package['type'] );
		$this->assertSame( 'twentytwentyfive', $package['domain'] );
		$this->assertSame( $this->theme, Packages::ofDomain( 'twentytwentyfive' ) );
		$this->assertSame( '', Packages::ofFile( SWIFTLING_DIR . 'swiftling.php' ) );
		$this->assertSame( 'plugin/woocommerce', Packages::ofFile( WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-cart.php' ) );
		$this->assertSame( $this->theme, Packages::ofFile( get_theme_root() . '/twentytwentyfive/functions.php' ) );
		$this->assertSame( '', Packages::ofFile( ABSPATH . WPINC . '/l10n.php' ) );
	}

	/**
	 * A scan of code finds the texts written out in gettext calls, and nothing else.
	 */
	public function test_code_scanner() {
		$code  = <<<'PHP'
<?php
echo __( 'Add to cart', 'shop' );
_e( "Line one\nLine two", 'shop' );
echo esc_html_x( 'Post', 'verb', 'shop' );
printf( _n( '%s item', '%s items', $count, 'shop' ), $count );
echo __( $label, 'shop' );
echo $object->__( 'A method', 'shop' );
echo Thing::__( 'A static method', 'shop' );
echo __( 'WordPress itself', 'default' );
echo \__( 'With a backslash', 'shop' );
echo __( 'Joined ' . 'together', 'shop' );
echo __( 'No domain' );
echo esc_attr__( "It's mine", 'shop' );
echo __( '%1$s %2$s', 'shop' );
echo __( '#', 'shop' );
function __a() {}
PHP;
		$texts = CodeScanner::textsInCode( $code );
		$found = array();
		foreach ( $texts as $text ) {
			$found[] = $text['t'] . ( isset( $text['c'] ) ? ' |' . $text['c'] : '' ) . ( isset( $text['n'] ) ? ' #' . $text['n'] : '' );
			$this->assertSame( 'shop', $text['d'] );
		}
		$this->assertSame(
			array(
				'Add to cart',
				"Line one\nLine two",
				'Post |verb',
				'%s item #1',
				'%s item #2',
				'With a backslash',
				'Joined together',
				"It's mine",
			),
			$found
		);
		$this->assertSame( array(), CodeScanner::textsInCode( '<?php this is not php (' ) );
		$this->assertTrue( Texts::hasWords( '%s items' ) );
		$this->assertTrue( Texts::hasWords( '<strong>%s</strong> sold' ) );
		$this->assertFalse( Texts::hasWords( '%1$s &ndash; %2$s' ) );
		$this->assertFalse( Texts::hasWords( '<br>' ) );
	}

	/**
	 * Scanning a theme's code in steps keeps every text in the catalogue and learns its domain.
	 */
	public function test_code_scan_steps() {
		$this->become( 'administrator' );
		$step = CodeScanner::step( $this->theme, 0 );
		while ( ! $step['done'] ) {
			$step = CodeScanner::step( $this->theme, $step['offset'] );
		}
		$this->assertGreaterThan( 0, $step['total'] );
		$scanned = Catalogue::texts( $this->theme, 'scanned' );
		$this->assertNotEmpty( $scanned );
		$this->assertSame( 'twentytwentyfive', $scanned[0]['d'] );
		$this->assertNotEmpty( CodeScanner::scans()[ $this->theme ]['finished'] );

		// A text the next scan does not find, and no page showed, is gone.
		Catalogue::add(
			$this->theme,
			array(
				array(
					'd' => 'twentytwentyfive',
					't' => 'Removed from the code',
				),
			),
			'scanned',
			1
		);
		$step = CodeScanner::step( $this->theme, 0 );
		while ( ! $step['done'] ) {
			$step = CodeScanner::step( $this->theme, $step['offset'] );
		}
		$this->assertNotContains( 'Removed from the code', wp_list_pluck( wp_list_pluck( Catalogue::texts( $this->theme ), 'source' ), 't' ) );
	}

	/**
	 * A scan of settings groups the values that could be text, and leaves settings out.
	 */
	public function test_scan_of_settings() {
		update_option(
			'zz_theme_options',
			array(
				'footer'  => 'Izrađeno u Zagrebu',
				'tagline' => 'Apartmani uz more i plažu',
				'size'    => 'Medium',
				'order'   => 'Title | Site name',
				'logo'    => 'https://example.org/wp-content/uploads/logo.png',
				'pixel'   => '<img src="https://pixel.example/p.gif" width="1" height="1">',
				'color'   => '#ffffff',
				'count'   => '12',
				'mode'    => 'boxed',
				'temp'    => ABSPATH . 'wp-content/uploads/temp',
			)
		);
		Settings::learnOwners( array( 'zz_theme_options' => $this->theme ) );
		// A footer text a visitor saw on a page.
		Settings::see( array( 'Izrađeno u Zagrebu' ) );

		$groups = array();
		foreach ( Settings::candidates( $this->theme ) as $candidate ) {
			$groups[ $candidate['p'] ] = $candidate['group'] . ':' . $candidate['kind'];
		}
		$this->assertSame(
			array(
				'option/zz_theme_options/footer'  => 'shown:text',
				'option/zz_theme_options/tagline' => 'maybe:text',
				'option/zz_theme_options/size'    => 'settings:text',
				'option/zz_theme_options/order'   => 'settings:text',
				'option/zz_theme_options/logo'    => 'addresses:image',
				'option/zz_theme_options/pixel'   => 'code:code',
			),
			$groups
		);
	}

	/**
	 * A setting belongs to the code that calls get_option() itself: one WordPress reads for a theme,
	 * as the site title in get_bloginfo(), is WordPress's.
	 */
	public function test_who_reads_a_setting() {
		$this->assertSame( '', Packages::callerOf( 'get_option' ), 'No get_option() call on the stack.' );
		$caller = null;
		$note   = static function ( $pre ) use ( &$caller ) {
			$caller = Packages::callerOf( 'get_option' );
			return $pre;
		};
		add_filter( 'pre_option_blogname', $note );
		get_bloginfo( 'name' );
		$this->assertSame( '', $caller );
		$this->assertTrue( Settings::isWordPressOption( 'blogname' ) );
		$this->assertTrue( Settings::isWordPressOption( 'widget_text' ) );
		$this->assertFalse( Settings::isWordPressOption( 'et_divi' ) );
	}

	/**
	 * Licences, keys, tokens and bookkeeping are never listed or learned, even when a theme reads them.
	 */
	public function test_secrets_are_never_offered() {
		foreach ( array( 'acf_pro_license', 'shop_api_key', 'shop_token', 'shop_version', '_transient_shop', 'swiftling_languages', 'blogname', 'widget_text' ) as $name ) {
			$this->assertFalse( Settings::isCandidateName( $name ), $name );
		}
		$this->assertTrue( Settings::isCandidateName( 'et_divi' ) );

		update_option( 'zz_theme_license', array( 'name' => 'Theme licence for Marija' ) );
		Settings::learnOwners( array( 'zz_theme_license' => $this->theme ) );
		$this->assertArrayNotHasKey( 'zz_theme_license', Settings::owners() );
		$this->assertNotContains( 'zz_theme_license', Settings::optionNames( $this->theme ) );
	}

	/**
	 * Options named after the theme belong to it without a recording; its Customizer settings too.
	 */
	public function test_settings_named_after_the_package() {
		update_option( 'twentytwentyfive_notice', array( 'text' => 'Dobro došli na naše stranice' ) );
		update_option( 'theme_mods_twentytwentyfive', array( 'footer_text' => 'Sva prava pridržana' ) );
		update_option( 'someone_else', array( 'text' => 'Ne pripada temi' ) );
		$names = Settings::optionNames( $this->theme );
		$this->assertContains( 'twentytwentyfive_notice', $names );
		$this->assertContains( 'theme_mods_twentytwentyfive', $names );
		$this->assertNotContains( 'someone_else', $names );
	}

	/**
	 * A chosen tracking code is translated as a whole, and a chosen single word too; the original
	 * stays, and a page check never puts a marker into the code.
	 */
	public function test_chosen_settings_are_translated() {
		$code = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-HR"></script><script>gtag("config","G-HR");</script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A tracking code a plugin keeps in its settings.
		$de   = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-DE"></script><script>gtag("config","G-DE");</script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A tracking code a plugin keeps in its settings.
		update_option( 'zz_tracking', $code );
		update_option( 'zz_notice', array( 'word' => 'dobrodošlica' ) );
		Settings::learnOwners(
			array(
				'zz_tracking' => $this->theme,
				'zz_notice'   => $this->theme,
			)
		);

		$chosen = Settings::choose( $this->theme, array( 'option/zz_tracking', 'option/zz_notice/word', 'option/blogname' ) );
		$this->assertSame(
			array(
				'option/zz_tracking'    => 'code',
				'option/zz_notice/word' => 'text',
			),
			$chosen,
			'Only values the scan offered can be chosen.'
		);
		$this->assertTrue( Settings::isWhole( 'option/zz_tracking' ) );
		swiftling_save_listed_texts( Texts::subject( 'settings:' . $this->theme ), Settings::listTexts( $this->theme ) );

		$this->translate( 'options', 'option/zz_tracking', $code, 'de', $de );
		$this->translate( 'options', 'option/zz_notice/word', 'dobrodošlica', 'de', 'willkommen' );

		$leaves = $this->probe(
			static function () {
				get_option( 'zz_tracking' );
				get_option( 'zz_notice' );
			}
		);
		$this->assertSame( array( 'option/zz_notice/word' ), array_values( wp_list_pluck( $leaves, 'p' ) ), 'No marker goes into the code.' );

		$this->show_in( 'de' );
		$this->assertSame( $de, get_option( 'zz_tracking' ) );
		$this->assertSame( 'willkommen', get_option( 'zz_notice' )['word'] );

		$this->reset_state();
		$this->assertSame( $code, get_option( 'zz_tracking' ) );
	}

	/**
	 * Code nobody chose is never translated as a whole: its parts stay as they were.
	 */
	public function test_code_not_chosen_is_left_alone() {
		$code = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-HR"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- A tracking code a plugin keeps in its settings.
		update_option( 'zz_tracking', $code );
		$this->assertFalse( Settings::isWhole( 'option/zz_tracking' ) );
		$this->translate( 'options', 'option/zz_tracking', $code, 'de', 'anything' );
		$this->show_in( 'de' );
		$this->assertSame( $code, get_option( 'zz_tracking' ) );
	}

	/**
	 * Sends what the check of a recorded page found, signed as the page printed it.
	 *
	 * @param array $leaves   Leaf number => o, p, v, r and, for a text from code, g.
	 * @param array $found_in Options and text domains, each with the package that used it.
	 * @param array $control  Texts a visitor saw.
	 * @param bool  $sign     Whether to sign the list.
	 * @return \WP_REST_Response
	 */
	private function record( $leaves, $found_in, $control, $sign = true ) {
		$payload = wp_json_encode(
			array(
				'subject'  => 'record:/',
				'template' => '',
				'leaves'   => (object) $leaves,
				'found_in' => $found_in,
			)
		);
		$found   = array();
		foreach ( array_keys( $leaves ) as $order => $id ) {
			$found[ $id ] = array( $this->landing( 'text', $order + 1, array( 'content' => false ) ) );
		}
		$request = new WP_REST_Request( 'POST', '/swiftling/v1/record' );
		$request->set_body_params(
			array(
				'subject'   => 'record:/',
				'registry'  => $payload,
				'signature' => $sign ? swiftling_probe_signature( $payload ) : 'wrong',
				'found'     => $found,
				'control'   => $control,
			)
		);
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A recorded page keeps only the texts of themes and plugins: never the content of a post,
	 * WordPress's own texts or its settings.
	 */
	public function test_recording_keeps_only_theme_and_plugin_texts() {
		$this->become( 'administrator' );
		Recorder::start( true, false );
		$post = self::factory()->post->create();
		$read = swiftling_string_path( array( 't' => 'Read more' ) );

		$response = $this->record(
			array(
				1 => array(
					'o' => 'post:' . $post,
					'p' => 'field/post_content',
					'v' => 'Sadržaj stranice',
					'r' => 'field:post_content||',
				),
				2 => array(
					'o' => 'gettext:twentytwentyfive',
					'p' => $read,
					'v' => 'Read more',
					'r' => 'gettext||',
					'g' => array(
						'd' => 'twentytwentyfive',
						't' => 'Read more',
					),
				),
				3 => array(
					'o' => 'gettext:default',
					'p' => swiftling_string_path( array( 't' => 'Search' ) ),
					'v' => 'Search',
					'r' => 'gettext||',
					'g' => array(
						'd' => 'default',
						't' => 'Search',
					),
				),
				4 => array(
					'o' => 'options',
					'p' => 'option/blogname',
					'v' => 'Apartmani Marija',
					'r' => 'option:blogname||',
				),
				5 => array(
					'o' => 'options',
					'p' => 'option/zz_footer',
					'v' => 'Izrađeno u Zagrebu',
					'r' => 'option:zz_footer||',
				),
			),
			array(
				// A theme asks for the site title through WordPress, which reads it: still WordPress's.
				'options' => array(
					'zz_footer' => $this->theme,
					'blogname'  => $this->theme,
				),
				'domains' => array( 'twentytwentyfive' => $this->theme ),
			),
			array(
				array(
					't' => 'Izrađeno u Zagrebu',
					'k' => 'paragraph',
					'r' => 'footer',
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array(
				array(
					'package' => $this->theme,
					'name'    => Packages::get( $this->theme )['name'],
					'texts'   => 2,
					'new'     => 2,
				),
			),
			$data['page']
		);
		$this->assertSame( 2, $data['total']['texts'] );
		$this->assertSame( 1, $data['total']['pages'] );

		$this->assertSame( array( 'Read more' ), wp_list_pluck( wp_list_pluck( Catalogue::texts( $this->theme, 'seen' ), 'source' ), 't' ) );
		$maps = swiftling_get_maps( array( 'post:' . $post, 'gettext:default', 'gettext:twentytwentyfive', 'options' ) );
		$this->assertArrayNotHasKey( 'post:' . $post, $maps, 'The content of a post is never kept.' );
		$this->assertArrayNotHasKey( 'gettext:default', $maps, "WordPress's own texts are never kept." );
		$this->assertArrayHasKey( swiftling_path_key( $read ), $maps['gettext:twentytwentyfive']['units'] );
		$this->assertArrayHasKey( swiftling_path_key( 'option/zz_footer' ), $maps['options']['units'] );
		$this->assertArrayNotHasKey( swiftling_path_key( 'option/blogname' ), $maps['options']['units'], "WordPress's own settings are never kept." );
		$this->assertSame( $this->theme, Settings::owners()['zz_footer'] );
		$this->assertArrayNotHasKey( 'blogname', Settings::owners() );

		// The same page again finds nothing new.
		$again = $this->record(
			array(
				2 => array(
					'o' => 'gettext:twentytwentyfive',
					'p' => $read,
					'v' => 'Read more',
					'r' => 'gettext||',
					'g' => array(
						'd' => 'twentytwentyfive',
						't' => 'Read more',
					),
				),
			),
			array(),
			array()
		);
		$this->assertSame( 0, $again->get_data()['page'][0]['new'] );
	}

	/**
	 * A recorded page must come with the list its page check printed, signed.
	 */
	public function test_recording_needs_the_signed_list() {
		$this->become( 'administrator' );
		$this->assertSame( 400, $this->record( array(), array(), array(), false )->get_status() );
		$this->become( 'editor' );
		$this->assertSame( 403, $this->record( array(), array(), array() )->get_status() );
	}

	/**
	 * A recorded page is a path of this site, never another site.
	 */
	public function test_record_subjects() {
		$subject = swiftling_subject( 'record:/kontakt/?a=1' );
		$this->assertSame( home_url( '/kontakt/?a=1' ), $subject['url'] );
		$this->assertTrue( $subject['probe'] );
		foreach ( array( 'record:', 'record:kontakt', 'record://evil.example/', 'record:/a b', 'record:/"onload' ) as $key ) {
			$this->assertNull( swiftling_subject( $key ), $key );
		}
		$this->assertNull( swiftling_subject( 'strings:plugin/not-active' ) );
		$this->assertSame( 'package', swiftling_subject( 'code:' . $this->theme )['kind'] );
	}

	/**
	 * A recording is the user's own, runs for 30 minutes, and can pause and stop.
	 */
	public function test_recording_state() {
		$user = $this->become( 'administrator' );
		$this->assertNull( Recorder::current() );
		Recorder::start( true, true );
		$this->assertTrue( Recorder::recordsPages() );
		$this->assertTrue( Recorder::recordsAdmin() );
		$this->assertGreaterThan( time() + 1700, (int) get_option( Recorder::UNTIL_OPTION ) );

		Recorder::pause( true );
		$this->assertFalse( Recorder::recordsPages() );
		Recorder::pause( false );
		$this->assertTrue( Recorder::recordsPages() );

		$this->become( 'administrator' );
		$this->assertNull( Recorder::current(), 'Another user is not recorded.' );

		wp_set_current_user( $user );
		Recorder::stop();
		$this->assertNull( Recorder::current() );
		$this->assertNotNull( Recorder::last() );

		Recorder::start( true, false );
		$recording          = Recorder::last();
		$recording['until'] = time() - 1;
		update_user_meta( $user, Recorder::META, $recording );
		$this->assertNull( Recorder::current(), 'A recording ends by itself.' );
	}

	/**
	 * Starting a recording needs the form's nonce and the right to manage the site.
	 */
	public function test_recording_form() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_recording',
			array(
				'operation' => 'start',
				'pages'     => '1',
			)
		);
		$this->assertSame( home_url( '/' ), $this->redirect_of( array( Recorder::class, 'handle' ) ) );
		$this->assertTrue( Recorder::recordsPages() );
		$this->assertFalse( Recorder::recordsAdmin() );

		$this->post_form( 'swiftling_recording', array( 'operation' => 'stop' ) );
		$this->assertStringContainsString( 'recording_stopped', $this->redirect_of( array( Recorder::class, 'handle' ) ) );
		$this->assertNull( Recorder::current() );

		$this->become( 'editor' );
		$this->post_form( 'swiftling_recording', array( 'operation' => 'start' ) );
		$this->expectException( \WPDieException::class );
		Recorder::handle();
	}

	/**
	 * A list of texts from code shows the texts without a translation first, 50 at a time, and
	 * finds texts by their words.
	 */
	public function test_list_of_texts_from_code() {
		$texts = array();
		for ( $number = 1; $number <= 60; $number++ ) {
			$texts[] = array(
				'd' => 'zz-shop',
				't' => 'Text ' . $number,
			);
		}
		Catalogue::add( $this->theme, $texts, 'scanned', time() );
		$subject = Texts::subject( 'code:' . $this->theme );

		$list = Texts::forEditor( $subject, 'de', array() );
		$this->assertSame( 'missing', $list['show'] );
		$this->assertSame( 60, $list['counts']['missing'] );
		$this->assertCount( Texts::PER_PAGE, $list['texts'] );
		$this->assertSame( 2, $list['pages'] );
		$this->assertCount( 10, Texts::forEditor( $subject, 'de', array( 'page' => 2 ) )['texts'] );

		$this->translate( 'gettext:zz-shop', swiftling_string_path( array( 't' => 'Text 1' ) ), 'Text 1', 'de', 'Text 1 DE' );
		$list = Texts::forEditor( $subject, 'de', array() );
		$this->assertSame( 59, $list['counts']['missing'] );
		$this->assertSame(
			array(
				'translated' => 1,
				'total'      => 60,
			),
			$list['progress']['de']
		);
		$this->assertSame( 0, $list['progress']['fr']['translated'] );

		$found = Texts::forEditor(
			$subject,
			'de',
			array(
				'show'   => 'all',
				'search' => 'text 5',
			)
		);
		$this->assertSame( array( 'Text 5', 'Text 50', 'Text 51', 'Text 52', 'Text 53', 'Text 54', 'Text 55', 'Text 56', 'Text 57', 'Text 58', 'Text 59' ), wp_list_pluck( $found['texts'], 'v' ) );

		// Only texts pages showed are on the list of texts on your pages.
		$this->assertSame( 0, Texts::forEditor( Texts::subject( 'strings:' . $this->theme ), 'de', array() )['total'] );
	}

	/**
	 * A translation made in the list of texts from code shows where the theme writes the text.
	 */
	public function test_translation_from_the_list_shows_on_the_site() {
		Catalogue::add(
			$this->theme,
			array(
				array(
					'd' => 'zz-shop',
					't' => 'Add to cart',
				),
			),
			'scanned',
			time()
		);
		$subject = Texts::subject( 'code:' . $this->theme );
		swiftling_save_package_list( $subject, 'de', array() );
		$this->translate( 'gettext:zz-shop', swiftling_string_path( array( 't' => 'Add to cart' ) ), 'Add to cart', 'de', 'In den Warenkorb' );

		$this->show_in( 'de' );
		$this->assertSame( 'In den Warenkorb', __( 'Add to cart', 'zz-shop' ) ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- A theme's text, as the theme writes it.
	}

	/**
	 * The screens of Themes & plugins and the editor of a package's lists render.
	 */
	public function test_screens_render() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		update_option( 'zz_theme_options', array( 'footer' => 'Izrađeno u Zagrebu' ) );
		Settings::learnOwners( array( 'zz_theme_options' => $this->theme ) );
		Catalogue::add(
			$this->theme,
			array(
				array(
					'd' => 'twentytwentyfive',
					't' => 'Read more',
				),
			),
			'seen',
			time()
		);
		$render = static function ( $view ) {
			ob_start();
			( new Menu() )->renderScreen( $view );
			return (string) ob_get_clean();
		};

		$screen = $render( 'themes-plugins' );
		$this->assertStringContainsString( 'Find texts', $screen );
		$this->assertStringContainsString( 'Only the active theme and active plugins are listed', $screen );
		$this->assertStringContainsString( 'role="tooltip" id="swiftling-tip-settings"', $screen );

		$_GET = array(
			'package' => $this->theme,
			'view'    => 'choose',
		);
		$this->assertStringContainsString( 'Izrađeno u Zagrebu', $render( 'themes-plugins' ) );

		$_GET['view'] = 'scan';
		$this->assertStringContainsString( 'swiftling-code-scan-start', $render( 'themes-plugins' ) );

		$_GET   = array(
			'subject' => 'strings:' . $this->theme,
			'lang'    => 'de',
		);
		$editor = $render( 'editor' );
		$this->assertStringContainsString( 'Read more', $editor );
		$this->assertStringContainsString( 'Write the Deutsch translation here. Until you do, visitors read the original (Hrvatski).', $editor );
		set_current_screen( 'front' );
	}
}
