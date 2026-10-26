<?php
/**
 * Tests of the placeholders of texts from code in their translations.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Translation\Google;
use SwiftLing\Translation\Services;
use WP_REST_Request;

/**
 * A text from code may go through sprintf(), as "%1$s - Image %2$d". A translation whose
 * placeholders do not fit stops the page on PHP 8, so it is never shown, never saved, and never
 * filled in from machine translation.
 */
class PlaceholdersTest extends TestCase {

	/**
	 * The text from code the tests translate, as a theme writes it.
	 */
	const FORMAT = '%1$s - Image %2$d';

	/**
	 * The post whose page shows the text.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Path of the text in the object of its text domain.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Checks a page that shows the text.
	 */
	public function set_up() {
		parent::set_up();
		$this->post = self::factory()->post->create_and_get();
		$this->path = swiftling_string_path( array( 't' => self::FORMAT ) );
		$this->check(
			'post:' . $this->post->ID,
			static function () {
				__( self::FORMAT, 'zz-theme' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment -- A theme's text, as the theme writes it.
			},
			array( $this->path => 'text' )
		);
	}

	/**
	 * The field of a text with placeholders names them, and points to the note that says to keep
	 * them; the field of the title, which has none, does neither.
	 */
	public function test_field_names_its_placeholders() {
		$this->become( 'administrator' );
		$post   = $this->post;
		$result = $this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				__( self::FORMAT, 'zz-theme' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment -- A theme's text, as the theme writes it.
				get_the_title( $post );
			},
			array( $this->path => 'text' )
		);
		$html   = swiftling_live_rows_html( swiftling_subject( 'post:' . $this->post->ID ), 'de', $result );

		$this->assertSame( 1, preg_match_all( '~<textarea[^>]* data-format="%1\$s %2\$d" aria-describedby="([^"]+)"~', $html, $field ) );
		$this->assertStringContainsString( 'id="' . $field[1][0] . '" aria-live="polite"', $html );
		$this->assertSame( 1, substr_count( $html, 'data-format=' ) );
	}

	/**
	 * The placeholders of a text, each with the value it takes.
	 */
	public function test_placeholders() {
		$this->assertSame( array(), swiftling_format_placeholders( 'No placeholder' ) );
		$this->assertSame(
			array( '%1$s/1/s', '%s/1/s', '%.2f/2/f', "%'*10d/3/d" ),
			array_map(
				static function ( $placeholder ) {
					return $placeholder['raw'] . '/' . $placeholder['position'] . '/' . $placeholder['type'];
				},
				swiftling_format_placeholders( "%1\$s, %s and %.2f %% %'*10d" )
			)
		);
		// A "%" that is no placeholder: no format, or a broken one.
		$this->assertNull( swiftling_format_placeholders( '50% off' ) );
		$this->assertNull( swiftling_format_placeholders( 's$1% ,s$2%' ) );
		$this->assertNull( swiftling_format_placeholders( '%1 $s' ) );
	}

	/**
	 * What would stop the page, and what only leaves a value out.
	 *
	 * @dataProvider data_problems
	 *
	 * @param string $original    Text in the code.
	 * @param string $translation Translation.
	 * @param bool   $breaks      Whether it would stop the page.
	 * @param array  $missing     Placeholders left out.
	 */
	public function test_problems( $original, $translation, $breaks, $missing ) {
		$this->assertSame(
			array(
				'breaks'  => $breaks,
				'missing' => $missing,
			),
			swiftling_format_problems( $original, $translation )
		);
		// Where the check says the translation of a format is safe, PHP agrees. A text that is no
		// format never goes through sprintf().
		if ( ! $breaks && swiftling_format_placeholders( $original ) ) {
			$this->assertIsString( sprintf( $translation, 'A', 'B', 'C' ) ); // phpcs:ignore WordPress.WP.I18n -- A translation under test.
		}
	}

	/**
	 * Translations and what they do.
	 *
	 * @return array
	 */
	public function data_problems() {
		return array(
			'the same placeholders'           => array( '%1$s, %2$s or %3$s', '%1$s, %2$s oder %3$s', false, array() ),
			'in another order, right to left' => array( '%1$s, %2$s or %3$s', '%3$s أو %2$s ،%1$s', false, array() ),
			'numbered for unnumbered'         => array( '%s of %s', '%2$s von %1$s', false, array() ),
			'a percent sign written twice'    => array( '%d%% done', '%d%% fertig', false, array() ),
			'one left out'                    => array( '%1$s - Image %2$d', 'Bild %2$d', false, array( '%1$s' ) ),
			'one too many'                    => array( '%1$s, %2$s or %3$s', '%1$s, %2$s, %3$s oder %4$s', true, array() ),
			'reversed, right to left'         => array( '%1$s - Image %2$d', 's$1% - d$2%', true, array( '%1$s', '%2$d' ) ),
			// A translation that cannot be read leaves every placeholder out.
			'a space inside'                  => array( '%1$s and %2$s', '%1 $s und %2$s', true, array( '%1$s', '%2$s' ) ),
			'another type'                    => array( 'Image %d', 'Bild %s', true, array() ),
			'a stray percent sign'            => array( '%s done', '100% %s fertig', true, array( '%s' ) ),
			'no format at all'                => array( '50% off', '50 % Rabatt', false, array() ),
			'no placeholder in the original'  => array( 'Read more', 'Weiterlesen %s', false, array() ),
		);
	}

	/**
	 * A translation that would stop the page is not shown: the text of the theme is. One that keeps
	 * the placeholders, in any order, is shown.
	 */
	public function test_translated_view_never_breaks_the_page() {
		$this->translate( 'gettext:zz-theme', $this->path, self::FORMAT, 'de', 'Bild %2$d von %1$s und %3$s' );
		$this->show_in( 'de' );
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment -- A theme's text, as the theme writes it.
		$this->assertSame( 'Haus - Image 2', sprintf( __( self::FORMAT, 'zz-theme' ), 'Haus', 2 ) );

		$this->translate( 'gettext:zz-theme', $this->path, self::FORMAT, 'de', 'Bild %2$d von %1$s' );
		$this->show_in( 'de' );
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch, WordPress.WP.I18n.MissingTranslatorsComment -- A theme's text, as the theme writes it.
		$this->assertSame( 'Bild 2 von Haus', sprintf( __( self::FORMAT, 'zz-theme' ), 'Haus', 2 ) );
	}

	/**
	 * Sends a request to a route of SwiftLing.
	 *
	 * @param string $route  Route.
	 * @param array  $params Body.
	 * @return \WP_REST_Response
	 */
	private function call( $route, $params ) {
		$request = new WP_REST_Request( 'POST', '/swiftling/v1' . $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Returns the place of the text.
	 *
	 * @return string
	 */
	private function place() {
		return swiftling_place_key( 'gettext:zz-theme', swiftling_path_key( $this->path ), swiftling_hash( self::FORMAT ) );
	}

	/**
	 * Saving refuses a translation that would stop the page, and says so; a good one is saved.
	 */
	public function test_saving_refuses_a_breaking_translation() {
		$this->become( 'administrator' );
		$response = $this->call(
			'/translations',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'      => $this->place(),
						'text'     => 's$1% - Bild d$2%',
						'original' => self::FORMAT,
					),
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $this->place() ), $response->get_data()['refused'] );
		$this->assertSame( array(), swiftling_get_translations( 'de_DE', array( $this->place() ) ) );

		$response = $this->call(
			'/translations',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'      => $this->place(),
						'text'     => 'Bild %2$d von %1$s',
						'original' => self::FORMAT,
					),
				),
			)
		);
		$this->assertSame( array(), $response->get_data()['refused'] );
		$this->assertSame( 'Bild %2$d von %1$s', swiftling_get_translations( 'de_DE', array( $this->place() ) )[ $this->place() ]['text'] );
	}

	/**
	 * Machine translation keeps the placeholders out of the service's hands, and a translation that
	 * comes back with broken ones is not filled in.
	 */
	public function test_machine_translation_keeps_placeholders() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->become( 'administrator' );
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $this->place(),
						'text' => self::FORMAT,
					),
				),
			)
		);
		$this->assertSame( array( '%1$s', '%2$d' ), FakeService::$sent[ $this->place() ]['keep'] );
		// The fake service writes everything in capitals: "%1$S" is no placeholder.
		$this->assertSame( array(), (array) $response->get_data()['translations'] );
		$this->assertSame( array( $this->place() ), $response->get_data()['failed'] );
	}

	/**
	 * A translation another plugin changes after the service is checked for its placeholders as
	 * changed: the fake service's capitals break them, the plugin's change mends them.
	 */
	public function test_changed_machine_translation_keeps_placeholders() {
		FakeService::connect();
		self::reset_static( Services::class, 'services' );
		$this->become( 'administrator' );
		add_filter(
			'swiftling_machine_translations',
			static function ( $translations ) {
				return array_map(
					static function () {
						return 'Bild %2$d von %1$s';
					},
					$translations
				);
			}
		);
		$response = $this->call(
			'/machine',
			array(
				'subject' => 'post:' . $this->post->ID,
				'lang'    => 'de',
				'items'   => array(
					array(
						'key'  => $this->place(),
						'text' => self::FORMAT,
					),
				),
			)
		);
		$this->assertSame( array( $this->place() => 'Bild %2$d von %1$s' ), (array) $response->get_data()['translations'] );
		$this->assertSame( array(), $response->get_data()['failed'] );
	}

	/**
	 * Google gets the placeholders as empty tags it does not translate, and they come back as they were.
	 */
	public function test_google_keeps_placeholders() {
		Services::update( 'google', array( 'key' => 'test-key' ) );
		$sent = null;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) use ( &$sent ) {
				$sent = json_decode( $args['body'], true );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array( 'translations' => array( array( 'translatedText' => 'Bild <span translate="no">%2$d</span> von <span translate="no">%1$s</span>' ) ) ) ) ),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			2
		);
		$result = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => self::FORMAT,
					'html' => false,
					'keep' => array( '%1$s', '%2$d' ),
				),
			),
			'en',
			'de'
		);
		// In spans Google leaves alone: it drops empty tags such as <wbr>, measured on 2026-10-10.
		$this->assertSame( array( '<span translate="no">%1$s</span> - Image <span translate="no">%2$d</span>' ), $sent['q'] );
		$this->assertSame( array( 'a' => 'Bild %2$d von %1$s' ), $result['translations'] );
	}
}
