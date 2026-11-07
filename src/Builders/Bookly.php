<?php
/**
 * Bookly: its booking form and emails in the language of the visitor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Bookly. Its booking form is loaded step by step through admin-ajax.php. The labels of the form are
 * values of its settings, its services, categories and staff rows of its own tables, and its emails
 * templates of another. The plugin hands every one of those texts, by a name of its own, to the filter
 * wpml_translate_single_string, whether a multilingual plugin is there or not; it has no other place to
 * translate them. What SwiftLing does:
 *
 * - It answers that filter for the plugin's texts, see translateString(), and nothing else of it: the
 *   texts are "bookly:strings" with paths such as "bookly/service_1" or "bookly/bookly_l10n_label_service",
 *   offered on the subject of the plugin's settings, see readMoreTexts().
 * - The form's requests are answered in the language of the page, see sendingActions(), also the one
 *   the visitor comes back with after paying, which names the page, see sentFrom(). The language of a
 *   booking is kept with it, in the plugin's own column of the locale, see keepLanguage().
 * - The plugin writes its emails without switching the language itself unless another multilingual
 *   plugin does it: an email to the customer is written in the language of the booking, one to the
 *   staff or the administrators in the original, as the plugin's code that sends it says.
 *
 * Checked against the code of Bookly 28.7 (the free plugin), in tests/BooklyTest.php, and by hand on a
 * site with the plugin.
 */
class Bookly extends SettingsTexts {
	/**
	 * The object key of the plugin's texts, and where they are in its map.
	 */
	const OWNER = 'bookly:strings';
	const BASE  = 'bookly';

	/**
	 * The plugin's actions of admin-ajax.php its booking form sends from the page.
	 */
	const FORM_ACTIONS = array( 'bookly_get_form_id', 'bookly_render_service', 'bookly_render_extras', 'bookly_render_time', 'bookly_render_next_time', 'bookly_render_repeat', 'bookly_render_cart', 'bookly_render_details', 'bookly_render_payment', 'bookly_render_complete', 'bookly_session_save', 'bookly_save_appointment', 'bookly_get_calendar_settings', 'bookly_back_from_payment_system' );

	/**
	 * A placeholder of the plugin's templates, as {client_name}.
	 */
	const PLACEHOLDER = '/\{[a-z0-9_#\/]+\}/i';

	/**
	 * The last booking of a customer before this request saved one, to give the new ones the language.
	 *
	 * @var int|null
	 */
	private $last = null;

	/**
	 * The order the visitor comes back with after paying, by its token.
	 *
	 * @var string
	 */
	private $order = '';

	/**
	 * The originals of the texts translated in the request, by translation, to put an email to the staff
	 * back in the original.
	 *
	 * @var array<string, string>
	 */
	private $originals = array();

	/**
	 * Whether the plugin writes an email to the staff now, which the next email sent gets back in the
	 * original.
	 *
	 * @var bool
	 */
	private $staff = false;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'bookly';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Bookly';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return class_exists( 'Bookly\\Lib\\Plugin' );
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
	 * Hooks where the plugin translates its texts and saves a booking.
	 */
	public function register(): void {
		add_filter( 'wpml_translate_single_string', array( $this, 'translateString' ), 10, 4 );
		foreach ( array( 'wp_ajax_bookly_save_appointment', 'wp_ajax_nopriv_bookly_save_appointment', 'wp_ajax_bookly_back_from_payment_system', 'wp_ajax_nopriv_bookly_back_from_payment_system' ) as $action ) {
			add_action( $action, array( $this, 'noteLast' ), 0, 0 );
		}
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
		add_filter( 'wp_mail', array( $this, 'originalForStaff' ) );
	}

	/**
	 * Returns the actions of admin-ajax.php the plugin's booking form sends from the page: answered in
	 * the language of the page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return self::FORM_ACTIONS;
	}

	/**
	 * Returns the page a booking was sent from when the visitor comes back to the plugin after paying,
	 * also locally: the plugin names the page in the address it sends the visitor to.
	 *
	 * @param string $referer The address the request names as its Referer.
	 */
	public function sentFrom( string $referer ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which page the plugin names; the plugin verifies the order.
		if ( isset( $_GET['action'], $_GET['bookly_referer'] ) && 'bookly_back_from_payment_system' === $_GET['action'] && is_string( $_GET['bookly_referer'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
			return esc_url_raw( wp_unslash( $_GET['bookly_referer'] ) );
		}
		return $referer;
	}

	/**
	 * Returns a text the plugin hands to the filter by its name, in the language it is written in: of
	 * the booking for an email to the customer, the original for one to the staff, otherwise that of the
	 * request. Texts of other plugins pass as they are.
	 *
	 * @param mixed $value    The text, as stored.
	 * @param mixed $context  The plugin's domain of the text.
	 * @param mixed $name     The plugin's name of the text.
	 * @param mixed $language The language the plugin asks for, or null for the current one.
	 * @return mixed
	 */
	public function translateString( $value, $context = '', $name = '', $language = null ) {
		if ( 'bookly' !== $context || ! is_string( $value ) || '' === trim( $value ) || ! is_string( $name ) || '' === $name ) {
			return $value;
		}
		$slug = $this->languageOf( $language );
		if ( '' === $slug ) {
			return $value;
		}
		$path       = self::BASE . '/' . swiftling_escape_segment( $name );
		$translated = self::pageSlug() === $slug ? swiftling_transform_text( self::OWNER, $path, 'bookly:string', $value ) : swiftling_in_language(
			$slug,
			static function () use ( $path, $value ) {
				return swiftling_transform_text( self::OWNER, $path, 'bookly:string', $value );
			}
		);
		if ( is_string( $translated ) && $translated !== $value ) {
			$this->originals[ $translated ] = $value;
		}
		return $translated;
	}

	/**
	 * Returns an email the plugin sends the staff with the texts the request translated back in the
	 * original: the plugin writes it from what it wrote for the customer, when the two share a time zone.
	 *
	 * @param mixed $mail The email: to, subject, message and the rest.
	 * @return mixed
	 */
	public function originalForStaff( $mail ) {
		if ( ! $this->staff ) {
			return $mail;
		}
		$this->staff = false;
		if ( is_array( $mail ) && $this->originals ) {
			foreach ( array( 'subject', 'message' ) as $field ) {
				if ( isset( $mail[ $field ] ) && is_string( $mail[ $field ] ) ) {
					$mail[ $field ] = strtr( $mail[ $field ], $this->originals );
				}
			}
		}
		return $mail;
	}

	/**
	 * Returns the language a text of the plugin is written in now.
	 *
	 * @param mixed $language The language the plugin asks for, as a locale or a code, or null.
	 * @return string Address slug, or an empty string for the original.
	 */
	private function languageOf( $language ): string {
		if ( is_string( $language ) && '' !== $language ) {
			return self::slugOf( $language );
		}
		$email = $this->email();
		if ( null !== $email ) {
			return $email;
		}
		return self::pageSlug();
	}

	/**
	 * Returns the language of an email the plugin writes now, from its code that sends it: of the booking
	 * for the customer, the original for the staff, the administrators or other recipients.
	 *
	 * @return string|null Address slug, an empty string for the original, or null when no email is written.
	 */
	private function email(): ?string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Which of the plugin's senders writes the email: it has no hook to say.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT, 30 ) as $frame ) {
			$class = isset( $frame['class'] ) ? (string) $frame['class'] : '';
			if ( 0 !== strpos( $class, 'Bookly\\Lib\\Notifications\\' ) ) {
				continue;
			}
			$function = (string) $frame['function'];
			if ( preg_match( '/Staff|Admins|Custom/', $function ) ) {
				$this->staff = true;
				return '';
			}
			if ( preg_match( '/Client|Customer/', $function ) ) {
				foreach ( $frame['args'] ?? array() as $arg ) {
					if ( is_object( $arg ) && method_exists( $arg, 'getCA' ) && is_object( $arg->getCA() ) && method_exists( $arg->getCA(), 'getLocale' ) && $arg->getCA()->getLocale() ) {
						return self::slugOf( (string) $arg->getCA()->getLocale() );
					}
				}
				return self::pageSlug();
			}
		}
		return null;
	}

	/**
	 * Returns the address slug of a language the plugin names, as a locale or a language code.
	 *
	 * @param string $language The plugin's name of the language.
	 */
	private static function slugOf( string $language ): string {
		foreach ( swiftling_languages()['targets'] as $slug => $target ) {
			$locale = (string) ( $target['wp_locale'] ?? $target['locale'] );
			if ( $language === $locale || $language === $target['locale'] || strtolower( $language ) === strtolower( (string) strtok( $locale, '_' ) ) || $language === (string) $slug ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * Returns the address slug of the language the request runs in, or an empty string for the original.
	 */
	private static function pageSlug(): string {
		$state = &swiftling_state();
		return 'translate' === $state['mode'] ? (string) $state['language'] : '';
	}

	/**
	 * Notes the last booking of a customer before the plugin saves one, so the new ones get the language
	 * of the page once the request ends.
	 */
	public function noteLast(): void {
		global $wpdb;
		if ( '' === self::pageSlug() ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once.
		$this->last = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i', $wpdb->prefix . 'bookly_customer_appointments' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which order the visitor comes back with; the plugin verifies it.
		$this->order = isset( $_GET['bookly_order'] ) && is_string( $_GET['bookly_order'] ) ? sanitize_text_field( wp_unslash( $_GET['bookly_order'] ) ) : '';
		add_action( 'shutdown', array( $this, 'keepLanguage' ), 5 );
	}

	/**
	 * Keeps the language of the page with the bookings the request saved, in the plugin's own column of
	 * their locale, where the plugin reads it for the emails it sends later.
	 */
	public function keepLanguage(): void {
		global $wpdb;
		$language = swiftling_target_language( self::pageSlug() );
		if ( null === $this->last || ! is_array( $language ) ) {
			return;
		}
		$locale = (string) ( $language['wp_locale'] ?? $language['locale'] );
		$table  = $wpdb->prefix . 'bookly_customer_appointments';
		if ( '' !== $this->order ) {
			// The bookings of the order the visitor came back with.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own column of a booking's locale.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i AS ca JOIN %i AS o ON o.id = ca.order_id SET ca.locale = %s WHERE o.token = %s AND ( ca.locale IS NULL OR ca.locale = %s )', $table, $wpdb->prefix . 'bookly_orders', $locale, $this->order, '' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own column of a booking's locale.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET locale = %s WHERE id > %d AND ( locale IS NULL OR locale = %s )', $table, $locale, $this->last, '' ) );
		}
		$this->last  = null;
		$this->order = '';
	}

	/**
	 * Returns no option of SwiftLing's own: the plugin hands its labels to the filter by name.
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
		return __( 'The labels of the Bookly form, its services, categories and staff, and the emails to customers, which go out in the language the customer booked in. Keep every placeholder, such as {client_name}.', 'swiftling' );
	}

	/**
	 * Reads the plugin's texts for the subject of the settings, by the names the plugin gives them.
	 */
	protected function readMoreTexts(): void {
		foreach ( $this->strings() as $name => $value ) {
			swiftling_transform_text( self::OWNER, self::BASE . '/' . swiftling_escape_segment( $name ), 'bookly:string', $value );
		}
	}

	/**
	 * Returns the plugin's texts a visitor or a customer reads, by the names it hands them to the filter
	 * with: the labels of its form, its services, categories and staff, and its emails to customers.
	 *
	 * @return array<string, string>
	 */
	protected function strings(): array {
		global $wpdb;
		$strings = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's labels, read for the editor.
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( 'bookly_l10n_' ) . '%' ), ARRAY_A ) as $row ) {
			$strings[ $row['option_name'] ] = (string) $row['option_value'];
		}
		$tables = array(
			'bookly_services'   => array(
				'service',
				array(
					'title' => '',
					'info'  => '_info',
				),
			),
			'bookly_categories' => array(
				'category',
				array(
					'name' => '',
					'info' => '_info',
				),
			),
			'bookly_staff'      => array( 'staff', array( 'info' => '_info' ) ),
		);
		foreach ( $tables as $table => $names ) {
			$name = $wpdb->prefix . $table;
			if ( ! $this->hasTable( $name ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table; the fields are constants of this class.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, ' . implode( ', ', array_keys( $names[1] ) ) . ' FROM %i ORDER BY id', $name ), ARRAY_A ) as $row ) {
				foreach ( $names[1] as $field => $suffix ) {
					if ( isset( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
						$strings[ $names[0] . '_' . $row['id'] . $suffix ] = (string) $row[ $field ];
					}
				}
			}
		}
		$table = $wpdb->prefix . 'bookly_notifications';
		if ( $this->hasTable( $table ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, gateway, type, subject, message FROM %i WHERE to_customer = 1 AND gateway = 'email' ORDER BY id", $table ), ARRAY_A ) as $row ) {
				$name = sprintf( '%s_%s_%d', $row['gateway'], $row['type'], $row['id'] );
				if ( '' !== trim( (string) $row['subject'] ) ) {
					$strings[ $name . '_subject' ] = (string) $row['subject'];
				}
				if ( '' !== trim( (string) $row['message'] ) ) {
					$strings[ $name ] = (string) $row['message'];
				}
			}
		}
		return $strings;
	}

	/**
	 * Declares the plugin's texts as text, and its labels as values of no option SwiftLing reads.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( self::OWNER === $owner ) {
			return 0 === strpos( $path, self::BASE . '/' ) ? true : null;
		}
		return null;
	}

	/**
	 * Returns what the editor calls a text of the plugin: its name, readable.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( self::OWNER !== $owner ) {
			return '';
		}
		$name = str_replace( array( '%2F', '%25' ), array( '/', '%' ), (string) substr( $path, strlen( self::BASE ) + 1 ) );
		if ( preg_match( '/^email_(.+)_(\d+)(_subject)?$/', $name, $found ) ) {
			/* translators: 1: type of an email of Bookly, e.g. new booking, 2: its number. */
			return sprintf( __( 'Email to the customer: %1$s %2$s', 'swiftling' ), str_replace( '_', ' ', $found[1] ), $found[2] ) . ' › ' . ( empty( $found[3] ) ? __( 'Message', 'swiftling' ) : __( 'Subject', 'swiftling' ) );
		}
		return ucfirst( str_replace( '_', ' ', (string) preg_replace( '/^bookly_l10n_/', '', $name ) ) );
	}

	/**
	 * Keeps the placeholders of the plugin's templates, as {client_name}, as they are when a text goes to
	 * machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepPlaceholders( $items ) {
		return Templates::keepPlaceholders( $items, self::PLACEHOLDER );
	}
}
