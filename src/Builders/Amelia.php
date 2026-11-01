<?php
/**
 * Amelia: its booking forms and emails in the language of the visitor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Amelia. Its booking forms are a script that loads the services, employees and the rest from the
 * plugin's API, with its own words printed in the language of the page. Services, categories,
 * employees, locations, extras, packages, events, custom fields and the templates of its emails are
 * rows of the plugin's own tables. What SwiftLing does not find by itself:
 *
 * - Those texts are "amelia:<type>:<ID>" with paths such as "amelia/name", offered on the subject of
 *   the plugin's settings, see listTexts(), and translated where the plugin's API hands them to its
 *   forms, see translateEntities(). The API answers what a form reads in the language of the page,
 *   see sendingActions(); a booking it saves stays in the original, so its emails to the staff do.
 * - The plugin writes its emails itself, from its tables, in the language the customer booked in, as
 *   its forms tell it, also the reminders it sends later. It reads the translations of each row from a
 *   column of its own, by locale: SwiftLing writes its translations there once they are saved or a row
 *   changes, see sync(), and adds the language to those the plugin uses. The free plugin translates
 *   the names it puts into an email so, but never the template of an email: an email to the customer
 *   is written again from the translation of its template, the values of its placeholders read from
 *   the email the plugin wrote, see translateEmail(). Only the templates of emails to the customer are
 *   offered; their placeholders, such as %customer_full_name%, stay as they are in machine
 *   translation, see keepPlaceholders().
 *
 * Checked against the code of Amelia 2.4 (the free plugin), in tests/AmeliaTest.php, and by hand on a
 * site with the plugin.
 */
class Amelia extends SettingsTexts {
	/**
	 * Where the texts of a row are in the map of the row, and the prefix of its object key.
	 */
	const BASE = 'amelia';

	/**
	 * The plugin's rows with texts, by type: table without prefix, fields, and a condition on the rows.
	 * A custom field and its options keep their translations by locale, the others by field and locale.
	 */
	const TYPES = array(
		'service'      => array( 'amelia_services', array( 'name', 'description' ), '' ),
		'category'     => array( 'amelia_categories', array( 'name' ), '' ),
		'employee'     => array( 'amelia_users', array( 'description' ), "type = 'provider'" ),
		'location'     => array( 'amelia_locations', array( 'name', 'description' ), '' ),
		'extra'        => array( 'amelia_extras', array( 'name', 'description' ), '' ),
		'package'      => array( 'amelia_packages', array( 'name', 'description' ), '' ),
		'event'        => array( 'amelia_events', array( 'name', 'description' ), '' ),
		'ticket'       => array( 'amelia_events_to_tickets', array( 'name' ), '' ),
		'field'        => array( 'amelia_custom_fields', array( 'label' ), '' ),
		'option'       => array( 'amelia_custom_fields_options', array( 'label' ), '' ),
		'notification' => array( 'amelia_notifications', array( 'subject', 'content' ), "sendTo = 'customer' AND type = 'email'" ),
	);

	/**
	 * The keys under which the plugin's API hands out lists of rows, by type.
	 */
	const LISTS = array(
		'categories'    => 'category',
		'serviceList'   => 'service',
		'services'      => 'service',
		'extras'        => 'extra',
		'employees'     => 'employee',
		'providers'     => 'employee',
		'locations'     => 'location',
		'packages'      => 'package',
		'events'        => 'event',
		'customTickets' => 'ticket',
		'customFields'  => 'field',
		'options'       => 'option',
	);

	/**
	 * The calls of the plugin's API that a booking form reads.
	 */
	const READ_CALLS = '#^/(entities|slots|events|packages|services|categories|locations)(/|$)#';

	/**
	 * A placeholder of the plugin's templates, as %customer_full_name%.
	 */
	const PLACEHOLDER = '/%[a-z0-9_]+%/i';

	/**
	 * Locales whose translations are written into the plugin's rows at the end of the request.
	 *
	 * @var array<string, true>
	 */
	private $pending = array();

	/**
	 * The plugin's rows with texts, read once a request until one changes.
	 *
	 * @var array<int, array>|null
	 */
	private $rows = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'amelia';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Amelia';
	}

	/**
	 * Whether the plugin is on the site: it names its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'AMELIA_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin: none is.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The plugin builds no posts.
		return false;
	}

	/**
	 * Hooks where the plugin's API hands out its rows and changes them, and where SwiftLing saves
	 * translations.
	 */
	public function register(): void {
		add_filter( 'amelia_get_entities_filter', array( $this, 'translateEntities' ), 100 );
		foreach ( array( 'amelia_get_services_filter', 'amelia_get_service_filter', 'amelia_get_categories_filter', 'amelia_get_category_filter', 'amelia_get_providers_filter', 'amelia_get_provider_filter', 'amelia_get_events_filter', 'amelia_get_event_filter' ) as $filter ) {
			add_filter( $filter, array( $this, 'translateEntities' ), 100 );
		}
		add_action( 'swiftling_translations_saved', array( $this, 'translationsSaved' ), 10, 1 );
		foreach ( array( 'service', 'category', 'provider', 'event', 'notification' ) as $type ) {
			add_action( 'amelia_after_' . $type . '_updated', array( $this, 'rowChanged' ), 10, 0 );
			add_action( 'amelia_after_' . $type . '_added', array( $this, 'rowChanged' ), 10, 0 );
		}
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
		add_filter( 'amelia_manipulate_email_data', array( $this, 'translateEmail' ), 10, 1 );
	}

	/**
	 * Returns an email of the plugin to the customer of a booking written from the translation of its
	 * template, in the language the customer booked in: the template whose text and placeholders the
	 * email shows, its placeholders filled with what the plugin put in them. An email that matches no
	 * template, or one to another recipient, stays as the plugin wrote it.
	 *
	 * @param mixed $email The email: email, subject, body, and the booking under data.
	 * @return mixed
	 */
	public function translateEmail( $email ) {
		if ( ! is_array( $email ) || ! isset( $email['email'], $email['subject'], $email['body'] ) || ! is_string( $email['subject'] ) || ! is_string( $email['body'] ) ) {
			return $email;
		}
		$slug = $this->customerLanguage( $email );
		if ( '' === $slug ) {
			return $email;
		}
		foreach ( $this->rows() as $row ) {
			if ( 'notification' !== $row['type'] || 'subject' !== $row['field'] ) {
				continue;
			}
			$content = $this->notificationContent( $row['id'] );
			$subject = self::refill( $row['value'], $this->inLanguage( $slug, 'notification', $row['id'], 'subject', $row['value'] ), $email['subject'] );
			$body    = '' !== $content ? self::refill( $content, $this->inLanguage( $slug, 'notification', $row['id'], 'content', $content ), $email['body'] ) : null;
			if ( null !== $subject && null !== $body ) {
				$email['subject'] = $subject;
				$email['body']    = $body;
				return $email;
			}
		}
		return $email;
	}

	/**
	 * Returns the language a booking was made in, for an email to its customer: the address slug of the
	 * locale the plugin kept with the booking, or an empty string for the original or another recipient.
	 *
	 * @param array $email The email, with the booking under data.
	 */
	private function customerLanguage( array $email ): string {
		$reservation = isset( $email['data']['reservation'] ) && is_array( $email['data']['reservation'] ) ? $email['data']['reservation'] : array();
		$key         = $email['data']['bookingKey'] ?? null;
		$booking     = null !== $key && isset( $reservation['bookings'][ $key ] ) && is_array( $reservation['bookings'][ $key ] ) ? $reservation['bookings'][ $key ] : null;
		if ( null === $booking ) {
			return '';
		}
		$customer = isset( $booking['customer']['email'] ) && is_string( $booking['customer']['email'] ) ? $booking['customer']['email'] : '';
		if ( '' === $customer || strtolower( $customer ) !== strtolower( (string) $email['email'] ) ) {
			return '';
		}
		$info = is_string( $booking['info'] ?? null ) ? json_decode( $booking['info'], true ) : ( $booking['info'] ?? null );
		return is_array( $info ) && isset( $info['locale'] ) && is_string( $info['locale'] ) ? swiftling_locale_slug( $info['locale'] ) : '';
	}

	/**
	 * Returns the message of a template of an email, untranslated.
	 *
	 * @param int $id The template's ID.
	 */
	private function notificationContent( int $id ): string {
		foreach ( $this->rows() as $row ) {
			if ( 'notification' === $row['type'] && $id === $row['id'] && 'content' === $row['field'] ) {
				return $row['value'];
			}
		}
		return '';
	}

	/**
	 * Returns a text of a row of the plugin in a language, as a page in it shows the text.
	 *
	 * @param string $slug  Address slug of the language.
	 * @param string $type  The type of row.
	 * @param int    $id    The row's ID.
	 * @param string $field The field.
	 * @param string $value The text, as stored.
	 */
	private function inLanguage( string $slug, string $type, int $id, string $field, string $value ): string {
		$translated = swiftling_in_language(
			$slug,
			static function () use ( $type, $id, $field, $value ) {
				return swiftling_transform_text( self::owner( $type, $id ), self::BASE . '/' . $field, self::BASE . ':' . $type, $value );
			}
		);
		return is_string( $translated ) ? $translated : $value;
	}

	/**
	 * Writes a text from the translation of its template, given the text the template wrote: the values
	 * of the placeholders are read from the text by the original template, and put into the translation.
	 *
	 * @param string $original   The template, as stored.
	 * @param string $translated The template's translation.
	 * @param string $written    The text the plugin wrote from the template.
	 * @return string|null The text in the language; null when the text was not written from the template.
	 */
	public static function refill( string $original, string $translated, string $written ): ?string {
		$parts   = preg_split( '/(' . trim( self::PLACEHOLDER, '/i' ) . ')/i', $original, -1, PREG_SPLIT_DELIM_CAPTURE );
		$pattern = '';
		$seen    = array();
		foreach ( (array) $parts as $index => $part ) {
			if ( 0 === $index % 2 ) {
				$pattern .= preg_quote( $part, '#' );
				continue;
			}
			$name = 'p' . substr( md5( strtolower( $part ) ), 0, 16 );
			if ( isset( $seen[ $name ] ) ) {
				$pattern .= '(?P=' . $name . ')';
			} else {
				$pattern      .= '(?P<' . $name . '>.*?)';
				$seen[ $name ] = $part;
			}
		}
		if ( ! preg_match( '#^' . $pattern . '$#s', $written, $values ) ) {
			return null;
		}
		if ( $translated === $original ) {
			return $written;
		}
		return (string) preg_replace_callback(
			self::PLACEHOLDER,
			static function ( $found ) use ( $values ) {
				$name = 'p' . substr( md5( strtolower( $found[0] ) ), 0, 16 );
				return isset( $values[ $name ] ) ? $values[ $name ] : $found[0];
			},
			$translated
		);
	}

	/**
	 * Returns the action of admin-ajax.php the plugin's API answers at, for a request that reads what a
	 * booking form shows: answered in the language of the page. A request that saves a booking stays in
	 * the original, so the plugin's emails to the staff do; the plugin writes those to the customer in
	 * the customer's language itself.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which call of the plugin's API the request is; the plugin verifies it.
		$call = isset( $_GET['call'] ) && is_string( $_GET['call'] ) ? sanitize_text_field( wp_unslash( $_GET['call'] ) ) : '';
		$read = isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		return $read && preg_match( self::READ_CALLS, $call ) ? array( 'wpamelia_api' ) : array();
	}

	/**
	 * Returns the rows the plugin's API hands out with their texts in the mode of the moment.
	 *
	 * @param mixed $data The answer of the API, with lists of rows by key.
	 * @return mixed
	 */
	public function translateEntities( $data ) {
		if ( ! is_array( $data ) || ! self::inMode() ) {
			return $data;
		}
		return $this->walk( $data, null );
	}

	/**
	 * Translates the texts of the rows in a part of an answer of the plugin's API.
	 *
	 * @param array       $data The part.
	 * @param string|null $type The type of row the part is, or null for a part that is none.
	 * @return array
	 */
	private function walk( array $data, ?string $type ): array {
		if ( null !== $type && isset( $data['id'] ) && is_numeric( $data['id'] ) ) {
			foreach ( self::TYPES[ $type ][1] as $field ) {
				if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) && '' !== trim( $data[ $field ] ) ) {
					$data[ $field ] = swiftling_transform_text( self::owner( $type, (int) $data['id'] ), self::BASE . '/' . $field, self::BASE . ':' . $type, $data[ $field ] );
				}
			}
		}
		foreach ( $data as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( is_string( $key ) && isset( self::LISTS[ $key ] ) ) {
				foreach ( $value as $index => $item ) {
					if ( is_array( $item ) ) {
						$value[ $index ] = $this->walk( $item, self::LISTS[ $key ] );
					}
				}
				$data[ $key ] = $value;
			} elseif ( is_string( $key ) && in_array( $key, array( 'service', 'category', 'provider', 'location', 'event' ), true ) ) {
				$data[ $key ] = $this->walk( $value, 'provider' === $key ? 'employee' : $key );
			} elseif ( is_int( $key ) || in_array( $key, array( 'data', 'appointment' ), true ) ) {
				$data[ $key ] = $this->walk( $value, is_int( $key ) ? $type : null );
			}
		}
		return $data;
	}

	/**
	 * Returns the object key of a row of the plugin.
	 *
	 * @param string $type The type of row.
	 * @param int    $id   The row's ID.
	 */
	private static function owner( string $type, int $id ): string {
		return self::BASE . ':' . $type . ':' . $id;
	}

	/**
	 * Returns the plugin's rows with texts, untranslated.
	 *
	 * @return array<int, array{type: string, id: int, field: string, value: string}>
	 */
	protected function rows(): array {
		global $wpdb;
		if ( null !== $this->rows ) {
			return $this->rows;
		}
		$rows = array();
		foreach ( self::TYPES as $type => $table ) {
			$name = $wpdb->prefix . $table[0];
			if ( ! $this->hasTable( $name ) ) {
				continue;
			}
			$where = '' !== $table[2] ? ' WHERE ' . $table[2] : '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table; fields and condition are constants of this class.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, ' . implode( ', ', $table[1] ) . " FROM %i{$where} ORDER BY id", $name ), ARRAY_A ) as $row ) {
				foreach ( $table[1] as $field ) {
					if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== trim( $row[ $field ] ) ) {
						$rows[] = array(
							'type'  => $type,
							'id'    => (int) $row['id'],
							'field' => $field,
							'value' => $row[ $field ],
						);
					}
				}
			}
		}
		$this->rows = $rows;
		return $rows;
	}

	/**
	 * Returns no option: the plugin's texts are rows of its tables.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array();
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		/* translators: %s: a placeholder of Amelia's emails, %customer_full_name%. */
		return sprintf( __( 'The services, categories, employees, locations, extras, events and custom fields of Amelia, and the emails to customers, which Amelia sends in the language the customer booked in. Keep every placeholder, such as %s.', 'swiftling' ), '%customer_full_name%' );
	}

	/**
	 * Reads the texts of the plugin's rows for the subject of the settings.
	 */
	protected function readMoreTexts(): void {
		foreach ( $this->rows() as $row ) {
			swiftling_transform_text( self::owner( $row['type'], $row['id'] ), self::BASE . '/' . $row['field'], self::BASE . ':' . $row['type'], $row['value'] );
		}
	}

	/**
	 * Declares the texts of the plugin's rows as text.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, self::BASE . ':' ) ) {
			return null;
		}
		return 0 === strpos( $path, self::BASE . '/' ) ? true : null;
	}

	/**
	 * Returns what the editor calls a text of a row: its type, its number and its field.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( ! preg_match( '#^' . self::BASE . ':([a-z]+):(\d+)$#', $owner, $found ) || ! isset( self::TYPES[ $found[1] ] ) ) {
			return '';
		}
		$types  = array(
			'service'      => __( 'Service', 'swiftling' ),
			'category'     => __( 'Category', 'swiftling' ),
			'employee'     => __( 'Employee', 'swiftling' ),
			'location'     => __( 'Location', 'swiftling' ),
			'extra'        => __( 'Extra', 'swiftling' ),
			'package'      => __( 'Package', 'swiftling' ),
			'event'        => __( 'Event', 'swiftling' ),
			'ticket'       => __( 'Ticket', 'swiftling' ),
			'field'        => __( 'Custom field', 'swiftling' ),
			'option'       => __( 'Option of a custom field', 'swiftling' ),
			'notification' => __( 'Email to the customer', 'swiftling' ),
		);
		$fields = array(
			'name'        => __( 'Name', 'swiftling' ),
			'description' => __( 'Description', 'swiftling' ),
			'label'       => __( 'Label', 'swiftling' ),
			'subject'     => __( 'Subject', 'swiftling' ),
			'content'     => __( 'Message', 'swiftling' ),
		);
		$field  = (string) substr( $path, strlen( self::BASE ) + 1 );
		return $types[ $found[1] ] . ' ' . $found[2] . ' › ' . ( $fields[ $field ] ?? $field );
	}

	/**
	 * Notes that translations were saved in a language: the plugin's rows get them at the end of the
	 * request, once, however many were saved.
	 *
	 * @param mixed $lang Locale of the translations.
	 */
	public function translationsSaved( $lang ): void {
		if ( is_string( $lang ) && '' !== swiftling_locale_slug( $lang ) ) {
			$this->schedule( array( $lang ) );
		}
	}

	/**
	 * Notes that a row of the plugin changed in its admin: its texts may be others now, so the rows get
	 * the translations of every language at the end of the request.
	 */
	public function rowChanged(): void {
		$this->rows = null;
		$locales    = array();
		foreach ( swiftling_languages()['targets'] as $language ) {
			$locales[] = (string) $language['locale'];
		}
		$this->schedule( $locales );
	}

	/**
	 * Writes the translations of some languages into the plugin's rows at the end of the request.
	 *
	 * @param string[] $locales Locales.
	 */
	private function schedule( array $locales ): void {
		foreach ( $locales as $locale ) {
			$this->pending[ $locale ] = true;
		}
		if ( false === has_action( 'shutdown', array( $this, 'syncPending' ) ) ) {
			add_action( 'shutdown', array( $this, 'syncPending' ), 5 );
		}
	}

	/**
	 * Writes the translations of the languages noted in the request into the plugin's rows.
	 */
	public function syncPending(): void {
		$locales       = array_keys( $this->pending );
		$this->pending = array();
		foreach ( $locales as $locale ) {
			$this->sync( (string) $locale );
		}
	}

	/**
	 * Writes the translations of a language into the plugin's rows, where the plugin reads them for its
	 * emails: each text in the language as a page shows it, or none where it has no translation. The
	 * translations of other languages, which the plugin's own screens may have added, stay.
	 *
	 * @param string $locale Locale of a language of the site.
	 */
	public function sync( string $locale ): void {
		global $wpdb;
		$slug = swiftling_locale_slug( $locale );
		if ( '' === $slug ) {
			return;
		}
		$language = swiftling_target_language( $slug );
		$code     = is_array( $language ) && ! empty( $language['wp_locale'] ) ? (string) $language['wp_locale'] : $locale;
		$texts    = array();
		foreach ( $this->rows() as $row ) {
			$translated = swiftling_in_language(
				$slug,
				static function () use ( $row ) {
					return swiftling_transform_text( self::owner( $row['type'], $row['id'] ), self::BASE . '/' . $row['field'], self::BASE . ':' . $row['type'], $row['value'] );
				}
			);
			$texts[ $row['type'] ][ $row['id'] ][ $row['field'] ] = is_string( $translated ) && $translated !== $row['value'] ? $translated : null;
		}
		$used = false;
		foreach ( $texts as $type => $ids ) {
			$table = $wpdb->prefix . self::TYPES[ $type ][0];
			foreach ( $ids as $id => $fields ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read to merge one row's translations.
				$stored = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT translations FROM %i WHERE id = %d', $table, $id ) ), true );
				$stored = is_array( $stored ) ? $stored : array();
				$before = $stored;
				foreach ( $fields as $field => $text ) {
					// A custom field and its options keep their translations by locale alone.
					$in = in_array( $type, array( 'field', 'option' ), true ) ? $stored : ( isset( $stored[ $field ] ) && is_array( $stored[ $field ] ) ? $stored[ $field ] : array() );
					if ( null === $text ) {
						unset( $in[ $code ] );
					} else {
						$in[ $code ] = $text;
						$used        = true;
					}
					if ( in_array( $type, array( 'field', 'option' ), true ) ) {
						$stored = $in;
					} elseif ( $in ) {
						$stored[ $field ] = $in;
					} else {
						unset( $stored[ $field ] );
					}
				}
				if ( $stored !== $before ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The column the plugin reads its translations from.
					$wpdb->update( $table, array( 'translations' => $stored ? wp_json_encode( $stored ) : null ), array( 'id' => $id ) );
				}
			}
		}
		if ( $used ) {
			$this->useLanguage( $code );
		}
	}

	/**
	 * Adds a locale to the languages the plugin uses: it writes an email in a language only then.
	 *
	 * @param string $code The plugin's code of the language, a locale.
	 */
	private function useLanguage( string $code ): void {
		$settings = json_decode( (string) swiftling_raw_option( 'amelia_settings', '' ), true );
		if ( ! is_array( $settings ) || ! isset( $settings['general'] ) || ! is_array( $settings['general'] ) ) {
			return;
		}
		$used = isset( $settings['general']['usedLanguages'] ) && is_array( $settings['general']['usedLanguages'] ) ? $settings['general']['usedLanguages'] : array();
		if ( in_array( $code, $used, true ) ) {
			return;
		}
		$used[]                               = $code;
		$settings['general']['usedLanguages'] = $used;
		update_option( 'amelia_settings', wp_json_encode( $settings ) );
	}

	/**
	 * Keeps the placeholders of the plugin's templates as they are when a text goes to machine
	 * translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) && preg_match_all( self::PLACEHOLDER, $item['text'], $found ) ) {
				$keep                  = isset( $item['keep'] ) && is_array( $item['keep'] ) ? $item['keep'] : array();
				$items[ $key ]['keep'] = array_values( array_unique( array_merge( $keep, $found[0] ) ) );
			}
		}
		return $items;
	}
}
