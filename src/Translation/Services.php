<?php
/**
 * The machine translation services SwiftLing knows, their settings and what was sent to them.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Translation;

defined( 'ABSPATH' ) || exit;

/**
 * The machine translation services SwiftLing knows, their settings and what was sent to them.
 *
 * Settings live in the option swiftling_service, not loaded on every request since only admin
 * screens read it: per service its key, the state and time of the last check, its error, and the
 * languages the service translates, and for a service with models the models its key may use and
 * the one chosen. The characters sent this month, and the tokens of language models, live in
 * swiftling_service_usage, and the service chosen to translate, when several are connected, in
 * swiftling_service_choice.
 */
final class Services {
	/**
	 * Option with the settings of every service.
	 */
	public const OPTION = 'swiftling_service';

	/**
	 * Option with the characters sent to each service this month, and the tokens of each language model.
	 */
	public const USAGE_OPTION = 'swiftling_service_usage';

	/**
	 * Option with the words that are never translated, see neverWords().
	 */
	public const NEVER_WORDS_OPTION = 'swiftling_never_words';

	/**
	 * Option with the ID of the service chosen to translate, when several are connected.
	 */
	public const CHOICE_OPTION = 'swiftling_service_choice';

	/**
	 * Every service, keyed by ID.
	 *
	 * @var array<string, Service>|null
	 */
	private static $services = null;

	/**
	 * Returns every service SwiftLing offers, keyed by ID.
	 *
	 * @return array<string, Service>
	 */
	public static function all(): array {
		if ( null === self::$services ) {
			$services = array( 'google' => new Google() );

			/**
			 * Filters the machine translation services, keyed by ID. Another plugin can add services of
			 * its own, each a subclass of SwiftLing\Translation\Service.
			 *
			 * @param array<string, Service> $services Services keyed by ID.
			 */
			$services = apply_filters( 'swiftling_translation_services', $services );

			self::$services = array();
			foreach ( (array) $services as $service ) {
				if ( $service instanceof Service ) {
					self::$services[ $service->id() ] = $service;
				}
			}
		}
		return self::$services;
	}

	/**
	 * Returns one service.
	 *
	 * @param string $id Service ID, e.g. "google".
	 */
	public static function get( string $id ): ?Service {
		$services = self::all();
		return isset( $services[ $id ] ) ? $services[ $id ] : null;
	}

	/**
	 * Returns the service a site translates with: the one chosen on the Translation service screen
	 * while it is connected, otherwise the first one that is connected.
	 */
	public static function current(): ?Service {
		$chosen = self::get( (string) get_option( self::CHOICE_OPTION, '' ) );
		if ( $chosen && $chosen->isConnected() ) {
			return $chosen;
		}
		foreach ( self::all() as $service ) {
			if ( $service->isConnected() ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * Returns the service that translates a page, template or list of terms into a language.
	 *
	 * @param string $subject  Key of what is translated, e.g. "post:12", see swiftling_subject().
	 * @param array  $language Language as swiftling_languages() gives it.
	 */
	public static function forTarget( string $subject, array $language ): ?Service {
		/**
		 * Filters the service that translates a page, template or list of terms into a language,
		 * so another plugin can use different services for different languages or pages.
		 *
		 * @param Service|null $service  The connected service, or null when none is.
		 * @param string       $subject  Key of what is translated, e.g. "post:12".
		 * @param array        $language Language as swiftling_languages() gives it.
		 */
		$service = apply_filters( 'swiftling_translation_service', self::current(), $subject, $language );
		return $service instanceof Service && $service->isConnected() ? $service : null;
	}

	/**
	 * Returns one setting of a service.
	 *
	 * @param string $id      Service ID.
	 * @param string $name    Setting: key, state, error, checked, languages, models or model.
	 * @param mixed  $fallback Value when the setting is not saved.
	 * @return mixed
	 */
	public static function setting( string $id, string $name, $fallback = null ) {
		$settings = get_option( self::OPTION, array() );
		return is_array( $settings ) && isset( $settings[ $id ][ $name ] ) ? $settings[ $id ][ $name ] : $fallback;
	}

	/**
	 * Saves settings of a service. A null value removes the setting.
	 *
	 * @param string               $id     Service ID.
	 * @param array<string, mixed> $values Setting => value.
	 */
	public static function update( string $id, array $values ): void {
		$settings = get_option( self::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$current  = isset( $settings[ $id ] ) && is_array( $settings[ $id ] ) ? $settings[ $id ] : array();
		foreach ( $values as $name => $value ) {
			if ( null === $value ) {
				unset( $current[ $name ] );
			} else {
				$current[ $name ] = $value;
			}
		}
		$settings[ $id ] = $current;
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Returns the characters sent to a service this month, as SwiftLing counts them. The month is
	 * the calendar month in UTC; the service may count by another time zone, and counts what other
	 * programs send with the same key too.
	 *
	 * @param string $id Service ID.
	 */
	public static function usage( string $id ): int {
		$usage = get_option( self::USAGE_OPTION, array() );
		if ( ! is_array( $usage ) || ! isset( $usage['month'] ) || gmdate( 'Y-m' ) !== $usage['month'] ) {
			return 0;
		}
		return isset( $usage['counts'][ $id ] ) ? (int) $usage['counts'][ $id ] : 0;
	}

	/**
	 * Returns the tokens a language model read and wrote this month, as SwiftLing counts them, in the
	 * same month as usage().
	 *
	 * @param string $id Service ID.
	 */
	public static function tokens( string $id ): int {
		$usage = get_option( self::USAGE_OPTION, array() );
		if ( ! is_array( $usage ) || ! isset( $usage['month'] ) || gmdate( 'Y-m' ) !== $usage['month'] ) {
			return 0;
		}
		return isset( $usage['tokens'][ $id ] ) ? (int) $usage['tokens'][ $id ] : 0;
	}

	/**
	 * Adds characters sent to a service, and the tokens a language model read and wrote, to this
	 * month's count. A new month starts from zero.
	 *
	 * @param string $id         Service ID.
	 * @param int    $characters Characters sent.
	 * @param int    $tokens     Tokens read and written, for a language model.
	 */
	public static function addUsage( string $id, int $characters, int $tokens = 0 ): void {
		if ( $characters <= 0 && $tokens <= 0 ) {
			return;
		}
		global $wpdb;
		// Read and written in the database itself, not through get_option(): several processes may count
		// at once (a language each, from the command line), and one that reads a stale copy would write
		// over the counts of the others. The write only happens if the row is still what was read;
		// otherwise it waits a moment and reads again.
		for ( $attempt = 0; $attempt < 200; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read straight from the table on purpose, see above.
			$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::USAGE_OPTION ) );
			$usage = null === $raw ? array() : maybe_unserialize( $raw );
			if ( ! is_array( $usage ) || ! isset( $usage['month'] ) || gmdate( 'Y-m' ) !== $usage['month'] ) {
				$usage = array(
					'month'  => gmdate( 'Y-m' ),
					'counts' => array(),
				);
			}
			foreach ( array(
				'counts' => $characters,
				'tokens' => $tokens,
			) as $count => $add ) {
				if ( $add > 0 ) {
					$usage[ $count ][ $id ] = ( isset( $usage[ $count ][ $id ] ) ? (int) $usage[ $count ][ $id ] : 0 ) + $add;
				}
			}
			$value = maybe_serialize( $usage );
			if ( null === $raw ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nothing saved yet; IGNORE leaves a row another process added first.
				$done = (bool) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::USAGE_OPTION, $value ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only if the row is still what was read.
				$done = (bool) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::USAGE_OPTION, $raw ) );
			}
			if ( $done ) {
				wp_cache_delete( self::USAGE_OPTION, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return;
			}
			// Another process was first: wait a moment, a different one each time, so they do not collide again.
			usleep( wp_rand( 500, 5000 ) );
		}
	}

	/**
	 * Returns the words that are never translated: names of the business, places and products, as
	 * the Translation service screen lists them. Machine translation keeps them as written.
	 *
	 * @return string[]
	 */
	public static function neverWords(): array {
		$saved = get_option( self::NEVER_WORDS_OPTION, array() );
		return is_array( $saved ) ? array_values( array_filter( array_map( 'strval', $saved ), 'strlen' ) ) : array();
	}

	/**
	 * Returns the words of the list a text holds: whole words, with their letters as written. In
	 * HTML a word inside a tag, such as an image's alt, is left alone: keeping it there would break
	 * the tag, see Google::keep().
	 *
	 * @param string $text Text.
	 * @param bool   $html Whether the text is HTML.
	 * @return string[] Words of the list, in the order of the list.
	 */
	public static function wordsIn( string $text, bool $html ): array {
		$found = array();
		foreach ( self::neverWords() as $word ) {
			$pattern = '~(?<![\p{L}\p{N}])' . preg_quote( $word, '~' ) . '(?![\p{L}\p{N}])~u';
			if ( ! preg_match( $pattern, $text ) ) {
				continue;
			}
			if ( $html && preg_match( '~<[^<>]*' . preg_quote( $word, '~' ) . '[^<>]*>~u', $text ) ) {
				continue;
			}
			$found[] = $word;
		}
		return $found;
	}
}
