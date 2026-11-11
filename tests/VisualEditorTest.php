<?php
/**
 * Tests of the visual editor.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use SwiftLing\Builders\Registry;
use WP_REST_Request;

require_once __DIR__ . '/stubs/FakePageBuilder.php';

/**
 * Which pages the visual editor lays out, when Translate opens it, and that it translates with the
 * translation editor's own routes and storage.
 */
class VisualEditorTest extends TestCase {

	/**
	 * A heading with a highlighted word and a paragraph, as the block editor writes them.
	 */
	const CONTENT = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Naš <mark style=\"background-color:#503AA8\" class=\"has-inline-color has-accent-1-color\">podcast</mark></h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Priče iz povijesti.</p>\n<!-- /wp:paragraph -->";

	/**
	 * A post made of blocks.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Makes the post, as an administrator.
	 */
	public function set_up() {
		parent::set_up();
		$this->become( 'administrator' );
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Podcast',
				'post_name'    => 'podcast',
				'post_content' => self::CONTENT,
			)
		);
	}

	/**
	 * Puts the builders back.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Renders a post's content as a theme does.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function content( $post ) {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
		setup_postdata( $post );
		return apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
	}

	/**
	 * Checks the post's page, as the editor does, and returns the text with some words.
	 *
	 * @param string $needle Words of the text.
	 * @return array The text.
	 */
	private function checked( $needle ) {
		$post   = $this->post;
		$result = $this->check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		return $this->found( $result, $needle );
	}

	/**
	 * Sends a request to a route of SwiftLing.
	 *
	 * @param string $route  Route, e.g. "/translations".
	 * @param array  $params Body.
	 * @return \WP_REST_Response
	 */
	private function call( $route, $params ) {
		$request = new WP_REST_Request( 'POST', '/swiftling/v1' . $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Saves the choice of editor of the General settings.
	 *
	 * @param string $editor table or visual.
	 */
	private function choose( $editor ) {
		update_option( 'swiftling_settings', array( 'editor' => $editor ) );
	}

	/**
	 * The visual editor lays out a post made of blocks, in a language that shows it translated here.
	 * Classic content, a page builder's page, a block the site does not know, a separate copy and the
	 * original kept stay with the translation editor.
	 */
	public function test_which_posts_it_lays_out() {
		$this->assertTrue( swiftling_visual_editor_supports( $this->post ) );
		$this->assertTrue( swiftling_visual_editor_supports( $this->post, 'de' ) );

		$classic = self::factory()->post->create_and_get( array( 'post_content' => '<p>Priče iz povijesti.</p>' ) );
		$this->assertSame( 'swiftling_visual_classic', swiftling_visual_editor_supports( $classic )->get_error_code() );

		// A block of a plugin shows as it is saved, its texts beside the page.
		$plugin = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT . "\n\n<!-- wp:acme/slider /-->" ) );
		$this->assertTrue( swiftling_visual_editor_supports( $plugin ) );

		$built = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT ) );
		add_post_meta( $built->ID, '_fake_builder', '1' );
		self::set_static( Registry::class, 'builders', Registry::all() + array( 'fakepagebuilder' => new FakePageBuilder() ) );
		self::reset_static( Registry::class, 'active' );
		$this->assertSame( 'swiftling_visual_builder', swiftling_visual_editor_supports( $built )->get_error_code() );
		$this->assertStringContainsString( 'built with Fake page builder', swiftling_visual_editor_supports( $built )->get_error_message() );
		$this->assertTrue( swiftling_visual_editor_supports( $this->post ), 'A post the builder did not build' );

		$this->assertSame( 'duplicate', swiftling_set_language_mode( $this->post, 'de', 'duplicate' ) );
		$this->assertSame( 'swiftling_visual_mode', swiftling_visual_editor_supports( $this->post, 'de' )->get_error_code() );
		$this->assertTrue( swiftling_visual_editor_supports( $this->post, 'fr' ), 'Another language' );
		$this->assertSame( 'copy_made', swiftling_set_language_mode( $this->post, 'fr', 'manual' ) );
		$this->assertSame( 'swiftling_visual_mode', swiftling_visual_editor_supports( $this->post, 'fr' )->get_error_code() );

		$this->assertSame( 'swiftling_visual_subject', swiftling_visual_editor_supports( null )->get_error_code() );
	}

	/**
	 * Loads a screen as WordPress does before it prints it.
	 *
	 * @param string $slug Slug of the screen.
	 */
	private function load( $slug ) {
		( new Menu() )->loadScreen( Menu::screens()[ $slug ] + array( 'slug' => $slug ) );
	}

	/**
	 * Translate opens the visual editor when the settings choose it and it lays the post out; Table
	 * view, which asks for the table editor, keeps it.
	 */
	public function test_translate_opens_the_chosen_editor() {
		$_GET = array(
			'page' => 'swiftling-translation',
			'post' => (string) $this->post->ID,
			'lang' => 'fr',
		);
		$this->assertSame( '', swiftling_visual_editor_redirect(), 'The table editor by default' );

		$this->choose( 'visual' );
		$visual = swiftling_visual_editor_url( $this->post->ID, 'fr' );
		$this->assertSame( admin_url( 'admin.php?page=swiftling-visual&post=' . $this->post->ID . '&lang=fr' ), $visual );
		$this->assertSame( $visual, swiftling_visual_editor_redirect() );
		$this->assertSame(
			$visual,
			$this->redirect_of(
				function () {
					$this->load( 'swiftling-translation' );
				}
			)
		);

		// Without a language, the first one.
		unset( $_GET['lang'] );
		$this->assertSame( swiftling_visual_editor_url( $this->post->ID, 'de' ), swiftling_visual_editor_redirect() );

		$_GET['editor'] = 'table';
		$this->assertSame( '', swiftling_visual_editor_redirect() );
		$this->assertStringContainsString( 'editor=table', swiftling_table_editor_url( $this->post->ID, 'fr' ) );
		$this->assert_no_redirect(
			function () {
				$this->load( 'swiftling-translation' );
			}
		);

		$_GET = array(
			'page' => 'swiftling-translation',
			'post' => (string) self::factory()->post->create( array( 'post_content' => '<p>Priče.</p>' ) ),
			'lang' => 'fr',
		);
		$this->assertSame( '', swiftling_visual_editor_redirect(), 'Classic content' );
	}

	/**
	 * Table view and Visual view remember the editor of each user, which Translate opens from then
	 * on; a user who has not chosen gets the one of the settings. A post the visual editor does not
	 * lay out opens in the table editor and keeps the choice.
	 */
	public function test_each_user_choice_is_remembered() {
		$first  = get_current_user_id();
		$second = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$plain  = array(
			'post' => (string) $this->post->ID,
			'lang' => 'fr',
		);
		$this->assertSame( 'table', swiftling_editor_choice() );

		// Visual view.
		$this->assertStringContainsString( 'editor=visual', swiftling_visual_view_url( $this->post->ID, 'fr' ) );
		$_GET = $plain + array( 'editor' => 'visual' );
		$this->load( 'swiftling-visual' );
		$this->assertSame( 'visual', get_user_meta( $first, SWIFTLING_EDITOR_CHOICE, true ) );
		$this->assertSame( 'visual', swiftling_editor_choice() );
		$_GET = $plain;
		$this->assertSame( swiftling_visual_editor_url( $this->post->ID, 'fr' ), swiftling_visual_editor_redirect() );
		// The visual editor itself never leads anywhere else.
		$this->assert_no_redirect(
			function () {
				$this->load( 'swiftling-visual' );
			}
		);

		// Another user has not chosen: the settings' editor.
		wp_set_current_user( $second );
		$this->assertSame( 'table', swiftling_editor_choice() );
		$this->assertSame( '', swiftling_visual_editor_redirect() );
		$this->assertSame( 'visual', swiftling_editor_choice( $first ) );
		$this->choose( 'visual' );
		$this->assertSame( swiftling_visual_editor_url( $this->post->ID, 'fr' ), swiftling_visual_editor_redirect() );
		$this->choose( 'table' );

		// A post the visual editor does not lay out: the table editor, the choice kept.
		wp_set_current_user( $first );
		$_GET['post'] = (string) self::factory()->post->create( array( 'post_content' => '<p>Priče.</p>' ) );
		$this->assert_no_redirect(
			function () {
				$this->load( 'swiftling-translation' );
			}
		);
		$this->assertSame( 'visual', swiftling_editor_choice() );

		// Table view.
		$_GET = $plain + array( 'editor' => 'table' );
		$this->load( 'swiftling-translation' );
		$this->assertSame( 'table', swiftling_editor_choice() );
		$_GET = $plain;
		$this->assertSame( '', swiftling_visual_editor_redirect() );

		// Someone who may not translate chooses nothing; nor does a value of no editor.
		$this->become( 'subscriber' );
		$_GET = $plain + array( 'editor' => 'visual' );
		swiftling_remember_editor();
		$this->assertSame( '', get_user_meta( get_current_user_id(), SWIFTLING_EDITOR_CHOICE, true ) );
		wp_set_current_user( $first );
		$_GET['editor'] = 'sideways';
		swiftling_remember_editor();
		$this->assertSame( 'table', get_user_meta( $first, SWIFTLING_EDITOR_CHOICE, true ) );
	}

	/**
	 * The General settings save the choice of editor, and only the choices they offer.
	 */
	public function test_settings_save_the_editor() {
		$this->post_form( 'swiftling_save_settings', array( 'editor' => 'visual' ) );
		$this->redirect_of( 'swiftling_save_settings' );
		$this->assertSame( 'visual', swiftling_settings()['editor'] );

		$this->post_form( 'swiftling_save_settings', array( 'editor' => 'sideways' ) );
		$this->redirect_of( 'swiftling_save_settings' );
		$this->assertSame( 'table', swiftling_settings()['editor'] );

		update_option( 'swiftling_settings', array( 'editor' => array( 'visual' ) ) );
		$this->assertSame( 'table', swiftling_settings()['editor'] );

		$html = $this->render_screen( 'settings' );
		$this->assertStringContainsString( 'name="editor" value="table"  checked=\'checked\'', $html );
	}

	/**
	 * The table editor of a post the visual editor lays out leads there, in the same language,
	 * whichever editor the settings choose; of a post it does not lay out, nowhere.
	 */
	public function test_table_editor_leads_to_the_visual_editor() {
		set_current_screen( 'toplevel_page_swiftling' );
		$_GET = array(
			'post' => (string) $this->post->ID,
			'lang' => 'fr',
		);
		$link = esc_url( swiftling_visual_view_url( $this->post->ID, 'fr' ) );
		$this->assertStringContainsString( 'href="' . $link . '"', $this->render_screen( 'editor' ) );

		swiftling_set_language_mode( $this->post, 'fr', 'duplicate' );
		$this->assertStringNotContainsString( 'page=swiftling-visual', $this->render_screen( 'editor' ), 'The original kept' );

		$_GET['post'] = (string) self::factory()->post->create( array( 'post_content' => '<p>Priče.</p>' ) );
		$this->assertStringNotContainsString( 'page=swiftling-visual', $this->render_screen( 'editor' ), 'Classic content' );
	}

	/**
	 * The screen gives its script the post's blocks and its texts with their translations, under
	 * the keys the translation editor saves them by; a post it does not lay out leads to the
	 * translation editor.
	 */
	public function test_screen() {
		$heading = $this->checked( 'podcast' );
		$key     = swiftling_place_key( 'post:' . $this->post->ID, swiftling_path_key( $heading['p'] ), swiftling_hash( $heading['v'] ) );
		$this->translate( 'post:' . $this->post->ID, $heading['p'], $heading['v'], 'fr', str_replace( 'Naš', 'Notre', $heading['v'] ) );
		$post = $this->post;
		$this->serve_page_check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);

		$_GET = array(
			'page' => 'swiftling-visual',
			'post' => (string) $post->ID,
			'lang' => 'fr',
		);
		$html = $this->render_screen( 'visual' );
		$this->assertSame( 1, preg_match( '#<script type="application/json" id="swiftling-visual-config">(.*?)</script>#s', $html, $found ) );
		$config = json_decode( $found[1], true );
		$this->assertSame( self::CONTENT, $config['post']['content'] );
		$this->assertSame( 'fr', $config['lang'] );
		$texts = array_column( $config['texts'], null, 'key' );
		$this->assertSame( 'reviewed', $texts[ $key ]['state'] );
		$this->assertStringContainsString( 'Notre <mark', $texts[ $key ]['translation'] );
		$this->assertSame( swiftling_table_editor_url( $post->ID, 'fr' ), $config['links']['table'] );
		$this->assertArrayHasKey( 'styles', $config['settings'] );

		$classic      = self::factory()->post->create( array( 'post_content' => '<p>Priče.</p>' ) );
		$_GET['post'] = (string) $classic;
		$html         = $this->render_screen( 'visual' );
		$this->assertStringNotContainsString( 'swiftling-visual-config', $html );
		// Without editor=table: the user did not choose the table editor.
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'post:' . $classic, 'fr' ) ) . '"', $html );
	}

	/**
	 * The visual editor saves a block's HTML, its formats kept, with the translation editor's route:
	 * only for whoever may translate the post, into SwiftLing's storage, and the page in the
	 * language shows it.
	 */
	public function test_saving_uses_the_editor_route() {
		$heading = $this->checked( 'podcast' );
		$key     = swiftling_place_key( 'post:' . $this->post->ID, swiftling_path_key( $heading['p'] ), swiftling_hash( $heading['v'] ) );
		$text    = str_replace( 'Naš', 'Notre', $heading['v'] );
		$params  = array(
			'subject' => 'post:' . $this->post->ID,
			'lang'    => 'fr',
			'status'  => 'reviewed',
			'items'   => array(
				array(
					'key'      => $key,
					'text'     => $text,
					'original' => $heading['v'],
				),
			),
		);

		$this->become( 'subscriber' );
		$this->assertSame( 403, $this->call( '/translations', $params )->get_status() );
		$this->assertSame( array(), swiftling_get_translations( 'fr_FR', array( $key ) ) );

		$this->become( 'administrator' );
		$response = $this->call( '/translations', $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $key => 'reviewed' ), $response->get_data()['states'] );
		$this->assertSame( $text, swiftling_get_translations( 'fr_FR', array( $key ) )[ $key ]['text'] );

		$this->show_in( 'fr' );
		$this->assertStringContainsString( 'Notre <mark style="background-color:#503AA8" class="has-inline-color has-accent-1-color">podcast</mark></h2>', $this->content( $this->post ) );
	}

	/**
	 * The address of the page in the language is saved with the translation editor's route.
	 */
	public function test_address_uses_the_editor_route() {
		$this->use_pretty_permalinks();
		$params = array(
			'subject' => 'post:' . $this->post->ID,
			'lang'    => 'fr',
			'slug'    => 'balado',
		);
		$this->become( 'subscriber' );
		$this->assertSame( 403, $this->call( '/slug', $params )->get_status() );

		$this->become( 'administrator' );
		$response = $this->call( '/slug', $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'balado', $response->get_data()['slug'] );
		$this->assertSame( 'balado', swiftling_post_address( $this->post, 'fr' )['slug'] );
	}

	/**
	 * A post with content that is no block of its own, a classic block or HTML between the blocks,
	 * and a page of a builder that keeps the page in its meta or in one block of its own, stay with
	 * the translation editor; a builder's name says why.
	 */
	public function test_declines_what_it_cannot_lay_out() {
		$freeform = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT . "\n\n<!-- wp:freeform -->\n<p>Klasični blok.</p>\n<!-- /wp:freeform -->" ) );
		$this->assertSame( 'swiftling_visual_loose', swiftling_visual_editor_supports( $freeform )->get_error_code() );
		$loose = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT . "\n\n<p>HTML između blokova.</p>" ) );
		$this->assertSame( 'swiftling_visual_loose', swiftling_visual_editor_supports( $loose )->get_error_code() );
		$this->assertStringContainsString( 'outside its blocks', swiftling_visual_editor_supports( $loose )->get_error_message() );
		$in_group = self::factory()->post->create_and_get( array( 'post_content' => "<!-- wp:group -->\n<div class=\"wp-block-group\">Tekst u grupi<!-- wp:paragraph -->\n<p>Odlomak.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->" ) );
		$this->assertSame( 'swiftling_visual_loose', swiftling_visual_editor_supports( $in_group )->get_error_code(), 'Text loose in a group' );
		$spaced = self::factory()->post->create_and_get( array( 'post_content' => "<!-- wp:group -->\n<div class=\"wp-block-group\">&nbsp;<!-- wp:paragraph -->\n<p>Odlomak.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->" ) );
		$this->assertTrue( swiftling_visual_editor_supports( $spaced ), 'A space is no text' );
		$group = self::factory()->post->create_and_get( array( 'post_content' => "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Odlomak.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->" ) );
		$this->assertTrue( swiftling_visual_editor_supports( $group ), 'A group of blocks' );

		foreach ( array(
			'_breakdance_data'      => 'Breakdance',
			'_oxygen_data'          => 'Oxygen',
			'ct_builder_shortcodes' => 'Oxygen',
			'brizy'                 => 'Brizy',
			'_fl_builder_enabled'   => 'Beaver Builder',
			'panels_data'           => 'SiteOrigin Page Builder',
		) as $key => $builder ) {
			$post = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT ) );
			add_post_meta( $post->ID, $key, array( 'tree' => 1 ) );
			$declined = swiftling_visual_editor_supports( $post );
			$this->assertSame( 'swiftling_visual_builder', $declined->get_error_code(), $key );
			$this->assertStringContainsString( $builder, $declined->get_error_message() );
		}
		// The empty block Beaver Builder adds to a page of blocks is no builder's page; its meta is.
		register_block_type( 'fl-builder/layout', array() );
		$beaver = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT . "\n\n<!-- wp:fl-builder/layout /-->" ) );
		$this->assertTrue( swiftling_visual_editor_supports( $beaver ) );
		add_post_meta( $beaver->ID, '_fl_builder_enabled', '1' );
		$this->assertStringContainsString( 'Beaver Builder', swiftling_visual_editor_supports( $beaver )->get_error_message() );
		// SiteOrigin's block, only with a layout in it.
		$origin = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT . "\n\n<!-- wp:siteorigin-panels/layout-block /-->" ) );
		$this->assertTrue( swiftling_visual_editor_supports( $origin ) );
		$origin = self::factory()->post->create_and_get( array( 'post_content' => '<!-- wp:siteorigin-panels/layout-block {"panelsData":{"widgets":[1]}} /-->' ) );
		$this->assertStringContainsString( 'SiteOrigin', swiftling_visual_editor_supports( $origin )->get_error_message() );

		// The translation editor names the builder too.
		set_current_screen( 'toplevel_page_swiftling' );
		$_GET = array(
			'post' => (string) $beaver->ID,
			'lang' => 'fr',
		);
		$this->assertStringContainsString( 'built with Beaver Builder', $this->render_screen( 'editor' ) );
		unregister_block_type( 'fl-builder/layout' );
	}

	/**
	 * The screen gives its script what the page in the language needs: its language and direction,
	 * each text's name as the translation editor names its row, and only the links the user may
	 * open, which for a translator are not the settings.
	 */
	public function test_screen_for_a_translator() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'ar'    => 'ar',
			)
		);
		$this->checked( 'podcast' );
		$post = $this->post;
		$this->serve_page_check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$data = swiftling_visual_editor_data( $post, 'ar' );
		$this->assertSame(
			array(
				'lang' => 'ar',
				'dir'  => 'rtl',
			),
			$data['target']
		);
		$texts = array_column( $data['texts'], null, 'path' );
		$this->assertSame( 'Title', $texts['field/post_title']['label'] );
		// HTML keeps its tags, as in the translation editor: only the text between them is edited.
		$heading = current(
			array_filter(
				$data['texts'],
				static function ( $text ) {
					return false !== strpos( $text['original'], 'podcast' );
				}
			)
		);
		$this->assertSame( 'html', $heading['type'] );
		$this->assertSame( '<h2 class="wp-block-heading">', trim( $heading['parts'][0] ) );
		$this->assertStringStartsWith( 'Naš <mark', $heading['parts'][1] );
		$this->assertSame( array( 'back', 'table', 'service', 'request' ), array_keys( (array) $data['links'] ) );

		// A translator, who may translate but not change the settings.
		$this->become( 'author' );
		add_filter( 'swiftling_can_translate', '__return_true' );
		$this->assertTrue( current_user_can( SWIFTLING_TRANSLATE ) );
		$data = swiftling_visual_editor_data( $post, 'de' );
		$this->assertSame( array( 'back', 'table' ), array_keys( (array) $data['links'] ) );
		remove_filter( 'swiftling_can_translate', '__return_true' );
	}

	/**
	 * The screen loads the block editor with the core blocks only, for a page it can lay out: not what
	 * plugins load into the block editor, which can be tours of their own and scripts that need the
	 * whole block editor. A block of a plugin shows as it is saved, see assets/js/visual.js.
	 */
	public function test_screen_loads_the_core_blocks_only() {
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
		wp_register_script( 'swiftling-test-block-editor', 'https://example.org/block.js', array(), '1', true );
		register_block_type( 'swiftling-test/card', array( 'editor_script_handles' => array( 'swiftling-test-block-editor' ) ) );
		$fired = 0;
		$count = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'enqueue_block_editor_assets', $count );

		$_GET = array( 'post' => (string) self::factory()->post->create( array( 'post_content' => self::CONTENT . "\n\n<!-- wp:swiftling-test/card /-->" ) ) );
		( new Menu() )->enqueueAssets( Menu::screens()['swiftling-visual'] + array( 'slug' => 'swiftling-visual' ) );
		$this->assertTrue( wp_script_is( 'wp-block-library' ) );
		$this->assertTrue( wp_script_is( 'swiftling-machine' ), 'Machine translation, in batches' );
		$this->assertContains( 'wp-dom-ready', wp_scripts()->registered['swiftling-visual']->deps );
		$this->assertFalse( wp_script_is( 'swiftling-test-block-editor' ) );
		$this->assertSame( 0, $fired );

		// A page it cannot lay out: no block editor, only the reason.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
		$_GET                  = array( 'post' => (string) self::factory()->post->create( array( 'post_content' => '<p>Klasično.</p>' ) ) );
		( new Menu() )->enqueueAssets( Menu::screens()['swiftling-visual'] + array( 'slug' => 'swiftling-visual' ) );
		$this->assertFalse( wp_script_is( 'wp-block-library' ) );

		remove_action( 'enqueue_block_editor_assets', $count );
		unregister_block_type( 'swiftling-test/card' );
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh queue of scripts.
	}

	/**
	 * A list item with a list inside it is a text of its own, without that list: saved as the
	 * visual editor saves it, the page shows the item and its list once each.
	 */
	public function test_text_of_a_block_with_inner_blocks() {
		$post   = self::factory()->post->create_and_get(
			array( 'post_content' => "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Točka jedan<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Podtočka</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->" )
		);
		$result = $this->check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$item   = $this->found( $result, 'Točka jedan' );
		$this->assertStringNotContainsString( 'Podtočka', $item['v'] );
		$key   = swiftling_place_key( 'post:' . $post->ID, swiftling_path_key( $item['p'] ), swiftling_hash( $item['v'] ) );
		$saved = $this->call(
			'/translations',
			array(
				'subject' => 'post:' . $post->ID,
				'lang'    => 'fr',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'      => $key,
						'text'     => str_replace( 'Točka jedan', 'Point un', $item['v'] ),
						'original' => $item['v'],
					),
				),
			)
		);
		$this->assertSame( array( $key => 'reviewed' ), $saved->get_data()['states'] );

		$this->show_in( 'fr' );
		$html = $this->content( $post );
		$this->assertSame( 1, substr_count( $html, 'Point un' ) );
		$this->assertSame( 1, substr_count( $html, 'Podtočka' ) );
		$this->assertStringNotContainsString( 'Točka jedan', $html );
		$this->assertStringNotContainsString( '<!--', $html );
	}

	/**
	 * An image with a caption is one text, its figure: the visual editor saves the original figure
	 * with the alt text and the caption of the language put in, and the page shows it so.
	 */
	public function test_figure_of_a_captioned_image() {
		$post   = self::factory()->post->create_and_get(
			array( 'post_content' => "<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"https://example.org/brdo.jpg\" alt=\"Brdo\"/><figcaption class=\"wp-element-caption\">Pogled na <strong>brdo</strong></figcaption></figure>\n<!-- /wp:image -->" )
		);
		$result = $this->check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);

		$figure = $this->found( $result, 'Pogled na' );
		$this->assertStringStartsWith( '<figure', trim( $figure['v'] ) );
		$key = swiftling_place_key( 'post:' . $post->ID, swiftling_path_key( $figure['p'] ), swiftling_hash( $figure['v'] ) );
		// Listed as code, which the visual editor gives the image block, see rawTreeOf().
		$this->assertTrue( swiftling_live_is_code( $figure ) );

		// As figureOf() in assets/js/visual.js puts the language's alt text and caption in.
		$text = str_replace( array( 'alt="Brdo"', 'Pogled na <strong>brdo</strong>' ), array( 'alt="Montagne"', 'Vue sur la <strong>montagne</strong>' ), $figure['v'] );
		$this->call(
			'/translations',
			array(
				'subject' => 'post:' . $post->ID,
				'lang'    => 'fr',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'      => $key,
						'text'     => $text,
						'original' => $figure['v'],
					),
				),
			)
		);
		$this->show_in( 'fr' );
		$html = $this->content( $post );
		$this->assertStringContainsString( 'alt="Montagne"', $html );
		$this->assertStringContainsString( 'Vue sur la <strong>montagne</strong></figcaption>', $html );
		$this->assertSame( 1, substr_count( $html, '<figure' ) );
	}

	/**
	 * A block of a plugin is shown as its page renders it, with the styles rendering it asks for;
	 * what that enqueues for the page is not left in the admin's queues.
	 */
	public function test_blocks_of_plugins_as_the_page_renders_them() {
		wp_register_style( 'acme-card-style', 'https://example.org/card.css', array(), '1' );
		register_block_type(
			'acme/card',
			array(
				'render_callback' => static function ( $attributes, $content ) {
					wp_enqueue_style( 'acme-card-style' );
					wp_add_inline_style( 'acme-card-style', '.acme-card{color:red}' );
					return '<div class="acme-card">' . $content . '</div>';
				},
			)
		);
		$post     = self::factory()->post->create_and_get( array( 'post_content' => "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:acme/card -->\n<p>Kartica</p>\n<!-- /wp:acme/card --></div>\n<!-- /wp:group -->" ) );
		$queue    = wp_styles()->queue;
		$rendered = swiftling_visual_plugin_blocks( $post, array() );
		$this->assertSame( array( 'post:' . $post->ID . '|0/0' ), array_keys( $rendered['html'] ) );
		$this->assertStringContainsString( '<div class="acme-card">', $rendered['html'][ 'post:' . $post->ID . '|0/0' ] );
		$this->assertStringContainsString( 'card.css', $rendered['styles'] );
		$this->assertStringContainsString( '.acme-card{color:red}', $rendered['styles'] );
		$this->assertSame( $queue, wp_styles()->queue );
		unregister_block_type( 'acme/card' );
	}
}
