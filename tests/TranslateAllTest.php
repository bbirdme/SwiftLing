<?php
/**
 * Tests of the routes of the Translate everything screen.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WP_REST_Request;

/**
 * The routes the whole-site run calls: the plan of a language, the page's empty texts and what the
 * run records.
 */
class TranslateAllTest extends TestCase {

	/**
	 * A checked post with a title, a text in a field and a link address.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Makes the post and checks its page.
	 */
	public function set_up() {
		parent::set_up();
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		add_post_meta( $this->post->ID, 'cta', 'https://example.org/kontakt/' );
		add_post_meta( $this->post->ID, 'subtitle', 'Javite nam se' );
		$this->check_post();
	}

	/**
	 * Checks the page of the post, as a page check in the browser does.
	 *
	 * @return array Result of swiftling_save_probe().
	 */
	private function check_post() {
		$post = $this->post;
		return $this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'cta', true );
				get_post_meta( $post->ID, 'subtitle', true );
			},
			array( 'meta/cta' => 'href' )
		);
	}

	/**
	 * Sends a request to a route of SwiftLing.
	 *
	 * @param string $route  Route, e.g. "/translate-all/plan".
	 * @param array  $params Body.
	 * @return \WP_REST_Response
	 */
	private function call( $route, $params ) {
		$request = new WP_REST_Request( 'POST', '/swiftling/v1' . $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Returns the place key of a text of the post.
	 *
	 * @param string $path     Path.
	 * @param string $original Original.
	 * @return string
	 */
	private function place( $path, $original ) {
		return swiftling_place_key( 'post:' . $this->post->ID, swiftling_path_key( $path ), swiftling_hash( $original ) );
	}

	/**
	 * Both routes are registered.
	 */
	public function test_routes() {
		$routes = rest_get_server()->get_routes( 'swiftling/v1' );
		foreach ( array( '/translate-all/plan', '/translate-all/progress' ) as $route ) {
			$this->assertArrayHasKey( '/swiftling/v1' . $route, $routes, $route );
		}
	}

	/**
	 * Visitors and people who do not manage the site may not call the routes.
	 */
	public function test_routes_need_rights() {
		$params = array( 'lang' => 'de' );
		$this->assertSame( 401, $this->call( '/translate-all/plan', $params )->get_status() );
		$this->become( 'editor' );
		$this->assertSame( 403, $this->call( '/translate-all/plan', $params )->get_status() );
		$this->assertSame( 403, $this->call( '/translate-all/progress', $params )->get_status() );
	}

	/**
	 * A language the site is not translated into is refused.
	 */
	public function test_language_is_validated() {
		$this->become( 'administrator' );
		$this->assertSame( 400, $this->call( '/translate-all/plan', array( 'lang' => 'it' ) )->get_status() );
	}

	/**
	 * The plan lists the pages to do with their page checks, and counts only the empty texts: a
	 * text already translated is left as it is, and a link address is never machine translated.
	 */
	public function test_plan_counts_only_empty_texts() {
		$this->become( 'administrator' );
		$this->translate( 'post:' . $this->post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt' );

		$plan = $this->call( '/translate-all/plan', array( 'lang' => 'de' ) );
		$this->assertSame( 200, $plan->get_status() );
		$data = $plan->get_data();
		$this->assertSame( 'de', $data['language']['slug'] );
		$this->assertSame( array( 'post:' . $this->post->ID ), wp_list_pluck( $data['pages'], 'key' ) );
		$this->assertSame( 1, $data['pages'][0]['texts'], 'Only the text in the field is empty' );
		$this->assertTrue( $data['pages'][0]['checked'] );
		$this->assertStringContainsString( 'swiftling_probe=marked', $data['pages'][0]['probes']['marked'] );
		$this->assertStringContainsString( 'swiftling_probe=control', $data['pages'][0]['probes']['control'] );
		$this->assertSame( 1, $data['texts'] );
		$this->assertGreaterThan( 0, $data['characters'] );
		$this->assertFalse( $data['estimated'], 'Every page was checked' );
		$this->assertSame( '', $data['status'] );

		// The other language has both texts to do.
		$this->assertSame( 2, $this->call( '/translate-all/plan', array( 'lang' => 'fr' ) )->get_data()['texts'] );
	}

	/**
	 * A page that was never checked counts as a page to do, with its texts estimated.
	 */
	public function test_plan_estimates_pages_never_checked() {
		$this->become( 'administrator' );
		$other = self::factory()->post->create( array( 'post_title' => 'O nama' ) );

		$data  = $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_data();
		$pages = array_column( $data['pages'], 'checked', 'key' );
		$this->assertFalse( $pages[ 'post:' . $other ], 'Never checked' );
		$this->assertTrue( $pages[ 'post:' . $this->post->ID ] );
		$this->assertTrue( $data['estimated'] );
		$this->assertGreaterThan( 2, $data['texts'], 'The texts of the page never checked are guessed' );
	}

	/**
	 * Posts of a type the Settings screen sets to Never are left out.
	 */
	public function test_plan_leaves_out_types_set_to_never() {
		$this->become( 'administrator' );
		register_post_type(
			'project',
			array(
				'public' => true,
				'labels' => array( 'singular_name' => 'Project' ),
			)
		);
		$project = self::factory()->post->create( array( 'post_type' => 'project' ) );
		update_option( 'swiftling_settings', array( 'never' => array( 'project' ) ) );

		$keys = wp_list_pluck( $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_data()['pages'], 'key' );
		$this->assertContains( 'post:' . $this->post->ID, $keys );
		$this->assertNotContains( 'post:' . $project, $keys );
	}

	/**
	 * A text whose original changed since it was translated is not sent to the service: it is
	 * fixed in the translation editor, where the previous translation shows.
	 */
	public function test_outdated_texts_are_not_counted() {
		$this->become( 'administrator' );
		$this->translate( 'post:' . $this->post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt' );
		wp_update_post(
			array(
				'ID'         => $this->post->ID,
				'post_title' => 'Kontaktirajte nas',
			)
		);
		$this->post = get_post( $this->post->ID );
		$this->check_post();

		$data = $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_data();
		$this->assertSame( 1, $data['texts'], 'The changed title waits for the editor' );
	}

	/**
	 * A page check for the run answers with the empty texts of the page instead of the editor's
	 * rows: no address, nothing translated already.
	 */
	public function test_page_check_answers_with_the_empty_texts() {
		$this->become( 'administrator' );
		$this->translate( 'post:' . $this->post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontakt' );

		$post     = $this->post;
		$leaves   = $this->probe(
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'cta', true );
				get_post_meta( $post->ID, 'subtitle', true );
			}
		);
		$registry = wp_json_encode(
			array(
				'subject'  => 'post:' . $post->ID,
				'template' => '',
				'leaves'   => $leaves,
			)
		);
		$found    = array();
		$order    = 0;
		foreach ( $leaves as $id => $leaf ) {
			$found[ $id ] = array( $this->landing( 'meta/cta' === $leaf['p'] ? 'href' : 'text', ++$order ) );
		}

		$response = $this->call(
			'/probe',
			array(
				'subject'   => 'post:' . $post->ID,
				'lang'      => 'de',
				'registry'  => $registry,
				'signature' => swiftling_probe_signature( $registry ),
				'found'     => $found,
				'control'   => array(),
				'fill'      => true,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'rows', $data, 'The run needs no rows' );
		$this->assertSame( array( $this->place( 'meta/subtitle', 'Javite nam se' ) ), wp_list_pluck( $data['items'], 'key' ) );
		$this->assertSame( 'Javite nam se', $data['items'][0]['text'] );
		$this->assertFalse( $data['items'][0]['html'] );
	}

	/**
	 * Checks the page of the post for a run, as the browser does, and returns what the probe route
	 * answers with.
	 *
	 * @param array $params More of the request, e.g. again.
	 * @return array
	 */
	private function fill( $params ) {
		$post     = $this->post;
		$leaves   = $this->probe(
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'cta', true );
				get_post_meta( $post->ID, 'subtitle', true );
			}
		);
		$registry = wp_json_encode(
			array(
				'subject'  => 'post:' . $post->ID,
				'template' => '',
				'leaves'   => $leaves,
			)
		);
		$found    = array();
		$order    = 0;
		foreach ( $leaves as $id => $leaf ) {
			$found[ $id ] = array( $this->landing( 'meta/cta' === $leaf['p'] ? 'href' : 'text', ++$order ) );
		}
		$response = $this->call(
			'/probe',
			$params + array(
				'subject'   => 'post:' . $post->ID,
				'lang'      => 'de',
				'registry'  => $registry,
				'signature' => swiftling_probe_signature( $registry ),
				'found'     => $found,
				'control'   => array(),
				'fill'      => true,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	/**
	 * Asked for texts to translate again, a page check for a run lists the texts translated in the
	 * states asked for, with their state, instead of the empty ones; never a translation from
	 * WordPress, a theme or a plugin, and never a link address.
	 */
	public function test_page_check_lists_texts_to_translate_again() {
		$this->become( 'administrator' );
		$key = 'post:' . $this->post->ID;
		$this->translate( $key, 'field/post_title', 'Kontakt', 'de', 'Kontakt DE', SWIFTLING_MACHINE );
		$this->translate( $key, 'meta/subtitle', 'Javite nam se', 'de', 'Melden Sie sich', SWIFTLING_REVIEWED );
		$title    = $this->place( 'field/post_title', 'Kontakt' );
		$subtitle = $this->place( 'meta/subtitle', 'Javite nam se' );

		$items = $this->fill( array( 'again' => array( 'machine' ) ) )['items'];
		$this->assertSame( array( $title ), wp_list_pluck( $items, 'key' ) );
		$this->assertSame( 'Kontakt', $items[0]['text'], 'The original goes, not the translation' );
		$this->assertSame( 'machine', $items[0]['state'] );

		$items = $this->fill( array( 'again' => array( 'machine', 'reviewed' ) ) )['items'];
		$this->assertSame( array( $title, $subtitle ), wp_list_pluck( $items, 'key' ) );
		$this->assertSame( array( 'machine', 'reviewed' ), wp_list_pluck( $items, 'state' ) );

		$this->translate( $key, 'field/post_title', 'Kontakt', 'de', 'Kontakt', SWIFTLING_OFFICIAL );
		$this->assertSame( array( $subtitle ), wp_list_pluck( $this->fill( array( 'again' => array( 'machine', 'reviewed' ) ) )['items'], 'key' ), 'Official translations stay' );

		$this->assertSame( array(), $this->fill( array() )['items'], 'Without again: only empty texts, and none is empty' );
	}

	/**
	 * The scan counts each checked page's translations by state, for a run that translates them
	 * again; a page never checked has no count.
	 */
	public function test_scan_counts_translations_by_state() {
		$this->become( 'administrator' );
		$key = 'post:' . $this->post->ID;
		$this->translate( $key, 'field/post_title', 'Kontakt', 'de', 'Kontakt DE', SWIFTLING_MACHINE );
		$this->translate( $key, 'meta/subtitle', 'Javite nam se', 'de', 'Melden Sie sich', SWIFTLING_REVIEWED );
		$never = self::factory()->post->create( array( 'post_title' => 'Nikad' ) );

		$pages = array_column( swiftling_translate_all_scan(), null, 'id' );
		$this->assertSame(
			array(
				'machine'  => 1,
				'reviewed' => 1,
			),
			$pages[ $this->post->ID ]['translated']['de']
		);
		$this->assertNull( $pages[ $never ]['translated']['de'] );
	}

	/**
	 * What the run records: the pages done, the pages skipped with their reason, the texts and the
	 * characters, and the date the language was finished on.
	 */
	public function test_progress_records_the_run() {
		$this->become( 'administrator' );
		$skipped  = self::factory()->post->create();
		$response = $this->call(
			'/translate-all/progress',
			array(
				'lang'       => 'de',
				'page'       => 'post:' . $this->post->ID,
				'outcome'    => 'done',
				'texts'      => 3,
				'characters' => 120,
				'total'      => 2,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'running', $response->get_data()['status'] );

		$this->call(
			'/translate-all/progress',
			array(
				'lang'    => 'de',
				'page'    => 'post:' . $skipped,
				'outcome' => 'skipped',
				'reason'  => 'The page could not be checked.',
			)
		);
		$run = $this->call( '/translate-all/progress', array( 'lang' => 'de' ) )->get_data();
		$this->assertSame( array( $this->post->ID ), $run['pages'] );
		$this->assertSame( 3, $run['texts'] );
		$this->assertSame( 120, $run['characters'] );
		$this->assertSame( 2, $run['total'] );
		$this->assertSame( 'The page could not be checked.', $run['skipped'][0]['reason'] );
		$this->assertSame( 'post:' . $skipped, $run['skipped'][0]['key'] );
		$this->assertStringContainsString( 'page=swiftling-translation', $run['skipped'][0]['editor'] );

		// The language the run has not touched is untouched.
		$this->assertSame( '', $this->call( '/translate-all/progress', array( 'lang' => 'fr' ) )->get_data()['status'] );

		$finished = $this->call(
			'/translate-all/progress',
			array(
				'lang'   => 'de',
				'finish' => true,
			)
		);
		$this->assertSame( 'done', $finished->get_data()['status'] );
		$this->assertGreaterThan( 0, $finished->get_data()['date'] );
	}

	/**
	 * A page the run already did is left out when it goes on, and the plan says where it stopped.
	 */
	public function test_a_stopped_run_goes_on_with_the_pages_left() {
		$this->become( 'administrator' );
		$other = self::factory()->post->create( array( 'post_title' => 'O nama' ) );
		$this->call(
			'/translate-all/progress',
			array(
				'lang'    => 'de',
				'page'    => 'post:' . $other,
				'outcome' => 'done',
				'total'   => 2,
			)
		);

		$data = $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_data();
		$this->assertSame( 'running', $data['status'] );
		$this->assertSame( array( 'post:' . $this->post->ID ), wp_list_pluck( $data['pages'], 'key' ) );
	}

	/**
	 * A language that is done cannot be done again: the server refuses it, whatever the screen shows.
	 */
	public function test_a_language_that_is_done_is_refused() {
		$this->become( 'administrator' );
		$this->call(
			'/translate-all/progress',
			array(
				'lang'   => 'de',
				'finish' => true,
			)
		);

		$plan = $this->call( '/translate-all/plan', array( 'lang' => 'de' ) );
		$this->assertSame( 409, $plan->get_status() );
		$this->assertSame( 'swiftling_translate_all_done', $plan->as_error()->get_error_code() );

		$progress = $this->call(
			'/translate-all/progress',
			array(
				'lang'    => 'de',
				'page'    => 'post:' . $this->post->ID,
				'outcome' => 'done',
			)
		);
		$this->assertSame( 409, $progress->get_status() );
		$this->assertSame( 'swiftling_translate_all_done', $progress->as_error()->get_error_code() );

		// Another language can still be done.
		$this->assertSame( 200, $this->call( '/translate-all/plan', array( 'lang' => 'fr' ) )->get_status() );
	}

	/**
	 * Another plugin drives a run on the server through swiftling_translate_all_record(), as the
	 * browser does through the progress route: the same run, which then says the server drives it.
	 * While the server drives a language, the browser is refused for it; once the server has not
	 * moved for a while, as when it stopped, the browser may go on with it.
	 */
	public function test_a_run_the_server_drives() {
		$this->become( 'administrator' );
		$state = swiftling_translate_all_record(
			'de',
			array(
				'page'       => 'post:' . $this->post->ID,
				'outcome'    => 'done',
				'texts'      => 3,
				'characters' => 120,
				'total'      => 2,
				'driver'     => 'server',
			)
		);
		$this->assertIsArray( $state );
		$this->assertSame( array( 'running', 'server' ), array( $state['status'], $state['driver'] ) );
		$this->assertSame( array( $this->post->ID ), $state['pages'] );

		$busy = $this->call(
			'/translate-all/progress',
			array(
				'lang'  => 'de',
				'total' => 2,
			)
		);
		$this->assertSame( 409, $busy->get_status() );
		$this->assertSame( 'swiftling_translate_all_busy', $busy->as_error()->get_error_code() );
		$this->assertSame( 409, $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_status() );
		$this->assertSame( 200, $this->call( '/translate-all/plan', array( 'lang' => 'fr' ) )->get_status(), 'Another language' );

		// The server last moved long ago: it stopped, and the browser goes on.
		$runs                   = get_option( SWIFTLING_TRANSLATE_ALL_OPTION );
		$runs['de_DE']['beat'] -= HOUR_IN_SECONDS;
		update_option( SWIFTLING_TRANSLATE_ALL_OPTION, $runs );
		$this->assertSame( '', swiftling_translate_all_state( 'de' )['driver'] );
		$this->assertSame( 200, $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_status() );

		// The browser drives it now; the server is refused until the browser says it stopped.
		$this->assertSame(
			200,
			$this->call(
				'/translate-all/progress',
				array(
					'lang'  => 'de',
					'total' => 2,
				)
			)->get_status()
		);
		$this->assertSame( 'browser', swiftling_translate_all_state( 'de' )['driver'] );
		$this->assertSame(
			'swiftling_translate_all_busy',
			swiftling_translate_all_record(
				'de',
				array(
					'total'  => 2,
					'driver' => 'server',
				)
			)->get_error_code()
		);
		$this->call(
			'/translate-all/progress',
			array(
				'lang'    => 'de',
				'total'   => 2,
				'stopped' => true,
			)
		);
		$this->assertSame( '', swiftling_translate_all_state( 'de' )['driver'] );
		$this->assertIsArray(
			swiftling_translate_all_record(
				'de',
				array(
					'total'  => 2,
					'driver' => 'server',
				)
			)
		);
	}

	/**
	 * A language the server translates says so on its tile and cannot be chosen; another plugin adds
	 * a way to start beside the screen's own.
	 */
	public function test_the_screen_shows_a_run_on_the_server() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		swiftling_translate_all_record(
			'de',
			array(
				'page'    => 'post:' . $this->post->ID,
				'outcome' => 'done',
				'total'   => 3,
				'driver'  => 'server',
			)
		);
		add_action(
			'swiftling_translate_all_start_actions',
			static function () {
				echo '<button type="button" class="added-start">Another way</button>';
			}
		);

		$html = $this->render();
		$this->assertStringContainsString( 'On the server: 1 of 3 pages', $html );
		$this->assertMatchesRegularExpression( '~value="de"[^>]*disabled~', $html );
		$this->assertMatchesRegularExpression( '~id="swiftling-all-start"[^>]*>.*?</button>\s*<button type="button" class="added-start">Another way</button>~s', $html );
		set_current_screen( 'front' );
	}

	/**
	 * The screen asks for a service before anything else, and shows a tile per language once one is
	 * connected: what is left to do, and the date a finished language was done on.
	 */
	public function test_the_screen_asks_for_a_service_and_shows_the_languages() {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );

		$without = $this->render();
		$this->assertStringContainsString( 'No translation service is connected.', $without );
		$this->assertStringContainsString( 'page=swiftling-service', $without );
		$this->assertStringNotContainsString( 'swiftling-all-tile', $without );

		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->call(
			'/translate-all/progress',
			array(
				'lang'   => 'fr',
				'finish' => true,
			)
		);

		$html = $this->render();
		$this->assertStringContainsString( 'Translate everything into Deutsch', $html );
		$this->assertStringContainsString( '2 empty texts', $html );
		$this->assertStringContainsString( '✓ Done on ' . wp_date( get_option( 'date_format' ), time() ), $html );
		$this->assertStringContainsString( 'characters billed by Fake to your key', $html );
		// The language that is done cannot be chosen again.
		$this->assertMatchesRegularExpression( '~value="fr"[^>]*disabled~', $html );
		set_current_screen( 'front' );
	}

	/**
	 * Renders the screen.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		( new \SwiftLing\Admin\Menu() )->renderScreen( 'translate-all' );
		return (string) ob_get_clean();
	}

	/**
	 * Translate everything never translates comments, in the Free and the Pro version alike: a site
	 * can have thousands, the run sends everything it finds to the service, and the service bills
	 * characters. They are translated on the Comments screen, when the user chooses. Comments change
	 * nothing in the plan or the scan, and the run's own routes take none.
	 */
	public function test_comments_are_no_part_of_the_run() {
		$this->become( 'administrator' );
		$before = $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_data();

		$ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[] = self::factory()->comment->create(
				array(
					'comment_post_ID'  => $this->post->ID,
					'comment_content'  => 'Komentar ' . $i,
					'comment_approved' => '1',
				)
			);
		}
		$this->translate( 'comment:' . $ids[0], 'field/comment_content', 'Komentar 1', 'de', 'Kommentar 1' );

		$after = $this->call( '/translate-all/plan', array( 'lang' => 'de' ) )->get_data();
		$this->assertSame( $before['pages'], $after['pages'], 'The same pages, with the same counts' );
		$this->assertSame( $before['texts'], $after['texts'] );
		$this->assertSame( $before['characters'], $after['characters'] );
		foreach ( swiftling_translate_all_scan() as $page ) {
			$this->assertStringStartsWith( 'post:', $page['key'], 'Only pages and posts' );
		}

		// The page check of a run answers with texts of the page; a list of comments is no page to
		// check, and what asks for its texts as a run does gets none.
		$subject = swiftling_subject( 'comments:post' );
		$this->assertFalse( $subject['probe'] );
		$result = swiftling_save_comment_list( $subject, 'de', array( 'show' => 'all' ) );
		$this->assertSame( array(), swiftling_translate_all_items( $subject, 'de', $result ) );
		$this->assertNotEmpty( swiftling_comment_batch( $subject, 'de', array() )['items'], 'The Comments screen still translates them' );

		// A run does not record a list of comments as a page it did.
		$state = $this->call(
			'/translate-all/progress',
			array(
				'lang'    => 'de',
				'page'    => 'comments:post',
				'outcome' => 'done',
			)
		)->get_data();
		$this->assertSame( array(), $state['pages'] );
	}

	/**
	 * The files that make the run name no comment function or list: nothing in them could reach a
	 * comment by itself, and a change that makes them do so fails here.
	 */
	public function test_the_run_does_not_reach_for_comments() {
		$root = dirname( __DIR__ ) . '/';
		foreach ( array( 'includes/translate-all.php', 'assets/js/translate-all.js', 'admin/views/translate-all.php' ) as $file ) {
			$code = (string) file_get_contents( $root . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file of the plugin itself.
			$this->assertSame( 0, preg_match( '/swiftling_comment|comment-batch|comments:/', $code ), $file . ' names no comment function, route or list' );
		}
	}
}
