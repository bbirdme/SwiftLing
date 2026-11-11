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

		$unknown = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT . "\n\n<!-- wp:acme/slider /-->" ) );
		$this->assertSame( 'swiftling_visual_block', swiftling_visual_editor_supports( $unknown )->get_error_code() );

		$built = self::factory()->post->create_and_get( array( 'post_content' => self::CONTENT ) );
		add_post_meta( $built->ID, '_fake_builder', '1' );
		self::set_static( Registry::class, 'builders', Registry::all() + array( 'fakepagebuilder' => new FakePageBuilder() ) );
		self::reset_static( Registry::class, 'active' );
		$this->assertSame( 'swiftling_visual_builder', swiftling_visual_editor_supports( $built )->get_error_code() );
		$this->assertTrue( swiftling_visual_editor_supports( $this->post ), 'A post the builder did not build' );

		$this->assertSame( 'duplicate', swiftling_set_language_mode( $this->post, 'de', 'duplicate' ) );
		$this->assertSame( 'swiftling_visual_mode', swiftling_visual_editor_supports( $this->post, 'de' )->get_error_code() );
		$this->assertTrue( swiftling_visual_editor_supports( $this->post, 'fr' ), 'Another language' );
		$this->assertSame( 'copy_made', swiftling_set_language_mode( $this->post, 'fr', 'manual' ) );
		$this->assertSame( 'swiftling_visual_mode', swiftling_visual_editor_supports( $this->post, 'fr' )->get_error_code() );

		$this->assertSame( 'swiftling_visual_subject', swiftling_visual_editor_supports( null )->get_error_code() );
	}

	/**
	 * Translate opens the visual editor when the settings choose it and it lays the post out; a link
	 * that asks for the table editor, as the visual editor's own, keeps it.
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
		$screen = Menu::screens()['swiftling-translation'] + array( 'slug' => 'swiftling-translation' );
		$this->assertSame(
			$visual,
			$this->redirect_of(
				static function () use ( $screen ) {
					( new Menu() )->loadScreen( $screen );
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
			static function () use ( $screen ) {
				( new Menu() )->loadScreen( $screen );
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
		$link = esc_url( swiftling_visual_editor_url( $this->post->ID, 'fr' ) );
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
		$this->assertStringContainsString( esc_url( swiftling_table_editor_url( $classic, 'fr' ) ), $html );
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
	 * Machine translation fills the empty texts of the page and saves them as machine translations,
	 * only for whoever may translate the post and use machine translation.
	 */
	public function test_fill_route() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->checked( 'Priče' );
		$post = $this->post;
		$this->serve_page_check(
			'post:' . $post->ID,
			function () use ( $post ) {
				$this->content( $post );
			}
		);
		$params = array(
			'subject' => 'post:' . $post->ID,
			'lang'    => 'fr',
		);

		$this->become( 'subscriber' );
		$this->assertSame( 403, $this->call( '/fill', $params )->get_status() );

		$this->become( 'administrator' );
		$response = $this->call( '/fill', $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertGreaterThanOrEqual( 2, $response->get_data()['translated'] );
		$this->assertSame( '', $response->get_data()['error'] );

		$this->show_in( 'fr' );
		$this->assertStringContainsString( 'PRIČE IZ POVIJESTI.', $this->content( $post ) );
	}
}
