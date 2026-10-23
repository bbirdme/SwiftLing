<?php
/**
 * A machine translation service, used with the site owner's own key.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * A machine translation service, used with the site owner's own key. Each service has a class in
 * this folder and is listed in Services::all(); another plugin can add its own there.
 *
 * Texts go from the site straight to the service, only when a user starts a translation.
 */
abstract class Service {
	/**
	 * The languages the next translate() translates between, see setLanguages().
	 *
	 * @var array{source: array, target: array}
	 */
	private $languages = array(
		'source' => array(),
		'target' => array(),
	);

	/**
	 * Returns the ID of the service, e.g. "google". It names the service's settings and the
	 * constant that can hold its key.
	 */
	abstract public function id(): string;

	/**
	 * Returns the short name people know the service by, e.g. "Google", as in "Translate with Google".
	 */
	abstract public function name(): string;

	/**
	 * Returns the full name of the product, e.g. "Google Cloud Translation".
	 */
	abstract public function fullName(): string;

	/**
	 * Checks a key: that the service accepts it and can translate with it.
	 *
	 * @param string $key API key.
	 * @return array{languages: string[], models?: array<string, string>}|\WP_Error The codes of the
	 *         languages the service translates, and for a service with models to choose from, as a
	 *         language model, the models the key may use: ID => name. The Translation service screen
	 *         offers those, see model(). An error whose data has the reason "billing" is a key that
	 *         works on an account that cannot pay: no credits, billing off, a spend limit reached.
	 */
	abstract public function check( string $key );

	/**
	 * Returns the code the service uses for a language, or null when it does not translate it.
	 *
	 * @param array $language Language as swiftling_languages() gives it.
	 */
	abstract public function languageCode( array $language ): ?string;

	/**
	 * Translates texts.
	 *
	 * @param array<string, array{text: string, html: bool, keep?: string[]}> $items Key => the text, whether
	 *                                                              it is HTML with simple formatting rather than plain
	 *                                                              text, and parts the service must leave as they are,
	 *                                                              such as the placeholders of a text from code ("%1$s"),
	 *                                                              which may move but never change.
	 * @param string                                                          $source Code of the original language, from languageCode().
	 * @param string                                                          $target Code of the language to translate into.
	 * @return array{translations: array<string, string>, failed: string[], characters: int, tokens?: int, error: string}|\WP_Error
	 *         Translations keyed like the items, in the same form; the keys that could not be translated;
	 *         the characters sent, as the service counts them for billing; for a language model, the
	 *         tokens it read and wrote; and why the service stopped part way, when it did. An error
	 *         when nothing was translated.
	 */
	abstract public function translate( array $items, string $source, string $target );

	/**
	 * Tells the service the languages the next translate() translates between, as
	 * swiftling_languages() gives them, so it can heed more than its codes for them: the formal
	 * variant of a locale, such as de_DE_formal, or what is set for the language. SwiftLing tells
	 * it before every translation.
	 *
	 * @param array $source The original language.
	 * @param array $target The language to translate into.
	 */
	public function setLanguages( array $source, array $target ): void {
		$this->languages = array(
			'source' => $source,
			'target' => $target,
		);
	}

	/**
	 * Returns the original language of the translation, as setLanguages() was told it: empty when
	 * it was not.
	 *
	 * @return array
	 */
	public function sourceLanguage(): array {
		return $this->languages['source'];
	}

	/**
	 * Returns the language of the translation, as setLanguages() was told it: empty when it was not.
	 *
	 * @return array
	 */
	public function targetLanguage(): array {
		return $this->languages['target'];
	}

	/**
	 * Returns the characters the service translates free of charge each month, or 0 when it gives
	 * none or SwiftLing does not know. The Translation service screen measures this month's
	 * characters against it.
	 */
	public function freeCharacters(): int {
		return 0;
	}

	/**
	 * Returns the usage of the billing period as the service itself counts it, when it says so:
	 * the characters used and the plan's limit, 0 for a plan without one. The Translation service
	 * screen shows it instead of freeCharacters(), apart from what SwiftLing counts by month.
	 *
	 * @return array{used: int, limit: int}|null Null when the service does not say.
	 */
	public function quota(): ?array {
		return null;
	}

	/**
	 * Returns the models the key may use, as the last check of the key found them: ID => name. Empty
	 * for a service without models to choose from, such as Google.
	 *
	 * @return array<string, string>
	 */
	public function models(): array {
		$models = Services::setting( $this->id(), 'models', array() );
		return is_array( $models ) ? array_map( 'strval', $models ) : array();
	}

	/**
	 * Returns the model the service translates with until another is chosen, when the key may use it.
	 */
	public function defaultModel(): string {
		return '';
	}

	/**
	 * Returns the model the service translates with: the one chosen on the Translation service
	 * screen while the key may use it, otherwise defaultModel(), otherwise the first the key may use.
	 * Empty for a service without models.
	 */
	public function model(): string {
		$models = $this->models();
		if ( ! $models ) {
			return '';
		}
		foreach ( array( (string) Services::setting( $this->id(), 'model', '' ), $this->defaultModel() ) as $model ) {
			if ( '' !== $model && isset( $models[ $model ] ) ) {
				return $model;
			}
		}
		return (string) array_keys( $models )[0];
	}

	/**
	 * Returns the address of the service's prices, or an empty string.
	 */
	public function pricingUrl(): string {
		return '';
	}

	/**
	 * Returns the kind of the service, which the Translation service screen groups services by:
	 * machine, a translation engine billed per character, such as Google or DeepL, or ai, a language
	 * model billed per token.
	 */
	public function kind(): string {
		return 'machine';
	}

	/**
	 * Returns how to get a key, which the Translation service screen shows while the service is not
	 * connected: a title, a line under it, the steps, and a link after them. A step is its text,
	 * which may name the one thing to click in <strong>, the address it opens, or an empty string,
	 * and a note under it. A service without a guide returns empty steps.
	 *
	 * @return array{title: string, intro: string, steps: array<int, array{0: string, 1: string, 2?: string}>, link: array{0?: string, 1?: string}}
	 */
	public function guide(): array {
		return array(
			'title' => '',
			'intro' => '',
			'steps' => array(),
			'link'  => array(),
		);
	}

	/**
	 * Returns the name of the constant in wp-config.php that can hold the key, e.g. SWIFTLING_GOOGLE_API_KEY.
	 * A key there wins over the one saved on the Translation service screen and stays out of the database.
	 */
	public function constant(): string {
		return 'SWIFTLING_' . strtoupper( $this->id() ) . '_API_KEY';
	}

	/**
	 * Whether the key comes from the constant in wp-config.php.
	 */
	public function keyFromConstant(): bool {
		return defined( $this->constant() ) && is_string( constant( $this->constant() ) ) && '' !== trim( constant( $this->constant() ) );
	}

	/**
	 * Returns the key in use: from wp-config.php, or the one saved on the Translation service screen.
	 */
	public function key(): string {
		if ( $this->keyFromConstant() ) {
			return trim( (string) constant( $this->constant() ) );
		}
		return (string) Services::setting( $this->id(), 'key', '' );
	}

	/**
	 * Whether the service has a key that worked when it was last checked. A key changed in
	 * wp-config.php since then is not checked yet.
	 */
	public function isConnected(): bool {
		return '' !== $this->key() && 'working' === $this->state();
	}

	/**
	 * Returns the state of the key in use, as its last check found it: working; unpaid, a key that
	 * works on an account that cannot pay, so nothing is translated until it can; failed; or
	 * unchecked.
	 */
	public function state(): string {
		$key = $this->key();
		if ( '' === $key || Services::setting( $this->id(), 'fingerprint', '' ) !== $this->fingerprint( $key ) ) {
			return 'unchecked';
		}
		$state = Services::setting( $this->id(), 'state', '' );
		return in_array( $state, array( 'working', 'unpaid' ), true ) ? $state : 'failed';
	}

	/**
	 * Returns a short digest of a key, so the settings can tell which key was checked without
	 * keeping a second copy of it.
	 *
	 * @param string $key API key.
	 */
	public function fingerprint( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 16 );
	}

	/**
	 * Returns the codes of the languages the service translates, from the last check of the key.
	 *
	 * @return string[] Empty when the key was never checked.
	 */
	public function languages(): array {
		$languages = Services::setting( $this->id(), 'languages', array() );
		return is_array( $languages ) ? array_map( 'strval', $languages ) : array();
	}
}
