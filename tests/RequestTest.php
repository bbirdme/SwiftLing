<?php
/**
 * Tests of the requests for support of a plugin, theme or form SwiftLing does not translate yet.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Translations offers to ask for a plugin, theme or form to be tested and added; the request goes to
 * swiftling.pro only when it is sent, and can be emailed when it cannot be sent.
 */
class RequestTest extends TestCase {

	/**
	 * What was sent to swiftling.pro, as wp_remote_post() was asked.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	private $sent = array();

	/**
	 * The answer swiftling.pro gives in the test: an HTTP status, or a WP_Error.
	 *
	 * @var int|\WP_Error
	 */
	private $answer = 201;

	/**
	 * Requests to swiftling.pro are answered here; no other request leaves.
	 */
	public function set_up() {
		parent::set_up();
		$this->sent   = array();
		$this->answer = 201;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( 0 !== strpos( $url, 'https://swiftling.pro/' ) ) {
					return $pre;
				}
				$this->sent[] = array(
					'url'  => $url,
					'args' => $args,
				);
				if ( is_wp_error( $this->answer ) ) {
					return $this->answer;
				}
				return array(
					'headers'  => array(),
					'body'     => '{}',
					'response' => array(
						'code'    => $this->answer,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * A request as the screen sends it.
	 *
	 * @param array $more Fields to change.
	 * @return array
	 */
	private function fields( $more = array() ) {
		return array_merge(
			array(
				'kind'   => 'plugin',
				'which'  => 'theme:' . get_stylesheet(),
				'name'   => '',
				'what'   => 'The questions of its FAQ block stay in English on /de/ pages.',
				'page'   => 'https://example.org/de/faq/',
				'email'  => 'owner@example.org',
				'report' => '1',
			),
			$more
		);
	}

	/**
	 * Sends a request through the screen's form and returns where it leads.
	 *
	 * @param array $fields Fields.
	 * @return string
	 */
	private function send( $fields ) {
		$this->post_form( 'swiftling_send_request', $fields );
		return $this->redirect_of( 'swiftling_handle_request' );
	}

	/**
	 * The list of what to ask for holds the site's theme and plugins with their versions, never
	 * SwiftLing itself.
	 */
	public function test_choices_are_the_sites_theme_and_plugins() {
		$choices = swiftling_request_choices();
		$theme   = wp_get_theme();
		$this->assertSame( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ), $choices[ 'theme:' . get_stylesheet() ]['label'] );
		foreach ( $choices as $key => $choice ) {
			$this->assertStringNotContainsString( 'SwiftLing', $choice['label'], $key );
		}
	}

	/**
	 * A request goes to swiftling.pro as JSON with what was written, the email and, when ticked,
	 * the report for support; the person then reads that it was sent. It is noted in the log,
	 * without the email.
	 */
	public function test_request_is_sent() {
		$this->become( 'administrator' );
		$location = $this->send( $this->fields() );

		$this->assertStringContainsString( 'page=swiftling-request', $location );
		$this->assertStringContainsString( 'swiftling_notice=request_sent', $location );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'https://swiftling.pro/wp-json/swiftling/v1/requests', $this->sent[0]['url'] );
		$payload = json_decode( $this->sent[0]['args']['body'], true );
		$this->assertSame( 'plugin', $payload['kind'] );
		$this->assertSame( wp_get_theme()->get( 'Name' ), $payload['name'] );
		$this->assertSame( 'The questions of its FAQ block stay in English on /de/ pages.', $payload['what'] );
		$this->assertSame( 'https://example.org/de/faq/', $payload['page'] );
		$this->assertSame( 'owner@example.org', $payload['email'] );
		$this->assertStringContainsString( 'SwiftLing support report', $payload['report'] );
		$this->assertSame( SWIFTLING_VERSION, $payload['swiftling'] );

		global $wpdb;
		$logged = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM %i ORDER BY id DESC LIMIT 1', swiftling_table( 'log' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own log.
		$this->assertStringContainsString( wp_get_theme()->get( 'Name' ), $logged );
		$this->assertStringNotContainsString( 'owner@example.org', $logged );
	}

	/**
	 * Without the tick, the report stays home; another plugin or theme is named in words.
	 */
	public function test_without_the_report_and_with_another_name() {
		$this->become( 'administrator' );
		$this->send(
			$this->fields(
				array(
					'report' => '',
					'which'  => 'other',
					'name'   => 'Bricks',
				)
			)
		);
		$payload = json_decode( $this->sent[0]['args']['body'], true );
		$this->assertSame( '', $payload['report'] );
		$this->assertSame( 'Bricks', $payload['name'] );
	}

	/**
	 * Nothing is sent without what stays untranslated, an email, or a name for another plugin; the
	 * screen keeps what was written.
	 *
	 * @dataProvider data_incomplete
	 *
	 * @param array $more Fields that make it incomplete.
	 */
	public function test_incomplete_request_is_not_sent( $more ) {
		$user     = $this->become( 'administrator' );
		$location = $this->send( $this->fields( $more ) );
		$this->assertStringContainsString( 'swiftling_notice=request_incomplete', $location );
		$this->assertSame( array(), $this->sent );
		$this->assertSame( $this->fields( $more )['what'], get_transient( 'swiftling_request_draft_' . $user )['what'] );
	}

	/**
	 * Incomplete requests.
	 *
	 * @return array
	 */
	public function data_incomplete() {
		return array(
			'no text'  => array( array( 'what' => '  ' ) ),
			'no email' => array( array( 'email' => 'owner' ) ),
			'no name'  => array(
				array(
					'which' => 'other',
					'name'  => '',
				),
			),
		);
	}

	/**
	 * When swiftling.pro cannot be reached, the request waits on the screen, ready to email to
	 * support@swiftling.pro, and the failure is in the log.
	 *
	 * @dataProvider data_failures
	 *
	 * @param int|\WP_Error $answer What swiftling.pro answers.
	 */
	public function test_request_that_cannot_be_sent_can_be_emailed( $answer ) {
		$this->answer = $answer;
		$this->become( 'administrator' );
		$location = $this->send( $this->fields() );
		$this->assertStringContainsString( 'swiftling_notice=request_failed', $location );

		$_GET = array( 'swiftling_notice' => 'request_failed' );
		$html = $this->render_screen( 'request' );
		$this->assertStringContainsString( 'mailto:support@swiftling.pro', $html );
		$this->assertStringContainsString( 'The questions of its FAQ block stay in English on /de/ pages.', $html );

		global $wpdb;
		$this->assertSame( 'error', $wpdb->get_var( $wpdb->prepare( 'SELECT level FROM %i ORDER BY id DESC LIMIT 1', swiftling_table( 'log' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- SwiftLing's own log.
	}

	/**
	 * What swiftling.pro may answer instead of taking the request.
	 *
	 * @return array
	 */
	public function data_failures() {
		return array(
			'not found'   => array( 404 ),
			'no response' => array( new \WP_Error( 'http_request_failed', 'cURL error 28' ) ),
		);
	}

	/**
	 * One person sends at most five requests an hour, so a request is not sent twice by accident and
	 * the address cannot be flooded from a site.
	 */
	public function test_requests_are_limited() {
		$this->become( 'administrator' );
		for ( $n = 0; $n < 5; $n++ ) {
			$this->assertStringContainsString( 'request_sent', $this->send( $this->fields() ) );
		}
		$this->assertStringContainsString( 'swiftling_notice=request_limit', $this->send( $this->fields() ) );
		$this->assertCount( 5, $this->sent );
	}

	/**
	 * Only people who manage the site send requests.
	 */
	public function test_editor_cannot_send() {
		$this->become( 'editor' );
		$this->post_form( 'swiftling_send_request', $this->fields() );
		try {
			swiftling_handle_request();
			$this->fail( 'An editor may not send a request.' );
		} catch ( \WPDieException $die ) {
			$this->assertSame( array(), $this->sent );
		}
	}

	/**
	 * The screen asks what it is, which one, what stays untranslated, a page and an email, with the
	 * report ticked and readable first, and says what is sent and when.
	 */
	public function test_screen() {
		$this->become( 'administrator' );
		$_GET = array( 'kind' => 'form' );
		$html = $this->render_screen( 'request' );
		$this->assertMatchesRegularExpression( '~name="kind" value="form"\s+checked~', $html, 'The kind it was opened for' );
		$this->assertStringContainsString( 'value="theme:' . esc_attr( get_stylesheet() ) . '"', $html );
		$this->assertMatchesRegularExpression( '~name="report" value="1"\s+checked~', $html );
		$this->assertStringContainsString( 'SwiftLing support report', $html, 'The report, to read first' );
		$this->assertStringContainsString( 'Sent to swiftling.pro only when you press Send', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( wp_get_current_user()->user_email ) . '"', $html );

		$_GET = array(
			'swiftling_notice' => 'request_sent',
			'swiftling_which'  => 'Bricks',
		);
		$this->assertStringContainsString( 'We test Bricks and write to you at the email you gave.', $this->render_screen( 'request' ) );
	}

	/**
	 * The screens of Translations offer the request under their list; Settings does not. Forms'
	 * "My form is not listed" and the translation editor of a page lead to it too.
	 */
	public function test_request_is_offered_where_translating() {
		$this->become( 'administrator' );
		$this->set_languages();
		$_GET = array();
		$link = esc_url( admin_url( 'admin.php?page=swiftling-request' ) );
		$this->assertStringContainsString( 'We test it and add it to SwiftLing', $this->render_screen( 'content' ) );
		$this->assertStringContainsString( $link, $this->render_screen( 'terms' ) );
		$this->assertStringNotContainsString( $link, $this->render_screen( 'languages' ) );

		$_GET = array( 'help' => 'not-listed' );
		$this->assertStringContainsString( esc_url( add_query_arg( 'kind', 'form', admin_url( 'admin.php?page=swiftling-request' ) ) ), $this->render_screen( 'forms' ) );

		$post = self::factory()->post->create( array( 'post_title' => 'Kontakt' ) );
		$_GET = array(
			'post' => (string) $post,
			'lang' => 'de',
		);
		$this->assertStringContainsString( $link, $this->render_screen( 'editor' ) );
	}
}
