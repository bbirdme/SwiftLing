<?php
/**
 * A translation service for tests.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Translation\Service;

/**
 * A translation service that translates by writing texts in capitals, without any request. It
 * remembers what it was asked to translate, so tests can tell what would have left the site.
 */
class FakeService extends Service {

	/**
	 * Texts of the last translation, keyed as they were sent.
	 *
	 * @var array
	 */
	public static $sent = array();

	/**
	 * Locales of the languages of the last translation, as the service was told them: source and
	 * target.
	 *
	 * @var string[]
	 */
	public static $languages = array();

	/**
	 * Locales the service does not translate.
	 *
	 * @var string[]
	 */
	public static $unsupported = array();

	/**
	 * What the next translation fails with, as a service refusing a key: nothing when null.
	 *
	 * @var \WP_Error|null
	 */
	public static $failure = null;

	/**
	 * Why the service stopped after translating, as a quota running out: empty when it did not.
	 *
	 * @var string
	 */
	public static $stopped = '';

	/**
	 * Models a key may use, as a language model lists them: none when empty.
	 *
	 * @var array<string, string>
	 */
	public static $models = array();

	/**
	 * Tokens each translated text takes, as a language model counts them: none when 0.
	 *
	 * @var int
	 */
	public static $tokens = 0;

	/**
	 * Returns the ID of the service.
	 */
	public function id(): string {
		return 'fake';
	}

	/**
	 * Returns the short name of the service.
	 */
	public function name(): string {
		return 'Fake';
	}

	/**
	 * Returns the full name of the service.
	 */
	public function fullName(): string {
		return 'Fake Translate';
	}

	/**
	 * Accepts every key but "bad", and "unpaid", a key that works on an account that cannot pay.
	 *
	 * @param string $key API key.
	 * @return array|\WP_Error
	 */
	public function check( string $key ) {
		if ( 'bad' === $key ) {
			return new \WP_Error( 'fake_key', 'This key does not work.' );
		}
		if ( 'unpaid' === $key ) {
			return new \WP_Error( 'fake_credit', 'You have no credits remaining. Add credits to continue using the API at https://fake.example/settings/organization/billing/overview.', array( 'reason' => 'billing' ) );
		}
		return array( 'languages' => array( 'hr', 'de', 'fr' ) ) + ( self::$models ? array( 'models' => self::$models ) : array() );
	}

	/**
	 * Returns the language part of a locale, unless the service does not translate the locale.
	 *
	 * @param array $language Language.
	 */
	public function languageCode( array $language ): ?string {
		return in_array( $language['locale'], self::$unsupported, true ) ? null : strtok( (string) $language['locale'], '_' );
	}

	/**
	 * Translates by writing the texts in capitals.
	 *
	 * @param array  $items  Key => text and whether it is HTML.
	 * @param string $source Code of the original language.
	 * @param string $target Code of the language to translate into.
	 * @return array
	 */
	public function translate( array $items, string $source, string $target ) {
		if ( null !== self::$failure ) {
			return self::$failure;
		}
		self::$sent      = $items;
		self::$languages = array( $this->sourceLanguage()['locale'] ?? '', $this->targetLanguage()['locale'] ?? '' );
		$translations    = array();
		$characters      = 0;
		foreach ( $items as $key => $item ) {
			$translations[ $key ] = mb_strtoupper( $item['text'] );
			$characters          += mb_strlen( $item['text'] );
		}
		return array(
			'translations' => $translations,
			'failed'       => array(),
			'characters'   => $characters,
			'error'        => self::$stopped,
		) + ( self::$tokens ? array( 'tokens' => self::$tokens * count( $items ) ) : array() );
	}

	/**
	 * Connects the service with a working key, as the Translation service screen would.
	 */
	public static function connect() {
		add_filter(
			'swiftling_translation_services',
			static function ( $services ) {
				return array( 'fake' => new self() ) + $services;
			}
		);
		$service = new self();
		\SwiftLing\Translation\Services::update(
			'fake',
			array(
				'key'         => 'fake-key',
				'state'       => 'working',
				'fingerprint' => $service->fingerprint( 'fake-key' ),
				'languages'   => array( 'hr', 'de', 'fr' ),
			)
		);
		self::$sent        = array();
		self::$languages   = array();
		self::$unsupported = array();
		self::$failure     = null;
		self::$stopped     = '';
		self::$models      = array();
		self::$tokens      = 0;
	}
}
