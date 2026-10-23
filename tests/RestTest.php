<?php
/**
 * Tests of the editor's REST routes.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WP_REST_Request;

/**
 * The routes the translation editor calls: who may call them, and what they accept.
 */
class RestTest extends TestCase {

	/**
	 * A checked post with a title, a meta text, the site title and a link address.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Makes the post and checks its page.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'blogname', 'Moja stranica' );
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		add_post_meta( $this->post->ID, 'cta', 'https://example.org/kontakt/' );
		add_post_meta( $this->post->ID, 'subtitle', 'Javite nam se' );
		$post = $this->post;
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
				get_option( 'blogname' );
				get_post_meta( $post->ID, 'cta', true );
				get_post_meta( $post->ID, 'subtitle', true );
			},
			array( 'meta/cta' => 'href' )
		);
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
	 * Returns the place key of a text of the post.
	 *
	 * @param string $path     Path.
	 * @param string $original Original.
	 * @param string $owner    Object; the post by default.
	 * @return string
	 */
	private function place( $path, $original, $owner = '' ) {
		return swiftling_place_key( '' !== $owner ? $owner : 'post:' . $this->post->ID, swiftling_path_key( $path ), swiftling_hash( $original ) );
	}

	/**
	 * Every route is registered.
	 */
	public function test_routes() {
		$routes = rest_get_server()->get_routes( 'swiftling/v1' );
		foreach ( array( '/probe', '/translations', '/machine', '/mode' ) as $route ) {
			$this->assertArrayHasKey( '/swiftling/v1' . $route, $routes, $route );
		}
	}

	/**
	 * Visitors and people who do not manage the site may not call the routes.
	 */
	public function test_routes_need_rights() {
		$params = array(
			'subject' => 'post:' . $this->post->ID,
			'lang'    => 'de',
			'status'  => 'reviewed',
			'items'   => array(),
		);
		$this->assertSame( 401, $this->call( '/translations', $params )->get_status() );
		$this->become( 'editor' );
		$this->assertSame( 403, $this->call( '/translations', $params )->get_status() );
		$this->assertSame( 403, $this->call( '/machine', $params )->get_status() );
		$this->assertSame(
			403,
			$this->call(
				'/mode',
				array(
					'post' => $this->post->ID,
					'lang' => 'de',
					'mode' => 'duplicate',
				)
			)->get_status()
		);
	}

	/**
	 * A language the site is not translated into, or an unknown status, is refused.
	 */
	public function test_parameters_are_validated() {
		$this->become( 'administrator' );
		$params = array(
			'subject' => 'post:' . $this->post->ID,
			'lang'    => 'it',
			'status'  => 'reviewed',
			'items'   => array(),
		);
		$this->assertSame( 400, $this->call( '/translations', $params )->get_status() );
		$params['lang']   = 'de';
		$params['status'] = 'official';
		$this->assertSame( 400, $this->call( '/translations', $params )->get_status() );
	}

	/**
	 * Translations are saved for the places of the subject only, and the answer gives their states.
	 */
	public function test_saving_translations() {
		$this->become( 'administrator' );
		$title    = $this->place( 'field/post_title', 'Kontakt' );
		$site     = $this->place( 'option/blogname', 'Moja stranica', 'options' );
		$response = $this->call(
			'/translations',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'status'  => 'machine',
				'items'   => array(
					array(
						'key'  => $title,
						'text' => 'Kontaktieren',
					),
					array(
						'key'  => $site,
						'text' => 'Meine Seite',
					),
					array(
						'key'  => md5( 'somewhere else' ),
						'text' => 'Ignored',
					),
					'not an item',
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array(
				$title => 'machine',
				$site  => 'machine',
			),
			$data['states']
		);
		$this->assertSame( 'partial', $data['languages']['de'] );
		$saved = swiftling_get_translations( 'de_DE', array( $title, $site, md5( 'somewhere else' ) ) );
		$this->assertCount( 2, $saved );
		$this->assertSame( SWIFTLING_MACHINE, $saved[ $title ]['status'] );
	}

	/**
	 * A blank translation removes the translation.
	 */
	public function test_blank_translation_removes_it() {
		$this->become( 'administrator' );
		$title = $this->place( 'field/post_title', 'Kontakt' );
		$this->translate( 'post:' . $this->post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
		$response = $this->call(
			'/translations',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'  => $title,
						'text' => "  \n ",
					),
				),
			)
		);
		$this->assertSame( array( $title => 'missing' ), $response->get_data()['states'] );
		$this->assertSame( array(), swiftling_get_translations( 'de_DE', array( $title ) ) );
	}

	/**
	 * Someone who may not write unfiltered HTML gets their translation cleaned, as WordPress cleans posts.
	 */
	public function test_translations_are_cleaned_without_unfiltered_html() {
		$this->become( 'administrator' );
		add_filter(
			'map_meta_cap',
			static function ( $caps, $cap ) {
				return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);
		$subtitle = $this->place( 'meta/subtitle', 'Javite nam se' );
		$this->call(
			'/translations',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'  => $subtitle,
						'text' => '<strong onclick="steal()">Melden</strong><script>steal()</script> Sie sich',
					),
				),
			)
		);
		$saved = swiftling_get_translations( 'de_DE', array( $subtitle ) )[ $subtitle ]['text'];
		$this->assertStringNotContainsString( '<script', $saved );
		$this->assertStringNotContainsString( 'onclick', $saved );
		$this->assertStringContainsString( '<strong>Melden</strong>', $saved );
	}

	/**
	 * An address can only become another address, never a script.
	 */
	public function test_addresses_stay_addresses() {
		$this->become( 'administrator' );
		$cta = swiftling_place_key( 'post:' . $this->post->ID, swiftling_link_key( 'meta/cta' ), swiftling_hash( 'https://example.org/kontakt/' ) );
		$this->assertSame( 'url', swiftling_translatable_keys( 'post:' . $this->post->ID )[ $cta ] );

		$save = function ( $text ) use ( $cta ) {
			$this->call(
				'/translations',
				array(
					'subject' => 'post:' . $this->post->ID,
					'lang'    => 'de',
					'status'  => 'reviewed',
					'items'   => array(
						array(
							'key'  => $cta,
							'text' => $text,
						),
					),
				)
			);
			$found = swiftling_get_translations( 'de_DE', array( $cta ) );
			return isset( $found[ $cta ] ) ? $found[ $cta ]['text'] : null;
		};
		$this->assertSame( 'https://example.de/kontakt/', $save( ' https://example.de/kontakt/ ' ) );
		$this->assertNull( $save( 'javascript:alert(1)' ) );
	}

	/**
	 * Without a connected service the machine route says what to do.
	 */
	public function test_machine_without_a_service() {
		$this->become( 'administrator' );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(),
			)
		);
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'swiftling_machine_service', $response->as_error()->get_error_code() );
	}

	/**
	 * The machine route sends only texts of places the user may translate, never addresses, and counts the characters.
	 */
	public function test_machine_translation() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );
		$title    = $this->place( 'field/post_title', 'Kontakt' );
		$cta      = swiftling_place_key( 'post:' . $this->post->ID, swiftling_link_key( 'meta/cta' ), swiftling_hash( 'https://example.org/kontakt/' ) );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $title,
						'text' => 'Kontakt',
					),
					array(
						'key'  => $cta,
						'text' => 'https://example.org/kontakt/',
					),
					array(
						'key'  => md5( 'elsewhere' ),
						'text' => 'Tajna',
					),
					array(
						'key'  => $this->place( 'meta/subtitle', 'Javite nam se' ),
						'text' => '   ',
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $title => 'KONTAKT' ), (array) $response->get_data()['translations'] );
		$this->assertSame( array( $title ), array_keys( FakeService::$sent ) );
		$this->assertSame( 7, \SwiftLing\Translation\Services::usage( 'fake' ) );
		$this->assertSame( 0, \SwiftLing\Translation\Services::tokens( 'fake' ), 'A service that counts no tokens' );
		$this->assertSame( array(), swiftling_get_translations( 'de_DE', array( $title ) ), 'The editor saves them itself' );
	}

	/**
	 * The tokens a language model read and wrote are counted beside the characters.
	 */
	public function test_machine_translation_counts_tokens() {
		FakeService::connect();
		FakeService::$tokens = 40;
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $this->place( 'field/post_title', 'Kontakt' ),
						'text' => 'Kontakt',
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 7, \SwiftLing\Translation\Services::usage( 'fake' ) );
		$this->assertSame( 40, \SwiftLing\Translation\Services::tokens( 'fake' ) );
	}

	/**
	 * Words that are never translated go to the service as parts to keep, and the answer names the
	 * ones each text kept.
	 */
	public function test_machine_translation_keeps_listed_words() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		update_option( 'swiftling_never_words', array( 'Kontakt', 'Split' ) );
		$this->become( 'administrator' );
		$title    = $this->place( 'field/post_title', 'Kontakt' );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $title,
						'text' => 'Kontakt',
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'Kontakt' ), FakeService::$sent[ $title ]['keep'] );
		$this->assertSame( array( $title => array( 'Kontakt' ) ), (array) $response->get_data()['kept'] );
	}

	/**
	 * The service is told the languages it translates between, as SwiftLing has them, not only its
	 * codes: a formal variant or a glossary is the language's.
	 */
	public function test_machine_tells_the_service_its_languages() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $this->place( 'field/post_title', 'Kontakt' ),
						'text' => 'Kontakt',
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( swiftling_languages()['source']['locale'], 'de_DE' ), FakeService::$languages );
	}

	/**
	 * Another plugin adds to what is sent, as parts to keep, and changes what comes back, only for
	 * the texts that were sent.
	 */
	public function test_machine_filters_what_is_sent_and_comes_back() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );
		$title    = $this->place( 'field/post_title', 'Kontakt' );
		$subtitle = $this->place( 'meta/subtitle', 'Javite nam se' );
		add_filter(
			'swiftling_machine_items',
			static function ( $items, $service, $language ) {
				foreach ( $items as $key => $item ) {
					$items[ $key ]['keep'] = array( 'Kontakt' );
				}
				$items['added'] = array(
					'text' => 'Not one of the page',
					'html' => false,
				);
				return 'fake' === $service->id() && 'de_DE' === $language['locale'] ? $items : array();
			},
			10,
			3
		);
		add_filter(
			'swiftling_machine_translations',
			static function ( $translations, $items ) {
				$translations[ array_keys( $items )[0] ] = str_replace( 'KONTAKT', 'Kontaktseite', $translations[ array_keys( $items )[0] ] );
				$translations['added']                   = 'Hinzu';
				$translations['broken']                  = array( 'Not text' );
				return $translations;
			},
			10,
			2
		);
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $title,
						'text' => 'Kontakt',
					),
					array(
						'key'  => $subtitle,
						'text' => 'Javite nam se',
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $title, $subtitle ), array_keys( FakeService::$sent ), 'Only texts of the page are sent' );
		$this->assertSame( array( 'Kontakt' ), FakeService::$sent[ $title ]['keep'] );
		$this->assertSame(
			array(
				$title    => 'Kontaktseite',
				$subtitle => 'JAVITE NAM SE',
			),
			(array) $response->get_data()['translations']
		);
	}

	/**
	 * Too many texts at once are refused before anything is sent.
	 */
	public function test_machine_size_limit() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );
		$title    = $this->place( 'field/post_title', 'Kontakt' );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $title,
						'text' => str_repeat( 'a', SWIFTLING_MACHINE_MAX_CHARACTERS + 1 ),
					),
				),
			)
		);
		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( array(), FakeService::$sent );
	}

	/**
	 * A language the service does not translate is named.
	 */
	public function test_machine_language_not_supported() {
		FakeService::connect();
		FakeService::$unsupported = array( 'de_DE' );
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(),
			)
		);
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'Fake does not translate Deutsch.', $response->as_error()->get_error_message() );
	}

	/**
	 * A page check is accepted only with the list the server printed and signed.
	 */
	public function test_probe_needs_the_signed_list() {
		$this->become( 'administrator' );
		$leaves   = $this->probe(
			function () {
				get_the_title( $this->post );
			}
		);
		$registry = wp_json_encode(
			array(
				'subject'  => 'post:' . $this->post->ID,
				'template' => '',
				'leaves'   => $leaves,
			)
		);
		$params   = array(
			'subject'   => 'post:' . $this->post->ID,
			'lang'      => 'de',
			'registry'  => $registry,
			'signature' => 'forged',
			'found'     => array( 1 => array( $this->landing( 'text' ) ) ),
			'control'   => array(),
		);
		$this->assertSame( 400, $this->call( '/probe', $params )->get_status() );

		$params['signature'] = swiftling_probe_signature( $registry );
		$params['subject']   = 'post:' . self::factory()->post->create();
		$this->assertSame( 400, $this->call( '/probe', $params )->get_status(), 'A list printed for another page' );

		$params['subject'] = 'post:' . $this->post->ID;
		$response          = $this->call( '/probe', $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Kontakt', $response->get_data()['rows'] );
		$map = swiftling_get_maps( array( 'post:' . $this->post->ID ) )[ 'post:' . $this->post->ID ];
		$this->assertSame( array( 'field/post_title' ), $map['sources'] );
	}

	/**
	 * The block editor's panel chooses how a language shows the post.
	 */
	public function test_mode_route() {
		$this->become( 'administrator' );
		$response = $this->call(
			'/mode',
			array(
				'post' => $this->post->ID,
				'lang' => 'de',
				'mode' => 'duplicate',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'duplicate', $response->get_data()['done'] );
		$this->assertSame( array( 'de_DE' ), swiftling_duplicate_locales( $this->post->ID ) );
	}
}
