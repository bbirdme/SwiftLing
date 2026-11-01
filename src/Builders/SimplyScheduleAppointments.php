<?php
/**
 * Simply Schedule Appointments: its booking form and emails in the language of the visitor.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Simply Schedule Appointments. The plugin shows its booking form in a frame of its own, loaded from
 * its REST API, and passes the frame the locale of the page: its own words are in the language of the
 * page already. What SwiftLing does not find by itself:
 *
 * - The names, descriptions and instructions of the appointment types are rows of the plugin's own
 *   table. They are texts of the type, "ssa:<ID>" with paths such as "ssa/title", translated where the
 *   plugin prepares a type for the form or an email, see translateType(), and offered on the subject
 *   of the plugin's settings, see readMoreTexts().
 * - The frame and the booking are requests of the REST API, answered in the language of the page,
 *   see sendingRoutes(); the requests of the frame name the frame, whose address names the page, see
 *   sentFrom().
 * - The emails are templates in the plugin's settings, sent later, by a scheduled task. The plugin
 *   keeps the locale the visitor booked in: an email to the customer is written in its language, one
 *   to the site's staff in the original, see startNotification(). The plugin reads its settings once a
 *   request, so the template of an email to the customer is written again from its translation, see
 *   translateEmail(). Only the templates of emails to the customer are offered for translation; their
 *   placeholders, such as {{ customer_name }}, stay as they are in machine translation, see
 *   keepPlaceholders().
 *
 * Checked against the code of Simply Schedule Appointments 1.6 (the free plugin), in
 * tests/SimplyScheduleAppointmentsTest.php, and by hand on a site with the plugin.
 */
class SimplyScheduleAppointments extends SettingsTexts {
	/**
	 * Name of the option the plugin keeps its settings in, as JSON.
	 */
	const OPTION = 'ssa_settings_json';

	/**
	 * Where the texts of an appointment type are in the map of the type, and their source for rule keys.
	 */
	const BASE   = 'ssa';
	const SOURCE = 'ssa:type';

	/**
	 * The texts of an appointment type a visitor reads.
	 */
	const TYPE_TEXTS = array( 'title', 'description', 'instructions' );

	/**
	 * The actions with which the plugin sends the notifications of an appointment, and the one with
	 * which it sends one notification.
	 */
	const NOTIFICATION_ACTIONS = array( 'ssa_fire_appointment_booked_notifications', 'ssa_fire_appointment_rescheduled_notifications', 'ssa_fire_appointment_start_date_notifications', 'ssa_fire_appointment_customer_information_edited_notifications', 'ssa_fire_appointment_canceled_notifications' );
	const SEND_ACTION          = 'ssa/async/send_notifications';

	/**
	 * A placeholder of the plugin's templates, as {{ customer_name }} or {% if ... %}.
	 */
	const PLACEHOLDER = '/\{\{.*?\}\}|\{%.*?%\}/s';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'simply-schedule-appointments';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Simply Schedule Appointments';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return function_exists( 'ssa' ) && class_exists( 'Simply_Schedule_Appointments', false );
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
	 * Hooks where the plugin prepares an appointment type and sends its notifications.
	 */
	public function register(): void {
		add_filter( 'ssa/appointment_type/prepare_item_for_response', array( $this, 'translateType' ), 1000, 2 );
		foreach ( self::NOTIFICATION_ACTIONS as $action ) {
			add_action( $action, array( $this, 'startNotification' ), 9, 1 );
			add_action( $action, array( $this, 'endNotification' ), 11, 0 );
		}
		add_action( self::SEND_ACTION, array( $this, 'startSending' ), 9, 2 );
		add_action( self::SEND_ACTION, array( $this, 'endNotification' ), 11, 0 );
		add_filter( 'ssa/notifications/email/args', array( $this, 'translateEmail' ), 10, 5 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Returns the routes of the plugin's REST API the booking form is loaded from and sends a booking
	 * to: answered in the language of the page.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/ssa/v1/(embed-inner|appointment_types(/\d+(/availability)?)?|appointments)/?$#' );
	}

	/**
	 * Returns the page a request of the plugin's frame was sent from: the frame's address names it.
	 *
	 * @param string $referer The address the request names as its Referer.
	 */
	public function sentFrom( string $referer ): string {
		if ( false === strpos( $referer, '/ssa/v1/embed-inner' ) ) {
			return $referer;
		}
		wp_parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );
		return isset( $query['booking_url'] ) && is_string( $query['booking_url'] ) && '' !== $query['booking_url'] ? $query['booking_url'] : $referer;
	}

	/**
	 * Returns an appointment type as the plugin prepares it, with its texts in the mode of the moment.
	 *
	 * @param mixed $item The type, by field.
	 * @param mixed $id   The type's ID.
	 * @return mixed
	 */
	public function translateType( $item, $id = 0 ) {
		if ( ! is_array( $item ) || ! $id || ! self::inMode() ) {
			return $item;
		}
		foreach ( self::TYPE_TEXTS as $field ) {
			if ( isset( $item[ $field ] ) && is_string( $item[ $field ] ) && '' !== trim( $item[ $field ] ) ) {
				$item[ $field ] = swiftling_transform_text( self::BASE . ':' . (int) $id, self::BASE . '/' . $field, self::SOURCE, $item[ $field ] );
			}
		}
		return $item;
	}

	/**
	 * Before the plugin sends a notification of an appointment: in the language the customer booked in
	 * when it goes to the customer, otherwise in the original.
	 *
	 * @param mixed $payload What the plugin sends the notification for: the appointment and the notification.
	 */
	public function startNotification( $payload ): void {
		$notification = is_array( $payload ) && isset( $payload['notification']['id'] ) ? $this->notification( (string) $payload['notification']['id'] ) : null;
		$this->switchFor( $notification, is_array( $payload ) && isset( $payload['appointment']['id'] ) ? (int) $payload['appointment']['id'] : 0 );
	}

	/**
	 * Before the plugin sends one notification, as startNotification().
	 *
	 * @param mixed $notification The notification, as the plugin's settings keep it.
	 * @param mixed $payload      What the plugin sends it for.
	 */
	public function startSending( $notification, $payload = null ): void {
		$this->switchFor( is_array( $notification ) ? $notification : null, is_array( $payload ) && isset( $payload['appointment']['id'] ) ? (int) $payload['appointment']['id'] : 0 );
	}

	/**
	 * Returns an email to the customer written from the translation of its template: the plugin wrote
	 * it from its settings as it read them first in the request, untranslated.
	 *
	 * @param mixed $args         The email: subject, message and recipients.
	 * @param mixed $notification The notification, as the plugin's settings keep it.
	 * @param mixed $vars         The values the template's placeholders name.
	 * @param mixed $appointment  The appointment, as the plugin's object.
	 * @param mixed $recipient    "customer" or "staff".
	 * @return mixed
	 */
	public function translateEmail( $args, $notification = null, $vars = array(), $appointment = null, $recipient = '' ) {
		if ( 'customer' !== $recipient || 'translate' !== swiftling_mode() || ! is_array( $args ) || ! is_array( $notification ) || ! isset( $notification['id'] ) || ! is_object( $appointment ) || ! function_exists( 'ssa' ) ) {
			return $args;
		}
		$renderer = ssa()->notifications;
		if ( ! is_object( $renderer ) || ! method_exists( $renderer, 'get_rendered_template_string_for_appointment' ) ) {
			return $args;
		}
		$path = 'option/' . self::OPTION . '/@json/notifications/notifications/#' . swiftling_escape_segment( (string) $notification['id'] );
		foreach ( array( 'subject', 'message' ) as $field ) {
			$template = isset( $notification[ $field ] ) && is_string( $notification[ $field ] ) ? $notification[ $field ] : '';
			if ( '' === trim( $template ) ) {
				continue;
			}
			$translated = swiftling_transform_text( 'options', $path . '/' . $field, 'option:' . self::OPTION, $template );
			if ( $translated === $template ) {
				continue;
			}
			$written = $renderer->get_rendered_template_string_for_appointment( $appointment, $translated, is_array( $vars ) ? $vars : array() );
			// As the plugin writes a subject: plain text, its entities decoded.
			$args[ $field ] = 'subject' === $field ? html_entity_decode( wp_strip_all_tags( (string) $written, true ) ) : $written;
		}
		return $args;
	}

	/**
	 * After the plugin sent a notification: the request as it was.
	 */
	public function endNotification(): void {
		swiftling_resume_translation();
	}

	/**
	 * Translates the request into the language of an appointment's customer for a notification to the
	 * customer, or puts it in the original for any other.
	 *
	 * @param array|null $notification The notification, as the plugin's settings keep it.
	 * @param int        $appointment  The appointment's ID.
	 */
	private function switchFor( ?array $notification, int $appointment ): void {
		if ( null === $notification || ! self::toCustomer( $notification ) || ! $appointment ) {
			swiftling_pause_translation();
			return;
		}
		swiftling_switch_translation( swiftling_locale_slug( $this->customerLocale( $appointment ) ) );
	}

	/**
	 * Returns the locale an appointment was booked in, as the plugin keeps it.
	 *
	 * @param int $appointment The appointment's ID.
	 */
	protected function customerLocale( int $appointment ): string {
		$model = function_exists( 'ssa' ) ? ssa()->appointment_model : null;
		return is_object( $model ) && method_exists( $model, 'db_get_field' ) ? (string) $model->db_get_field( 'customer_locale', $appointment ) : '';
	}

	/**
	 * Returns the plugin's notifications, as its settings keep them, untranslated.
	 *
	 * @return array<int, array>
	 */
	protected function notifications(): array {
		$settings = json_decode( (string) swiftling_raw_option( self::OPTION, '' ), true );
		return isset( $settings['notifications']['notifications'] ) && is_array( $settings['notifications']['notifications'] ) ? array_values( array_filter( $settings['notifications']['notifications'], 'is_array' ) ) : array();
	}

	/**
	 * Returns a notification of the plugin by its ID.
	 *
	 * @param string $id The notification's ID.
	 */
	private function notification( string $id ): ?array {
		foreach ( $this->notifications() as $notification ) {
			if ( isset( $notification['id'] ) && (string) $notification['id'] === $id ) {
				return $notification;
			}
		}
		return null;
	}

	/**
	 * Whether a notification goes to the customer: the plugin's own test, whether its recipients name
	 * the customer's email or phone.
	 *
	 * @param array $notification The notification.
	 */
	private static function toCustomer( array $notification ): bool {
		$recipients = isset( $notification['sent_to'] ) && is_array( $notification['sent_to'] ) ? implode( ',', array_filter( $notification['sent_to'], 'is_string' ) ) : '';
		return false !== strpos( $recipients, 'customer_email' ) || false !== strpos( $recipients, 'customer_phone' );
	}

	/**
	 * Returns the plugin's option, whose texts are those of the notifications to the customer.
	 *
	 * @return array<string, string[]>
	 */
	protected function textOptions(): array {
		return array( self::OPTION => array() );
	}

	/**
	 * Returns the sentence the editor shows above the plugin's texts.
	 */
	protected function settingsNote(): string {
		return __( 'The names, descriptions and instructions of the appointment types, and the emails to customers, which go out in the language the customer booked in. Keep every placeholder, such as {{ customer_name }}.', 'swiftling' );
	}

	/**
	 * Reads the texts of every appointment type for the subject of the settings, as the plugin prepares
	 * a type for the booking form.
	 */
	protected function readMoreTexts(): void {
		$model = function_exists( 'ssa' ) ? ssa()->appointment_type_model : null;
		if ( ! is_object( $model ) || ! method_exists( $model, 'query' ) ) {
			return;
		}
		foreach ( (array) $model->query( array( 'number' => -1 ) ) as $type ) {
			if ( is_array( $type ) && isset( $type['id'] ) ) {
				$this->translateType( $type, $type['id'] );
			}
		}
	}

	/**
	 * Declares the texts of an appointment type and of the notifications to the customer as text, and
	 * every other value of the plugin's settings, such as an email address or a template to the staff,
	 * as none.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of nothing of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 === strpos( $owner, self::BASE . ':' ) ) {
			return 0 === strpos( $path, self::BASE . '/' ) ? true : null;
		}
		$prefix = 'option/' . self::OPTION;
		if ( 'options' !== $owner || ( $prefix !== $path && 0 !== strpos( $path, $prefix . '/' ) ) ) {
			return null;
		}
		if ( ! preg_match( '#^' . preg_quote( $prefix, '#' ) . '/@json/notifications/notifications/\#([^/]+)/(subject|message)$#', $path, $found ) ) {
			return false;
		}
		$notification = $this->notification( str_replace( array( '%2F', '%25' ), array( '/', '%' ), $found[1] ) );
		return null !== $notification && self::toCustomer( $notification );
	}

	/**
	 * Returns what the editor calls a text of an appointment type or of a notification.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no text of the plugin.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 === strpos( $owner, self::BASE . ':' ) ) {
			$labels = array(
				'title'        => __( 'Name', 'swiftling' ),
				'description'  => __( 'Description', 'swiftling' ),
				'instructions' => __( 'Instructions', 'swiftling' ),
			);
			$field  = (string) substr( $path, strlen( self::BASE ) + 1 );
			/* translators: %d: number of an appointment type. */
			return sprintf( __( 'Appointment type %d', 'swiftling' ), (int) substr( $owner, strlen( self::BASE ) + 1 ) ) . ' › ' . ( $labels[ $field ] ?? $field );
		}
		if ( 'options' === $owner && preg_match( '#/notifications/notifications/\#([^/]+)/(subject|message)$#', $path, $found ) ) {
			$notification = $this->notification( str_replace( array( '%2F', '%25' ), array( '/', '%' ), $found[1] ) );
			$title        = null !== $notification && isset( $notification['title'] ) && is_string( $notification['title'] ) ? $notification['title'] : $found[1];
			return $title . ' › ' . ( 'subject' === $found[2] ? __( 'Subject', 'swiftling' ) : __( 'Message', 'swiftling' ) );
		}
		return '';
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
