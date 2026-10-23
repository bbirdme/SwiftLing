<?php
/**
 * Google Cloud Translation, with the site owner's own API key.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Translation;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Google Cloud Translation, Basic edition (v2), with the site owner's own API key.
 *
 * The Advanced edition (v3) does not accept API keys, only service accounts, so the Basic edition
 * it is; both use the same translation model and price. Every text goes as HTML, so plain texts and
 * formatted ones travel in one request. Google charges every character sent, tags and spaces
 * included, so the attributes of tags, such as link addresses, stay on the site: each tag that has
 * attributes goes as a short numbered tag and is put back in the translation. Parts a text must keep,
 * such as the placeholders of a text from code, travel in spans Google leaves alone, so "%1$s" cannot
 * come back as "% 1 $ s". See Markup, which every service can use.
 */
final class Google extends Service {
	/**
	 * Address of the Basic edition of the API.
	 */
	private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

	/**
	 * Characters per request Google recommends at most.
	 */
	private const CHUNK_CHARACTERS = 5000;

	/**
	 * Texts per request, below the limit of the API.
	 */
	private const CHUNK_TEXTS = 100;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'google';
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'Google';
	}

	/**
	 * {@inheritDoc}
	 */
	public function fullName(): string {
		return 'Google Cloud Translation';
	}

	/**
	 * Google translates the first 500,000 characters a month free of charge, as a monthly credit of
	 * 10 dollars (cloud.google.com/translate/pricing, checked 2026-10-04).
	 */
	public function freeCharacters(): int {
		return 500000;
	}

	/**
	 * {@inheritDoc}
	 */
	public function pricingUrl(): string {
		return 'https://cloud.google.com/translate/pricing';
	}

	/**
	 * How to get a Google key, as the Translation service screen shows it.
	 *
	 * @return array{title: string, intro: string, steps: array<int, array{0: string, 1: string, 2?: string}>, link: array{0?: string, 1?: string}}
	 */
	public function guide(): array {
		$steps = array(
			array( __( 'Open the Google Cloud console and sign in.', 'swiftling' ), 'https://console.cloud.google.com/' ),
			array( __( 'Create a project for this site.', 'swiftling' ), 'https://console.cloud.google.com/projectcreate' ),
			array( __( 'Turn on billing for the project. Google asks for a payment card even for the characters it translates free of charge each month.', 'swiftling' ), 'https://console.cloud.google.com/billing' ),
			array(
				/* translators: %s: the label of Google's button, Enable, in bold. */
				sprintf( esc_html__( 'Enable the Cloud Translation API. On its page, only click %s.', 'swiftling' ), '<strong>' . esc_html__( 'Enable', 'swiftling' ) . '</strong>' ),
				'https://console.cloud.google.com/apis/library/translate.googleapis.com',
				__( 'There is nothing to choose there: the price list on that page is for information only. SwiftLing uses the standard text translation, never the LLM, Adaptive or Custom models.', 'swiftling' ),
			),
			array( __( 'Create an API key: Credentials, then Create credentials, then API key.', 'swiftling' ), 'https://console.cloud.google.com/apis/credentials' ),
			array(
				__( 'Restrict the key: under API restrictions, allow only the Cloud Translation API.', 'swiftling' ),
				'',
				__( 'Under Application restrictions choose None, or IP addresses when your server has a fixed address; never Websites, because SwiftLing asks Google from your server. Google’s note that a service account is required is about the Gemini and Agent Platform APIs, not this one.', 'swiftling' ),
			),
			array( __( 'To never pay more than you expect, set a daily limit of characters under Quotas of the Cloud Translation API.', 'swiftling' ), 'https://console.cloud.google.com/apis/api/translate.googleapis.com/quotas' ),
			array( __( 'Paste the key above and save it.', 'swiftling' ), '' ),
		);
		return array(
			'title' => __( 'How to get a Google key', 'swiftling' ),
			'intro' => __( 'Any Google account will do. It takes about ten minutes.', 'swiftling' ),
			'steps' => $steps,
			'link'  => array( __( 'Google’s prices and the characters it translates free of charge each month', 'swiftling' ), 'https://cloud.google.com/translate/pricing' ),
		);
	}

	/**
	 * Checks a key: asks for the languages Google translates, then translates one word, which also
	 * tells whether the project of the key has billing on. That costs two characters.
	 *
	 * @param string $key API key.
	 * @return array{languages: string[]}|WP_Error
	 */
	public function check( string $key ) {
		$list = $this->request( $key, 'GET', '/languages', null );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$languages = array();
		foreach ( isset( $list['data']['languages'] ) && is_array( $list['data']['languages'] ) ? $list['data']['languages'] : array() as $language ) {
			if ( isset( $language['language'] ) && is_string( $language['language'] ) ) {
				$languages[] = $language['language'];
			}
		}
		if ( ! $languages ) {
			return new WP_Error( 'swiftling_service_answer', __( 'Google answered without a list of languages. Try again later.', 'swiftling' ) );
		}

		$test = $this->request(
			$key,
			'POST',
			'',
			array(
				'q'      => array( 'Hi' ),
				'source' => 'en',
				'target' => 'de',
				'format' => 'text',
			)
		);
		if ( is_wp_error( $test ) ) {
			return $test;
		}
		Services::addUsage( $this->id(), 2 );

		return array( 'languages' => $languages );
	}

	/**
	 * Returns Google's code for a language: the locale's own variant when Google has it, such as
	 * pt-PT or fr-CA, otherwise the language, such as de for de_DE. A locale with a script, such as
	 * sr_Latn, only matches that script: Google writes Serbian in Cyrillic.
	 *
	 * @param array $language Language as swiftling_languages() gives it.
	 */
	public function languageCode( array $language ): ?string {
		$known = array();
		foreach ( $this->languages() as $code ) {
			$known[ strtolower( $code ) ] = $code;
		}
		if ( ! $known ) {
			return null;
		}
		foreach ( self::candidates( (string) $language['locale'] ) as $candidate ) {
			if ( isset( $known[ strtolower( $candidate ) ] ) ) {
				return $known[ strtolower( $candidate ) ];
			}
		}
		return null;
	}

	/**
	 * Returns the codes Google may use for a locale, the closest first.
	 *
	 * @param string $locale For example de_DE, pt_PT, sr_Latn_RS, zh_TW, nb_NO or cnr.
	 * @return string[]
	 */
	private static function candidates( string $locale ): array {
		$parts  = explode( '_', str_replace( '-', '_', $locale ) );
		$lang   = strtolower( array_shift( $parts ) );
		$script = '';
		$region = '';
		foreach ( $parts as $part ) {
			if ( 4 === strlen( $part ) && ctype_alpha( $part ) ) {
				$script = ucfirst( strtolower( $part ) );
			} elseif ( '' === $region && ( ( 2 === strlen( $part ) && ctype_alpha( $part ) ) || ( 3 === strlen( $part ) && ctype_digit( $part ) ) ) ) {
				$region = strtoupper( $part );
			}
			// Other parts are WordPress variants, such as formal in de_DE_formal.
		}

		if ( 'zh' === $lang ) {
			$traditional = 'Hant' === $script || ( '' === $script && in_array( $region, array( 'TW', 'HK', 'MO' ), true ) );
			return $traditional ? array( 'zh-TW' ) : array( 'zh-CN', 'zh' );
		}
		if ( '' !== $script ) {
			return array( $lang . '-' . $script );
		}

		// Languages Google knows under another code, or under two.
		$aliases = array(
			'nb'  => array( 'no' ),
			'nn'  => array( 'no' ),
			'he'  => array( 'he', 'iw' ),
			'jv'  => array( 'jv', 'jw' ),
			'fil' => array( 'fil', 'tl' ),
			'tl'  => array( 'tl', 'fil' ),
		);
		$codes   = '' !== $region ? array( $lang . '-' . $region ) : array();
		return array_values( array_unique( array_merge( $codes, isset( $aliases[ $lang ] ) ? $aliases[ $lang ] : array( $lang ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, array{text: string, html: bool}> $items  Key => text.
	 * @param string                                         $source Google's code of the original language.
	 * @param string                                         $target Google's code of the language to translate into.
	 */
	public function translate( array $items, string $source, string $target ) {
		$key = $this->key();
		if ( '' === $key ) {
			return new WP_Error( 'swiftling_service_key', __( 'Google is not connected: add a key on the Translation service screen.', 'swiftling' ) );
		}

		$segments = array();
		$tags     = array();
		$kept     = array();
		foreach ( $items as $item_key => $item ) {
			$keep = isset( $item['keep'] ) && is_array( $item['keep'] ) ? array_filter( $item['keep'], 'is_string' ) : array();
			if ( $item['html'] || $keep ) {
				$html = $item['html'] ? $item['text'] : Markup::plainToHtml( $item['text'] );
				// Tags first, so the spans of the parts to keep go to Google as they are.
				list( $html, $tags[ $item_key ] )                  = Markup::protect( $html );
				list( $segments[ $item_key ], $kept[ $item_key ] ) = Markup::keep( $html, $item['html'] ? $keep : array_map( array( Markup::class, 'plainToHtml' ), $keep ) );
			} else {
				$segments[ $item_key ] = Markup::plainToHtml( $item['text'] );
			}
		}

		$result = array(
			'translations' => array(),
			'failed'       => array(),
			'characters'   => 0,
			'error'        => '',
		);
		$chunks = self::chunks( $segments );
		foreach ( $chunks as $index => $chunk ) {
			$answer = $this->request(
				$key,
				'POST',
				'',
				array(
					'q'      => array_values( $chunk ),
					'source' => $source,
					'target' => $target,
					'format' => 'html',
					'model'  => 'nmt',
				)
			);
			if ( is_wp_error( $answer ) ) {
				if ( ! $result['translations'] ) {
					return $answer;
				}
				// What Google translated so far stays; the rest is reported as not translated.
				foreach ( array_slice( $chunks, $index ) as $rest ) {
					array_push( $result['failed'], ...array_map( 'strval', array_keys( $rest ) ) );
				}
				$result['error'] = $answer->get_error_message();
				break;
			}

			$result['characters'] += array_sum( array_map( 'mb_strlen', $chunk ) );
			$translated            = isset( $answer['data']['translations'] ) && is_array( $answer['data']['translations'] ) ? array_values( $answer['data']['translations'] ) : array();
			$position              = 0;
			foreach ( array_keys( $chunk ) as $item_key ) {
				$text = isset( $translated[ $position ]['translatedText'] ) && is_string( $translated[ $position ]['translatedText'] ) ? $translated[ $position ]['translatedText'] : null;
				++$position;
				if ( null !== $text && isset( $tags[ $item_key ] ) ) {
					$text = Markup::restore( $text, $tags[ $item_key ] );
					$text = null === $text ? null : Markup::unkeep( $text, $kept[ $item_key ] );
					if ( null !== $text && ! $items[ $item_key ]['html'] ) {
						$text = Markup::htmlToPlain( $text );
					}
				} elseif ( null !== $text ) {
					$text = Markup::htmlToPlain( $text );
				}
				if ( null === $text || '' === trim( wp_strip_all_tags( $text ) ) ) {
					$result['failed'][] = (string) $item_key;
				} else {
					$result['translations'][ (string) $item_key ] = $text;
				}
			}
		}

		return $result;
	}

	/**
	 * Splits texts into requests of at most CHUNK_TEXTS texts and, where possible, CHUNK_CHARACTERS
	 * characters. A longer text goes alone.
	 *
	 * @param array<string, string> $segments Key => text as sent.
	 * @return array<int, array<string, string>>
	 */
	private static function chunks( array $segments ): array {
		$chunks = array();
		$chunk  = array();
		$length = 0;
		foreach ( $segments as $key => $segment ) {
			$size = mb_strlen( $segment );
			if ( $chunk && ( count( $chunk ) >= self::CHUNK_TEXTS || $length + $size > self::CHUNK_CHARACTERS ) ) {
				$chunks[] = $chunk;
				$chunk    = array();
				$length   = 0;
			}
			$chunk[ $key ] = $segment;
			$length       += $size;
		}
		if ( $chunk ) {
			$chunks[] = $chunk;
		}
		return $chunks;
	}

	/**
	 * Sends a request to the API with the key in a header, where it does not end up in logs of
	 * addresses.
	 *
	 * @param string     $key    API key.
	 * @param string     $method GET or POST.
	 * @param string     $path   Path after the address of the API, e.g. "/languages".
	 * @param array|null $body   JSON body of a POST request.
	 * @return array|WP_Error The answer, decoded.
	 */
	private function request( string $key, string $method, string $path, ?array $body ) {
		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array( 'X-goog-api-key' => $key ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::ENDPOINT . $path, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: error message, e.g. a connection timeout. */
			return new WP_Error( 'swiftling_service_unreachable', sprintf( __( 'Google could not be reached: %s', 'swiftling' ), $response->get_error_message() ), array( 'status' => 502 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			return self::error( $code, is_array( $data ) ? $data : array() );
		}
		return $data;
	}

	/**
	 * Turns an error answer of the API into a message that says what to do. The reason comes from
	 * the error's details, e.g. API_KEY_INVALID; for a reason not known here, Google's own message.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Answer, decoded.
	 */
	private static function error( int $code, array $data ): WP_Error {
		$reason  = '';
		$message = isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ? $data['error']['message'] : '';
		foreach ( isset( $data['error']['details'] ) && is_array( $data['error']['details'] ) ? $data['error']['details'] : array() as $detail ) {
			if ( isset( $detail['reason'] ) && is_string( $detail['reason'] ) ) {
				$reason = $detail['reason'];
				break;
			}
		}

		$messages = array(
			'API_KEY_INVALID'                 => __( 'Google does not accept this key. Check that it was copied whole.', 'swiftling' ),
			'SERVICE_DISABLED'                => __( 'The Cloud Translation API is not enabled in the Google Cloud project of this key. Enable it, wait a few minutes and try again.', 'swiftling' ),
			'BILLING_DISABLED'                => __( 'The Google Cloud project of this key has no active billing account. Google asks for one even for the characters it translates free of charge.', 'swiftling' ),
			'API_KEY_SERVICE_BLOCKED'         => __( 'This key may not use the Cloud Translation API. Add it under the API restrictions of the key.', 'swiftling' ),
			'API_KEY_HTTP_REFERRER_BLOCKED'   => __( 'This key only accepts requests from websites in a browser, but SwiftLing asks Google from your server. Remove the website restriction of the key, or restrict it to the IP address of your server.', 'swiftling' ),
			'API_KEY_IP_ADDRESS_BLOCKED'      => __( 'This key does not accept requests from the IP address of your server. Check the IP address restriction of the key.', 'swiftling' ),
			'RATE_LIMIT_EXCEEDED'             => __( 'Google’s limit for this key is reached: a daily limit set for it, or too many requests at once. Try again later.', 'swiftling' ),
			'RESOURCE_EXHAUSTED'              => __( 'Google’s limit for this key is reached: a daily limit set for it, or too many requests at once. Try again later.', 'swiftling' ),
			'CONSUMER_INVALID'                => __( 'The Google Cloud project of this key no longer exists or cannot be used.', 'swiftling' ),
			'API_KEY_ANDROID_APP_BLOCKED'     => __( 'This key is restricted to apps. Restrict it to the IP address of your server instead.', 'swiftling' ),
			'API_KEY_IOS_APP_BLOCKED'         => __( 'This key is restricted to apps. Restrict it to the IP address of your server instead.', 'swiftling' ),
			'ACCESS_TOKEN_SCOPE_INSUFFICIENT' => __( 'This key may not use the Cloud Translation API. Add it under the API restrictions of the key.', 'swiftling' ),
		);
		if ( isset( $messages[ $reason ] ) ) {
			$text = $messages[ $reason ];
		} elseif ( 429 === $code ) {
			$text = $messages['RATE_LIMIT_EXCEEDED'];
		} elseif ( '' !== $message ) {
			/* translators: %s: Google's error message, in English. */
			$text = sprintf( __( 'Google answered: %s', 'swiftling' ), $message );
		} else {
			/* translators: %d: HTTP status code. */
			$text = sprintf( __( 'Google answered with error %d. Try again later.', 'swiftling' ), $code );
		}

		return new WP_Error(
			'swiftling_service_' . ( '' !== $reason ? strtolower( $reason ) : 'error' ),
			$text,
			// A project without billing has a key that works on an account that cannot pay.
			array( 'status' => 429 === $code ? 429 : 502 ) + ( 'BILLING_DISABLED' === $reason ? array( 'reason' => 'billing' ) : array() )
		);
	}
}
