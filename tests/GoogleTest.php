<?php
/**
 * Tests of Google Cloud Translation.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Translation\Google;
use SwiftLing\Translation\Services;
use WP_Error;

/**
 * Google Cloud Translation with the site owner's key, against answers the tests give.
 */
class GoogleTest extends TestCase {

	/**
	 * Requests sent to Google: address, arguments and decoded body.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Answers to give, in order; each an array( status, body ) or a WP_Error.
	 *
	 * @var array
	 */
	private $answers = array();

	/**
	 * Saves a key and answers requests to Google.
	 */
	public function set_up() {
		parent::set_up();
		Services::update( 'google', array( 'key' => 'test-key' ) );
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
	}

	/**
	 * Gives the next answer to a request.
	 *
	 * @param mixed  $pre  Answer so far.
	 * @param array  $args Arguments.
	 * @param string $url  Address.
	 * @return array|WP_Error
	 */
	public function answer( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
			'body' => isset( $args['body'] ) ? json_decode( $args['body'], true ) : null,
		);
		$answer           = array_shift( $this->answers );
		if ( null === $answer ) {
			$this->fail( 'No answer for ' . $url );
		}
		if ( $answer instanceof WP_Error ) {
			return $answer;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $answer[1] ),
			'response' => array(
				'code'    => $answer[0],
				'message' => '',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Returns Google's answer with translations.
	 *
	 * @param string[] $texts Translations.
	 * @return array
	 */
	private function translations( $texts ) {
		return array(
			200,
			array(
				'data' => array(
					'translations' => array_map(
						static function ( $text ) {
							return array( 'translatedText' => $text );
						},
						$texts
					),
				),
			),
		);
	}

	/**
	 * Plain text travels as HTML with line breaks and comes back as plain text.
	 */
	public function test_plain_text() {
		$this->answers[] = $this->translations( array( 'Hallo &lt;du&gt; &amp; ich<br>dort' ) );
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => "Bok <ti> & ja\ntamo",
					'html' => false,
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( array( 'Bok &lt;ti&gt; &amp; ja<br>tamo' ), $this->requests[0]['body']['q'] );
		$this->assertSame( 'html', $this->requests[0]['body']['format'] );
		$this->assertSame( array( 'a' => "Hallo <du> & ich\ndort" ), $result['translations'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertSame( mb_strlen( 'Bok &lt;ti&gt; &amp; ja<br>tamo' ), $result['characters'] );
	}

	/**
	 * Parts a text must keep, such as a placeholder or a word that is never translated, go inside a
	 * span Google leaves as it is, translate="no". Google drops empty tags such as <wbr>, measured
	 * on 2026-10-10, so a part kept that way would be lost.
	 */
	public function test_parts_to_keep_travel_in_a_span_google_leaves_alone() {
		$this->answers[] = $this->translations( array( 'Das Apartment <span translate="no">Lavanda</span>, <span translate="no">%1$s</span> Nächte' ) );
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => 'Apartman Lavanda, %1$s noći',
					'html' => false,
					'keep' => array( 'Lavanda', '%1$s' ),
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( array( 'Apartman <span translate="no">Lavanda</span>, <span translate="no">%1$s</span> noći' ), $this->requests[0]['body']['q'] );
		$this->assertSame( array( 'a' => 'Das Apartment Lavanda, %1$s Nächte' ), $result['translations'] );
		$this->assertSame( array(), $result['failed'] );
	}

	/**
	 * A translation that lost or changed a part to keep is not trusted.
	 */
	public function test_lost_part_to_keep_fails() {
		$this->answers[] = $this->translations( array( 'Das Apartment Lavendel', 'Das Apartment <span translate="no">Lavendel</span>' ) );
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => 'Apartman Lavanda',
					'html' => false,
					'keep' => array( 'Lavanda' ),
				),
				'b' => array(
					'text' => 'Apartman Lavanda',
					'html' => false,
					'keep' => array( 'Lavanda' ),
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( array(), $result['translations'] );
		$this->assertSame( array( 'a', 'b' ), $result['failed'] );
	}

	/**
	 * Attributes of tags stay on the site and come back into the translation.
	 */
	public function test_tag_attributes_stay_on_the_site() {
		$this->answers[] = $this->translations( array( '<a i="0">Klicken</a> <b>jetzt</b><img i="1" />' ) );
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => '<a href="https://example.org/a/very/long/address" class="button">Klikni</a> <b>sada</b><img src="/x.png" alt="" />',
					'html' => true,
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( array( '<a i="0">Klikni</a> <b>sada</b><img i="1" />' ), $this->requests[0]['body']['q'] );
		$this->assertSame( '<a href="https://example.org/a/very/long/address" class="button">Klicken</a> <b>jetzt</b><img src="/x.png" alt="" />', $result['translations']['a'] );
	}

	/**
	 * A translation whose numbered tags were lost, doubled or renamed is not trusted.
	 *
	 * @dataProvider data_broken
	 *
	 * @param string $answer What Google sent back.
	 */
	public function test_broken_tags_fail( $answer ) {
		$this->answers[] = $this->translations( array( $answer ) );
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => '<a href="/x">Klikni</a> <a href="/y">ovdje</a>',
					'html' => true,
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( array(), $result['translations'] );
		$this->assertSame( array( 'a' ), $result['failed'] );
	}

	/**
	 * Translations Google broke.
	 *
	 * @return array
	 */
	public function data_broken() {
		return array(
			'tag lost'    => array( '<a i="0">Klicken</a> hier' ),
			'tag doubled' => array( '<a i="0">Klicken</a> <a i="0">hier</a>' ),
			'tag renamed' => array( '<a i="0">Klicken</a> <b i="1">hier</b>' ),
			'empty'       => array( '<a i="0"></a> <a i="1"> </a>' ),
		);
	}

	/**
	 * Texts go in requests of at most 100 texts and, where possible, 5,000 characters.
	 */
	public function test_requests_are_split() {
		$items = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$items[ 'k' . $i ] = array(
				'text' => 'Tekst ' . $i,
				'html' => false,
			);
		}
		$this->answers[] = $this->translations( array_fill( 0, 100, 'Text' ) );
		$this->answers[] = $this->translations( array( 'Text' ) );
		$result          = ( new Google() )->translate( $items, 'hr', 'de' );
		$this->assertCount( 2, $this->requests );
		$this->assertCount( 101, $result['translations'] );

		$this->requests  = array();
		$this->answers[] = $this->translations( array( 'A' ) );
		$this->answers[] = $this->translations( array( 'B' ) );
		( new Google() )->translate(
			array(
				'a' => array(
					'text' => str_repeat( 'a', 3000 ),
					'html' => false,
				),
				'b' => array(
					'text' => str_repeat( 'b', 3000 ),
					'html' => false,
				),
			),
			'hr',
			'de'
		);
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * When Google stops part way, what it translated stays and the rest is reported.
	 */
	public function test_error_part_way() {
		$items = array();
		for ( $i = 0; $i < 150; $i++ ) {
			$items[ 'k' . $i ] = array(
				'text' => 'Tekst',
				'html' => false,
			);
		}
		$this->answers[] = $this->translations( array_fill( 0, 100, 'Text' ) );
		$this->answers[] = array( 429, array() );
		$result          = ( new Google() )->translate( $items, 'hr', 'de' );
		$this->assertCount( 100, $result['translations'] );
		$this->assertCount( 50, $result['failed'] );
		$this->assertStringContainsString( 'limit', $result['error'] );
	}

	/**
	 * When nothing was translated, the error itself comes back.
	 */
	public function test_error_at_once() {
		$this->answers[] = new WP_Error( 'http_request_failed', 'Connection timed out' );
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => 'Tekst',
					'html' => false,
				),
			),
			'hr',
			'de'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'swiftling_service_unreachable', $result->get_error_code() );
		$this->assertStringContainsString( 'Connection timed out', $result->get_error_message() );
	}

	/**
	 * Google's reasons become messages that say what to do.
	 *
	 * @dataProvider data_errors
	 *
	 * @param int    $status   HTTP status.
	 * @param array  $body     Google's answer.
	 * @param string $code     Error code.
	 * @param string $message  Words in the message.
	 * @param int    $returned Status the route answers with.
	 */
	public function test_errors( $status, $body, $code, $message, $returned ) {
		$this->answers[] = array( $status, $body );
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => 'Tekst',
					'html' => false,
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertStringContainsString( $message, $result->get_error_message() );
		$this->assertSame( $returned, $result->get_error_data()['status'] );
	}

	/**
	 * Answers of Google and the errors they become.
	 *
	 * @return array
	 */
	public function data_errors() {
		$reason = static function ( $reason, $message = 'x' ) {
			return array(
				'error' => array(
					'message' => $message,
					'details' => array( array( 'reason' => $reason ) ),
				),
			);
		};
		return array(
			'invalid key'    => array( 400, $reason( 'API_KEY_INVALID' ), 'swiftling_service_api_key_invalid', 'copied whole', 502 ),
			'no billing'     => array( 403, $reason( 'BILLING_DISABLED' ), 'swiftling_service_billing_disabled', 'billing', 502 ),
			'website key'    => array( 403, $reason( 'API_KEY_HTTP_REFERRER_BLOCKED' ), 'swiftling_service_api_key_http_referrer_blocked', 'from your server', 502 ),
			'too many'       => array( 429, array(), 'swiftling_service_error', 'Try again later', 429 ),
			'unknown reason' => array( 500, $reason( 'SOMETHING_NEW', 'Backend error' ), 'swiftling_service_something_new', 'Google answered: Backend error', 502 ),
			'nothing at all' => array( 503, array(), 'swiftling_service_error', 'error 503', 502 ),
		);
	}

	/**
	 * A project without billing is a key that works on an account that cannot pay.
	 */
	public function test_no_billing_is_a_billing_reason() {
		$this->answers[] = array(
			403,
			array(
				'error' => array(
					'message' => 'Billing disabled',
					'details' => array( array( 'reason' => 'BILLING_DISABLED' ) ),
				),
			),
		);
		$result          = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => 'Tekst',
					'html' => false,
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( 'billing', $result->get_error_data()['reason'] );
	}

	/**
	 * The key travels in a header, never in the address, where logs would keep it.
	 */
	public function test_key_is_sent_in_a_header() {
		$this->answers[] = $this->translations( array( 'Text' ) );
		( new Google() )->translate(
			array(
				'a' => array(
					'text' => 'Tekst',
					'html' => false,
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( 'https://translation.googleapis.com/language/translate/v2', $this->requests[0]['url'] );
		$this->assertSame( 'test-key', $this->requests[0]['args']['headers']['X-goog-api-key'] );
	}

	/**
	 * Without a key nothing is sent.
	 */
	public function test_without_a_key() {
		Services::update( 'google', array( 'key' => null ) );
		$result = ( new Google() )->translate(
			array(
				'a' => array(
					'text' => 'Tekst',
					'html' => false,
				),
			),
			'hr',
			'de'
		);
		$this->assertSame( 'swiftling_service_key', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Checking a key asks for the languages and translates one word, which is counted.
	 */
	public function test_check() {
		$this->answers[] = array(
			200,
			array(
				'data' => array(
					'languages' => array(
						array( 'language' => 'de' ),
						array( 'language' => 'hr' ),
					),
				),
			),
		);
		$this->answers[] = $this->translations( array( 'Hallo' ) );
		$this->assertSame( array( 'languages' => array( 'de', 'hr' ) ), ( new Google() )->check( 'test-key' ) );
		$this->assertSame( 2, Services::usage( 'google' ) );

		$this->answers[] = array( 200, array( 'data' => array() ) );
		$this->assertSame( 'swiftling_service_answer', ( new Google() )->check( 'test-key' )->get_error_code() );
	}

	/**
	 * Google's code for a language: its own variant when Google has it, then the language, then an alias.
	 *
	 * @dataProvider data_codes
	 *
	 * @param string      $locale   Locale.
	 * @param string|null $expected Google's code.
	 */
	public function test_language_code( $locale, $expected ) {
		Services::update( 'google', array( 'languages' => array( 'de', 'hr', 'pt', 'pt-PT', 'zh-CN', 'zh-TW', 'no', 'iw', 'sr', 'fil' ) ) );
		$this->assertSame( $expected, ( new Google() )->languageCode( array( 'locale' => $locale ) ) );
	}

	/**
	 * Locales and Google's codes for them.
	 *
	 * @return array
	 */
	public function data_codes() {
		return array(
			'language'               => array( 'de_DE', 'de' ),
			'formal variant'         => array( 'de_DE_formal', 'de' ),
			'regional variant'       => array( 'pt_PT', 'pt-PT' ),
			'variant google lacks'   => array( 'pt_BR', 'pt' ),
			'traditional chinese'    => array( 'zh_TW', 'zh-TW' ),
			'hong kong chinese'      => array( 'zh_HK', 'zh-TW' ),
			'simplified chinese'     => array( 'zh_CN', 'zh-CN' ),
			'norwegian bokmål'       => array( 'nb_NO', 'no' ),
			'hebrew by its old code' => array( 'he_IL', 'iw' ),
			'serbian in latin'       => array( 'sr_Latn_RS', null ),
			'serbian'                => array( 'sr_RS', 'sr' ),
			'unknown'                => array( 'cnr', null ),
		);
	}

	/**
	 * Without a checked key, Google's languages are not known.
	 */
	public function test_language_code_needs_a_checked_key() {
		$this->assertNull( ( new Google() )->languageCode( array( 'locale' => 'de_DE' ) ) );
	}
}
