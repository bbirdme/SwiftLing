<?php
/**
 * Booking Calendar: its booking form, messages and emails in the language of the visitor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Booking Calendar (WP Booking Calendar). It switches its own words to the locale the page names, and
 * keeps its booking forms, messages and emails in its settings and a table of its own. What SwiftLing
 * does not find by itself:
 *
 * - A booking form is HTML with fields of the plugin's own, as [text* firstname placeholder:"..."],
 *   loaded with the summary of a booking the plugin shows and emails, see translateForm(). Its texts
 *   are "wpbc:form:<slug>": each text between the form's tags, by its words, so it keeps its
 *   translation wherever it moves in the form, and the texts a field names, its placeholder, the labels
 *   of its options and the button's text, by the field's name.
 * - The message after a booking and the templates of the emails to the visitor are values of the
 *   plugin's settings, declared as text, see textOptions(). The template of the email to the site's
 *   owner is not offered.
 * - The calendar, the price and the booking are sent through admin-ajax.php, answered in the language
 *   of the page, see sendingActions(). The language of a booking is kept with it where the plugin keeps
 *   one, see keepLanguage(), and an email the plugin sends the visitor later, as when the booking is
 *   approved, is written from the translation of its template in that language, see translateEmail().
 *
 * Checked against the code of Booking Calendar 11.9 (the free plugin), in tests/BookingCalendarTest.php,
 * and by hand on a site with the plugin.
 */
class BookingCalendar extends SettingsTexts {
	/**
	 * Where the texts of a form are in the map of the form, and the prefix of its object key.
	 */
	const BASE = 'wpbc';

	/**
	 * The plugin's settings of emails to the visitor, and their fields that hold text.
	 */
	const EMAILS      = array( 'booking_email_new_visitor', 'booking_email_approved', 'booking_email_deny', 'booking_email_deleted', 'booking_email_trash' );
	const EMAIL_TEXTS = array( 'subject', 'content', 'header_content', 'footer_content' );

	/**
	 * The plugin's IDs of the emails to the visitor, by the option of their settings.
	 */
	const EMAIL_IDS = array(
		'new_visitor' => 'booking_email_new_visitor',
		'approved'    => 'booking_email_approved',
		'deny'        => 'booking_email_deny',
		'deleted'     => 'booking_email_deleted',
		'trash'       => 'booking_email_trash',
	);

	/**
	 * A field of the plugin's forms, as [text* firstname placeholder:"Example"] or [calendar].
	 */
	const FIELD = '/\[(?!\/)(?!lang=)([a-z_]+)(\*?)((?:\s(?:[^\]"]|"[^"]*")*)?)\]/i';

	/**
	 * The booking the plugin writes an email for now, while it sends the emails of a booking.
	 *
	 * @var int
	 */
	private $booking = 0;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'booking-calendar';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Booking Calendar';
	}

	/**
	 * Whether the plugin is on the site: it names its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'WP_BK_VERSION_NUM' ) || function_exists( 'wpbc_lang' );
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
	 * Hooks where the plugin loads a form and writes the emails of a booking.
	 */
	public function register(): void {
		// Before the preview of the plugin's form builder (100), which shows the form being edited.
		add_filter( 'wpbc_bfb_form_loader_from_builder', array( $this, 'translatePair' ), 50, 1 );
		add_action( 'wpbc_track_new_booking', array( $this, 'keepLanguage' ), 10, 1 );
		add_filter( 'wpbc_replace_params_for_booking', array( $this, 'noteBooking' ), 10, 4 );
		add_filter( 'wpbc_email_api_get_subject_before', array( $this, 'translateSubject' ), 10, 2 );
		add_filter( 'wpbc_email_api_get_content_before', array( $this, 'translateEmail' ), 10, 2 );
		add_filter( 'wpbc_email_api_get_content_after', array( $this, 'originalSummary' ), 10, 3 );
	}

	/**
	 * Returns the actions of admin-ajax.php the plugin's calendar and form send from the page: answered
	 * in the language of the page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'WPBC_AJX_CALENDAR_LOAD', 'CALCULATE_THE_COST', 'WPBC_AJX_BOOKING__CREATE', 'DELETE_BY_VISITOR', 'BOOKING_SEARCH', 'WPBC_FLEXTIMELINE_NAV', 'WPBC_AJX_BOOKING_APPOINTMENT_RESOLVE', 'WPBC_AJX_BOOKING_APPOINTMENT_VALIDATE_TIME', 'WPBC_AJX_BOOKING_RESOURCE_SELECTOR_RESOLVE' );
	}

	/**
	 * Returns a form of the plugin as it loads it, the form and the summary of a booking, with their
	 * texts in the mode of the moment.
	 *
	 * @param mixed $pair The form and the summary, with the form's slug.
	 * @return mixed
	 */
	public function translatePair( $pair ) {
		if ( ! is_array( $pair ) || ! self::inMode() ) {
			return $pair;
		}
		$owner = self::BASE . ':form:' . sanitize_key( (string) ( $pair['form_slug'] ?? 'standard' ) );
		foreach ( array( 'form', 'content' ) as $part ) {
			if ( isset( $pair[ $part ] ) && is_string( $pair[ $part ] ) && '' !== trim( $pair[ $part ] ) ) {
				$pair[ $part ] = $this->translateForm( $owner, $part, $pair[ $part ] );
			}
		}
		return $pair;
	}

	/**
	 * Returns a form or a summary of the plugin with its texts in the mode of the moment: each text
	 * between its tags, by its words, and the texts each field names. The fields themselves and the HTML
	 * stay as they are.
	 *
	 * @param string $owner Object key of the form.
	 * @param string $part  "form" or "content".
	 * @param string $html  The form, as stored.
	 */
	private function translateForm( string $owner, string $part, string $html ): string {
		$fields = array();
		$masked = (string) preg_replace_callback(
			self::FIELD,
			function ( $found ) use ( &$fields, $owner ) {
				$fields[] = $this->translateField( $owner, $found );
				return '<wpbc-field-' . ( count( $fields ) - 1 ) . '>';
			},
			$html
		);
		$masked = HtmlTexts::translate(
			$masked,
			static function ( $text, $key ) use ( $owner, $part ) {
				return swiftling_transform_text( $owner, self::BASE . '/' . $part . '/' . $key, self::BASE . ':form', $text );
			}
		);
		return (string) preg_replace_callback(
			'#<wpbc-field-(\d+)>#',
			static function ( $found ) use ( $fields ) {
				return $fields[ (int) $found[1] ] ?? '';
			},
			$masked
		);
	}

	/**
	 * Returns a field of a form with the texts it names in the mode of the moment: its placeholder, the
	 * labels of its options ("Label@@value"), and the text of a button.
	 *
	 * @param string $owner Object key of the form.
	 * @param array  $found The field: whole, type, star, and what follows the type.
	 */
	private function translateField( string $owner, array $found ): string {
		$type  = strtolower( $found[1] );
		$rest  = $found[3];
		$name  = preg_match( '/^\s+([a-z0-9_-]+)/i', $rest, $named ) ? $named[1] : $type;
		$base  = self::BASE . '/field/' . swiftling_escape_segment( $name );
		$fixed = (string) preg_replace_callback(
			'/placeholder:"([^"]*)"/',
			static function ( $placeholder ) use ( $owner, $base ) {
				return 'placeholder:"' . str_replace( '"', '', (string) swiftling_transform_text( $owner, $base . '/placeholder', self::BASE . ':field', $placeholder[1] ) ) . '"';
			},
			$rest
		);
		$fixed = (string) preg_replace_callback(
			'/(?<![:\w])"([^"]*)"/',
			static function ( $quoted ) use ( $owner, $base, $type ) {
				$value = $quoted[1];
				if ( 'submit' === $type ) {
					return '"' . str_replace( '"', '', (string) swiftling_transform_text( $owner, $base . '/text', self::BASE . ':field', $value ) ) . '"';
				}
				$at = strpos( $value, '@@' );
				if ( false === $at || 0 === $at ) {
					return $quoted[0];
				}
				$label = substr( $value, 0, $at );
				$text  = (string) swiftling_transform_text( $owner, $base . '/option/' . swiftling_escape_segment( substr( $value, $at + 2 ) ), self::BASE . ':field', $label );
				return '"' . str_replace( array( '"', '@@' ), '', $text ) . substr( $value, $at ) . '"';
			},
			$fixed
		);
		return '[' . $found[1] . $found[2] . $fixed . ']';
	}

	/**
	 * Returns the plugin's settings that hold text: the message after a booking, and the emails to the
	 * visitor.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		$options = array(
			'booking_title_after_reservation' => array( '' ),
			'booking_confirmation_header'     => array( '' ),
			'booking_confirmation__booking_details__content' => array( '' ),
		);
		foreach ( self::EMAILS as $email ) {
			$options[ $email ] = self::EMAIL_TEXTS;
		}
		return $options;
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The booking forms of Booking Calendar, the message after a booking and the emails to the visitor, which go out in the language the visitor booked in. Keep every placeholder in square brackets, such as [dates].', 'swiftling' );
	}

	/**
	 * Reads the texts of the plugin's published forms for the subject of the settings, as the plugin
	 * loads a form.
	 */
	protected function readMoreTexts(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'booking_form_structures';
		if ( ! $this->hasTable( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read for the editor.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT form_slug, advanced_form, content_form FROM %i WHERE status = 'published' ORDER BY is_default DESC, booking_form_id", $table ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$this->translatePair(
				array(
					'form'      => (string) $row['advanced_form'],
					'content'   => (string) $row['content_form'],
					'form_slug' => (string) $row['form_slug'],
				)
			);
		}
	}

	/**
	 * Declares the texts of a form as text, and the settings as SettingsTexts does.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, self::BASE . ':form:' ) ) {
			return 0 === strpos( $path, self::BASE . '/' ) ? true : null;
		}
		return parent::fieldHoldsText( $owner, $path );
	}

	/**
	 * Returns what the editor calls a text of a form, or of the settings.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, self::BASE . ':form:' ) ) {
			return parent::fieldLabel( $owner, $path );
		}
		/* translators: %s: slug of a booking form of Booking Calendar, e.g. standard. */
		$form = sprintf( __( 'Form %s', 'swiftling' ), substr( $owner, strlen( self::BASE . ':form:' ) ) );
		if ( preg_match( '#^' . self::BASE . '/field/([^/]+)/(placeholder|text|option)#', $path, $found ) ) {
			$parts = array(
				'placeholder' => __( 'Placeholder', 'swiftling' ),
				'text'        => __( 'Button', 'swiftling' ),
				'option'      => __( 'Option', 'swiftling' ),
			);
			return $form . ' › ' . str_replace( array( '%2F', '%25' ), array( '/', '%' ), $found[1] ) . ' › ' . $parts[ $found[2] ];
		}
		return $form . ' › ' . ( 0 === strpos( $path, self::BASE . '/content/' ) ? __( 'Summary of a booking', 'swiftling' ) : __( 'Text', 'swiftling' ) );
	}

	/**
	 * Keeps the language a visitor booked in with the booking, where the plugin keeps the locale of a
	 * booking: it names it on the booking in its admin, and SwiftLing writes the emails sent later in it.
	 *
	 * @param mixed $booking What the plugin tracks of the new booking, with its ID.
	 */
	public function keepLanguage( $booking ): void {
		$id = is_array( $booking ) && isset( $booking['booking_id'] ) ? (int) $booking['booking_id'] : 0;
		if ( ! $id || 'translate' !== swiftling_mode() || ! function_exists( 'wpbc_save_booking_meta_option' ) ) {
			return;
		}
		$language = swiftling_target_language( self::pageSlug() );
		if ( is_array( $language ) ) {
			wpbc_save_booking_meta_option( $id, array( 'booking_meta_locale' => (string) ( $language['wp_locale'] ?? $language['locale'] ) ) );
		}
	}

	/**
	 * Notes the booking the plugin writes an email for now. An email the plugin sends the visitor later,
	 * as when the booking is approved in its admin, gets the summary of the booking in the language of
	 * the booking: the plugin wrote it in the request's language.
	 *
	 * @param mixed $replace    The values of the email's placeholders.
	 * @param mixed $booking_id The booking.
	 * @param mixed $type       The booking's resource.
	 * @param mixed $data       The booking's data, as the plugin keeps it.
	 * @return mixed
	 */
	public function noteBooking( $replace, $booking_id = 0, $type = 1, $data = '' ) {
		$this->booking = is_numeric( $booking_id ) ? (int) $booking_id : 0;
		if ( ! is_array( $replace ) || ! isset( $replace['content'] ) || '' !== self::pageSlug() || ! function_exists( 'wpbc__legacy__get_form_content_arr' ) ) {
			return $replace;
		}
		$slug = $this->bookingLanguage();
		if ( '' === $slug ) {
			return $replace;
		}
		swiftling_switch_translation( $slug );
		try {
			$summary = wpbc__legacy__get_form_content_arr( $data, $type, '', $replace );
		} finally {
			swiftling_resume_translation();
		}
		if ( is_array( $summary ) && isset( $summary['content'] ) && is_string( $summary['content'] ) ) {
			$replace['content'] = $summary['content'];
		}
		return $replace;
	}

	/**
	 * Returns the subject of an email to the visitor in the language of the booking.
	 *
	 * @param mixed $subject  The subject's template.
	 * @param mixed $email_id The plugin's ID of the email.
	 * @return mixed
	 */
	public function translateSubject( $subject, $email_id = '' ) {
		if ( ! is_string( $subject ) ) {
			return $subject;
		}
		$fields = $this->translateEmail( array( 'subject' => $subject ), $email_id );
		return $fields['subject'];
	}

	/**
	 * Returns the texts of the template of an email to the visitor in the language of the booking: the
	 * language kept with it, or else the language of the page that sends it.
	 *
	 * @param mixed $fields   The template's fields: subject, content, header and footer.
	 * @param mixed $email_id The plugin's ID of the email.
	 * @return mixed
	 */
	public function translateEmail( $fields, $email_id = '' ) {
		if ( ! is_array( $fields ) || ! is_string( $email_id ) || ! isset( self::EMAIL_IDS[ $email_id ] ) ) {
			return $fields;
		}
		$slug = $this->bookingLanguage();
		if ( '' === $slug ) {
			return $fields;
		}
		$option = self::EMAIL_IDS[ $email_id ];
		return swiftling_in_language(
			$slug,
			static function () use ( $fields, $option ) {
				foreach ( self::EMAIL_TEXTS as $field ) {
					if ( isset( $fields[ $field ] ) && is_string( $fields[ $field ] ) && '' !== trim( $fields[ $field ] ) ) {
						$fields[ $field ] = swiftling_transform_text( 'options', 'option/' . $option . '/' . $field, 'option:' . $option, $fields[ $field ] );
					}
				}
				return $fields;
			}
		);
	}

	/**
	 * Returns the email to the site's owner about a booking sent from a page in another language with
	 * the labels of the booking's summary in the original: the plugin writes the summary once, in the
	 * language of the page, for the visitor and the owner alike. The addresses of the site's pages lead
	 * to the original too, as [siteurl], which the plugin fills with the home page in the language.
	 *
	 * @param mixed $content  The email, written.
	 * @param mixed $email_id The plugin's ID of the email.
	 * @param mixed $type     "html" or "plain".
	 * @return mixed
	 */
	public function originalSummary( $content, $email_id = '', $type = 'html' ) {
		if ( ! is_string( $content ) || 'new_admin' !== $email_id || '' === self::pageSlug() ) {
			return $content;
		}
		foreach ( $this->summaryLabels() as $translated => $original ) {
			$content = 'html' === $type ? str_replace( '>' . $translated . '<', '>' . $original . '<', $content ) : str_replace( $translated, $original, $content );
		}
		return swiftling_addresses_in( $content, '' );
	}

	/**
	 * Returns the labels of the summaries of the plugin's published forms in the language of the request,
	 * each with its original.
	 *
	 * @return array<string, string> Translation => original.
	 */
	private function summaryLabels(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'booking_form_structures';
		if ( ! $this->hasTable( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once for an email.
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT form_slug, content_form FROM %i WHERE status = 'published'", $table ), ARRAY_A );
		$labels = array();
		foreach ( (array) $rows as $row ) {
			$owner = self::BASE . ':form:' . sanitize_key( (string) $row['form_slug'] );
			foreach ( HtmlTexts::texts( (string) preg_replace( self::FIELD, '', (string) $row['content_form'] ) ) as $key => $text ) {
				$translated = swiftling_transform_text( $owner, self::BASE . '/content/' . $key, self::BASE . ':form', $text );
				if ( is_string( $translated ) && $translated !== $text ) {
					$labels[ $translated ] = $text;
				}
			}
		}
		return $labels;
	}

	/**
	 * Returns the language of the booking the plugin writes an email for: the locale kept with it, or
	 * the language of the page that sends it.
	 *
	 * @return string Address slug, or an empty string for the original.
	 */
	private function bookingLanguage(): string {
		if ( $this->booking && function_exists( 'wpbc_get_booking_meta_option' ) ) {
			$locale = wpbc_get_booking_meta_option( $this->booking, 'booking_meta_locale' );
			if ( is_string( $locale ) && '' !== $locale ) {
				foreach ( swiftling_languages()['targets'] as $slug => $language ) {
					if ( ( $language['wp_locale'] ?? $language['locale'] ) === $locale || $language['locale'] === $locale ) {
						return (string) $slug;
					}
				}
				return '';
			}
		}
		return self::pageSlug();
	}

	/**
	 * Returns the address slug of the language the request runs in, or an empty string for the original.
	 */
	private static function pageSlug(): string {
		$state = &swiftling_state();
		return 'translate' === $state['mode'] ? (string) $state['language'] : '';
	}
}
