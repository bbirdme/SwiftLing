<?php
/**
 * Events Manager: what of an event SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Events Manager. An event and a location are posts visitors open, but the plugin prints them from
 * objects of its own, loaded from its own tables, which hold copies of their titles and content and
 * the location's address. What SwiftLing does not find by itself:
 *
 * - The plugin prints an event's name, notes and excerpt, and a location's name, description and
 *   address, from those objects, past get_the_title() and the_content. Where the plugin loads one,
 *   its texts are put in the mode of the moment, see translateEvent() and translateLocation(), under
 *   the paths SwiftLing gives them on the post, so a translation made on either shows on both. An
 *   object is never saved so: the plugin's own editors on the site save what was stored, see
 *   keepStored().
 * - A ticket is a row of the plugin's own table: its name and description are texts of its event,
 *   "events-manager/ticket/<ID>/name", offered in its editor, see listTexts(). A recurrence of a recurring
 *   event has no post: the plugin gives it its recurring event's, and its tickets are copies of that
 *   event's tickets, translated once for every date as those, see translateTicket().
 * - The words the plugin's settings print, such as the label of the search form's button, messages
 *   of bookings and the formats that place an event's fields on a page, are read as options and
 *   declared as text, see fieldHoldsText(), and the formats of dates are declared as none. They are a
 *   subject of their own on the Templates screen too, with the messages no page check sees, as the
 *   one after a booking, see subject(). A format
 *   holds placeholders as #_EVENTNAME, which machine translation keeps as they are, see keepPlaceholders();
 *   a translation that leaves one out shows the original, see keepFormat().
 * - The plugin keeps the objects it loaded in the object cache by their ID: a page in a language and
 *   a page check keep their own for the request, see start().
 * - A booking, the summary of a booking and the search load from the page through admin-ajax.php: in
 *   the language of that page, see sendingActions().
 * - An event and a location get no separate copy: a copy has no row in the plugin's tables, no dates
 *   and no tickets. They are translated in place, see Builder::noCopyTypes().
 *
 * Checked against the code of Events Manager 7.4 (the free plugin), in tests/EventsManagerTest.php,
 * and by hand on a site with the plugin. Events Manager Pro (booking forms, payments, reminders) was
 * not checked.
 */
class EventsManager extends Builder {
	/**
	 * Where the texts of tickets are in the maps of their events.
	 */
	const BASE = 'events-manager';

	/**
	 * Source of a text of a ticket or of an address of a location, for its rule key.
	 */
	const SOURCE = 'events-manager:row';

	/**
	 * The plugin's types of post.
	 */
	const EVENT    = 'event';
	const LOCATION = 'location';

	/**
	 * The fields of a location's address the plugin keeps in its table. Its country is a code, which
	 * the plugin prints as the country's name in the language of the page.
	 */
	const ADDRESS = array( 'location_address', 'location_town', 'location_state', 'location_region' );

	/**
	 * Groups of the object cache the plugin keeps its objects in.
	 */
	const CACHE_GROUPS = array( 'em_events', 'em_locations', 'em_tickets' );

	/**
	 * The settings of the plugin that hold text a visitor reads, as the plugin lists them for
	 * multilingual sites, without the formats of dates and numbers, see NO_TEXT_OPTIONS.
	 */
	const TEXT_OPTIONS = array(
		'dbem_events_anonymous_result_success',
		'dbem_events_form_result_success',
		'dbem_events_form_result_success_updated',
		'dbem_data_privacy_consent_text',
		'dbem_data_comms_consent_text',
		'dbem_event_list_item_format_header',
		'dbem_event_list_item_format',
		'dbem_event_list_item_format_footer',
		'dbem_no_events_message',
		'dbem_single_event_format',
		'dbem_event_excerpt_format',
		'dbem_event_excerpt_alt_format',
		'dbem_search_form_submit',
		'dbem_search_form_advanced_hide',
		'dbem_search_form_advanced_show',
		'dbem_search_form_text_label',
		'dbem_search_form_categories_label',
		'dbem_search_form_category_label',
		'dbem_search_form_countries_label',
		'dbem_search_form_country_label',
		'dbem_search_form_regions_label',
		'dbem_search_form_region_label',
		'dbem_search_form_states_label',
		'dbem_search_form_state_label',
		'dbem_search_form_towns_label',
		'dbem_search_form_town_label',
		'dbem_search_form_tags_label',
		'dbem_search_form_tag_label',
		'dbem_search_form_tags_placeholder',
		'dbem_search_form_categories_placeholder',
		'dbem_search_form_geo_label',
		'dbem_search_form_geo_units_label',
		'dbem_search_form_dates_label',
		'dbem_search_form_dates_separator',
		'dbem_event_all_day_message',
		'dbem_small_calendar_event_title_format',
		'dbem_full_calendar_event_format',
		'dbem_calendar_large_pill_format',
		'dbem_calendar_preview_modal_event_format',
		'dbem_calendar_preview_tooltip_event_format',
		'dbem_display_calendar_events_limit_msg',
		'dbem_ical_description_format',
		'dbem_ical_real_description_format',
		'dbem_location_page_title_format',
		'dbem_single_location_format',
		'dbem_location_event_list_item_header_format',
		'dbem_location_event_list_item_format',
		'dbem_location_event_list_item_footer_format',
		'dbem_location_no_events_message',
		'dbem_location_event_single_format',
		'dbem_location_no_event_message',
		'dbem_categories_list_item_format_header',
		'dbem_categories_list_item_format',
		'dbem_categories_list_item_format_footer',
		'dbem_no_categories_message',
		'dbem_category_page_title_format',
		'dbem_category_page_format',
		'dbem_category_event_list_item_header_format',
		'dbem_category_event_list_item_format',
		'dbem_category_event_list_item_footer_format',
		'dbem_category_no_events_message',
		'dbem_category_no_event_message',
		'dbem_category_event_single_format',
		'dbem_tags_list_item_format_header',
		'dbem_tags_list_item_format',
		'dbem_tags_list_item_format_footer',
		'dbem_no_tags_message',
		'dbem_tag_page_title_format',
		'dbem_tag_page_format',
		'dbem_tag_event_list_item_header_format',
		'dbem_tag_event_list_item_format',
		'dbem_tag_event_list_item_footer_format',
		'dbem_tag_no_events_message',
		'dbem_tag_event_single_format',
		'dbem_tag_no_event_message',
		'dbem_rss_main_description',
		'dbem_rss_main_title',
		'dbem_rss_title_format',
		'dbem_rss_description_format',
		'dbem_map_text_format',
		'dbem_location_baloon_format',
		'dbem_booking_feedback_cancelled',
		'dbem_booking_warning_cancel',
		'dbem_bookings_form_msg_disabled',
		'dbem_bookings_form_msg_closed',
		'dbem_bookings_form_msg_cancelled',
		'dbem_bookings_form_msg_full',
		'dbem_bookings_form_msg_attending',
		'dbem_bookings_form_msg_bookings_link',
		'dbem_booking_feedback',
		'dbem_booking_feedback_pending',
		'dbem_booking_feedback_full',
		'dbem_booking_feedback_error',
		'dbem_booking_feedback_email_exists',
		'dbem_booking_feedback_log_in',
		'dbem_booking_feedback_nomail',
		'dbem_booking_feedback_already_booked',
		'dbem_booking_feedback_min_space',
		'dbem_booking_feedback_spaces_limit',
		'dbem_booking_button_msg_book',
		'dbem_booking_button_msg_booking',
		'dbem_booking_button_msg_booked',
		'dbem_booking_button_msg_already_booked',
		'dbem_booking_button_msg_error',
		'dbem_booking_button_msg_full',
		'dbem_booking_button_msg_event_cancelled',
		'dbem_booking_button_msg_closed',
		'dbem_booking_button_msg_cancel',
		'dbem_booking_button_msg_canceling',
		'dbem_booking_button_msg_cancelled',
		'dbem_booking_button_msg_cancel_error',
		'dbem_bookings_submit_button',
		'dbem_bookings_submit_button_paid',
		'dbem_bookings_submit_button_processing',
		'dbem_bookings_summary_message',
		'dbem_bookings_header_tickets',
		'dbem_bookings_header_reg_info',
		'dbem_bookings_header_summary',
		'dbem_bookings_header_confirm',
		'dbem_bookings_header_confirm_free',
	);

	/**
	 * The settings of the plugin that look like text but are formats of dates, times and numbers, or
	 * the separators between them: the plugin hands them to date() or builds numbers with them, so they
	 * are never marked.
	 */
	const NO_TEXT_OPTIONS = array(
		'dbem_date_format',
		'dbem_date_format_js',
		'dbem_dates_separator',
		'dbem_time_format',
		'dbem_times_separator',
		'dbem_list_date_title',
		'dbem_event_list_groupby_format',
		'dbem_small_calendar_month_format',
		'dbem_full_calendar_month_format',
		'dbem_calendar_preview_modal_date_format',
		'dbem_bookings_currency_thousands_sep',
		'dbem_bookings_currency_decimal_point',
		'dbem_bookings_currency_format',
	);

	/**
	 * Key of the subject of the plugin's settings.
	 */
	const SETTINGS_SUBJECT = 'em-settings';

	/**
	 * A placeholder of the plugin's formats, as #_EVENTNAME, #_LOCATIONADDRESS or #_{j M Y}.
	 */
	const PLACEHOLDER = '/#@?_\{[^}]*\}|#@?_[A-Z0-9_]+(?:\{[^}]*\})?/';

	/**
	 * The texts each object the plugin loaded showed, as stored and as shown, by object.
	 *
	 * @var \SplObjectStorage<object, array<string, array{0: mixed, 1: mixed}>>|null
	 */
	private $shown = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'events-manager';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Events Manager';
	}

	/**
	 * Whether the plugin is on the site: it names its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'EM_VERSION' );
	}

	/**
	 * Whether a post is built with the plugin. An event is a post SwiftLing reads as any other.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- An event needs nothing of a builder.
		return false;
	}

	/**
	 * Hooks where the plugin loads an event, a location and a ticket, and where it saves one.
	 */
	public function register(): void {
		add_action( 'em_event', array( $this, 'translateEvent' ), 10, 1 );
		add_action( 'em_location', array( $this, 'translateLocation' ), 10, 1 );
		add_action( 'em_ticket', array( $this, 'translateTicket' ), 10, 1 );
		// The plugin keeps a copy of each object as it loaded it, before the actions above, and hands out
		// a copy of that one the next time.
		add_filter( 'em_get_event', array( $this, 'translateCached' ), 10, 1 );
		add_filter( 'em_get_location', array( $this, 'translateCached' ), 10, 1 );
		foreach ( array( 'em_event_save_pre', 'em_location_save_pre', 'em_ticket_save_pre' ) as $hook ) {
			add_action( $hook, array( $this, 'keepStored' ), 1, 1 );
		}
		// After SwiftLing's own filter of options (100), which put the translation in.
		add_filter( 'pre_option', array( $this, 'keepFormat' ), 101, 2 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepPlaceholders' ) );
	}

	/**
	 * Keeps the objects the plugin loads in a page in a language, or in a page check, to this request:
	 * the plugin keys its cache by the ID only, so with a persistent object cache an event in one
	 * language would be served in the others.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		unset( $probe );
		if ( 'control' !== $mode ) {
			wp_cache_add_non_persistent_groups( self::CACHE_GROUPS );
		}
	}

	/**
	 * Returns the actions of admin-ajax.php the page of an event sends: a booking, its summary and the
	 * search. Answered in the language of that page.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'booking_add', 'booking_form_summary', 'search_events', 'search_events_grouped', 'search_cats', 'search_tags', 'search_locations' );
	}

	/**
	 * Returns the plugin's types of post that get no separate copy: a copy has no row in the plugin's
	 * tables, so no dates, no tickets and no address.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::EVENT, self::LOCATION );
	}

	/**
	 * Whether SwiftLing changes the request: a page check or a page in a language.
	 */
	private static function inMode(): bool {
		return in_array( swiftling_mode(), array( 'probe', 'translate' ), true );
	}

	/**
	 * Gives an event the plugin loaded its name, notes and excerpt in the mode of the moment, under the
	 * paths of the post's title, content and excerpt.
	 *
	 * @param mixed $event The plugin's event.
	 */
	public function translateEvent( $event ): void {
		if ( ! is_object( $event ) || ! self::inMode() ) {
			return;
		}
		$post_id = (int) $event->post_id;
		if ( ! $post_id ) {
			return;
		}
		$owner = swiftling_post_owner( $post_id );
		$this->show( $event, 'event_name', swiftling_filter_title( $event->event_name, $post_id ) );
		if ( is_string( $event->post_content ) && '' !== trim( $event->post_content ) ) {
			$this->show( $event, 'post_content', swiftling_translate_post_content( $owner, $event->post_content ) );
		}
		if ( is_string( $event->post_excerpt ) && '' !== trim( $event->post_excerpt ) ) {
			$this->show( $event, 'post_excerpt', swiftling_transform( $owner, 'field/post_excerpt', 'field:post_excerpt', $event->post_excerpt ) );
		}
	}

	/**
	 * Gives a location the plugin loaded its name, description and address in the mode of the moment:
	 * the name and description under the paths of the post's title and content, the address under paths
	 * of its own, "events-manager/location_address".
	 *
	 * @param mixed $location The plugin's location.
	 */
	public function translateLocation( $location ): void {
		if ( ! is_object( $location ) || ! self::inMode() ) {
			return;
		}
		$post_id = (int) $location->post_id;
		if ( ! $post_id ) {
			return;
		}
		$owner = swiftling_post_owner( $post_id );
		$this->show( $location, 'location_name', swiftling_filter_title( $location->location_name, $post_id ) );
		if ( is_string( $location->post_content ) && '' !== trim( $location->post_content ) ) {
			$this->show( $location, 'post_content', swiftling_translate_post_content( $owner, $location->post_content ) );
		}
		foreach ( self::ADDRESS as $field ) {
			if ( is_string( $location->$field ) && '' !== trim( $location->$field ) ) {
				$this->show( $location, $field, swiftling_transform_text( $owner, self::BASE . '/' . $field, self::SOURCE, $location->$field ) );
			}
		}
	}

	/**
	 * Returns an event or a location the plugin hands out from its cache with its texts in the mode of
	 * the moment, as one it loads, see translateEvent() and translateLocation(). An object with its
	 * texts in the language already keeps them.
	 *
	 * @param mixed $item The plugin's event or location.
	 * @return mixed
	 */
	public function translateCached( $item ) {
		if ( $item instanceof \EM_Event ) {
			$this->translateEvent( $item );
		} elseif ( $item instanceof \EM_Location ) {
			$this->translateLocation( $item );
		}
		return $item;
	}

	/**
	 * Gives a ticket the plugin loaded its name and description in the mode of the moment, as texts of
	 * its event. A ticket of a recurrence has none of its own: the plugin reads them from its recurring
	 * event's ticket, which it loads as a ticket too.
	 *
	 * @param mixed $ticket The plugin's ticket.
	 */
	public function translateTicket( $ticket ): void {
		if ( ! is_object( $ticket ) || ! self::inMode() || empty( $ticket->ticket_id ) ) {
			return;
		}
		// A ticket of a recurrence is a copy of its recurring event's ticket, with that ticket's texts:
		// it is translated as that ticket, once for every date.
		$source = ! empty( $ticket->ticket_parent ) ? (int) $ticket->ticket_parent : (int) $ticket->ticket_id;
		$owner  = self::ticketOwner( $source );
		if ( '' === $owner ) {
			return;
		}
		foreach ( array(
			'ticket_name'        => 'name',
			'ticket_description' => 'description',
		) as $field => $part ) {
			$text = $ticket->$field;
			if ( is_string( $text ) && '' !== trim( $text ) ) {
				$this->show( $ticket, $field, swiftling_transform_text( $owner, self::ticketPath( $source, $part ), self::SOURCE, $text ) );
			}
		}
	}

	/**
	 * Returns the path of a text of a ticket in the map of its event.
	 *
	 * @param int    $ticket_id Ticket ID.
	 * @param string $part      name or description.
	 */
	public static function ticketPath( int $ticket_id, string $part ): string {
		return self::BASE . '/ticket/' . $ticket_id . '/' . $part;
	}

	/**
	 * Returns the object key of the event a ticket is sold for: its post. A recurrence of a recurring
	 * event has no post of its own, but its tickets are read as its recurring event's, see
	 * translateTicket().
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return string Empty when the ticket's event has no post.
	 */
	private static function ticketOwner( int $ticket_id ): string {
		global $wpdb;
		$post_id = wp_cache_get( $ticket_id, 'swiftling_em_ticket_posts' );
		if ( false === $post_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own tables, once a ticket in a request.
			$post_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT e.post_id FROM %i t JOIN %i e ON e.event_id = t.event_id WHERE t.ticket_id = %d', $wpdb->prefix . 'em_tickets', $wpdb->prefix . 'em_events', $ticket_id ) );
			wp_cache_set( $ticket_id, $post_id, 'swiftling_em_ticket_posts' );
		}
		return $post_id ? swiftling_post_owner( (int) $post_id ) : '';
	}

	/**
	 * Puts a text on an object of the plugin for display and remembers what was stored, so the object
	 * is never saved with it.
	 *
	 * @param object $item  The plugin's object.
	 * @param string $field Its field.
	 * @param mixed  $text  The text as shown.
	 */
	private function show( $item, string $field, $text ): void {
		$stored = $item->$field;
		if ( ! is_string( $text ) || $text === $stored ) {
			return;
		}
		if ( null === $this->shown ) {
			$this->shown = new \SplObjectStorage();
		}
		$fields               = isset( $this->shown[ $item ] ) ? $this->shown[ $item ] : array();
		$fields[ $field ]     = array( $stored, $text );
		$this->shown[ $item ] = $fields;
		$item->$field         = $text;
	}

	/**
	 * Puts back what was stored on an object of the plugin before the plugin saves it: a form of the
	 * plugin on a page in a language, such as its form to submit an event, saves the object it loaded,
	 * which shows the translation. A field the form changed keeps what was typed.
	 *
	 * @param mixed $item The plugin's event, location or ticket.
	 */
	public function keepStored( $item ): void {
		if ( ! is_object( $item ) || null === $this->shown || ! isset( $this->shown[ $item ] ) ) {
			return;
		}
		foreach ( $this->shown[ $item ] as $field => $texts ) {
			if ( $item->$field === $texts[1] ) {
				$item->$field = $texts[0];
			}
		}
		unset( $this->shown[ $item ] );
	}

	/**
	 * Declares which of the plugin's settings hold text: those in TEXT_OPTIONS, and none of the formats
	 * of dates and numbers. A ticket's name and description, and the fields of a location's address,
	 * are texts too, also one word.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for everything else.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 'options' === $owner && 0 === strpos( $path, 'option/dbem_' ) ) {
			$name = explode( '/', substr( $path, 7 ) )[0];
			if ( in_array( $name, self::TEXT_OPTIONS, true ) ) {
				return true;
			}
			return in_array( $name, self::NO_TEXT_OPTIONS, true ) ? false : null;
		}
		if ( 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, self::BASE . '/' ) ) {
			return true;
		}
		return null;
	}

	/**
	 * Shows the original of a format of the plugin when its translation leaves out a placeholder of
	 * the original, as #_EVENTDATES: the event's dates would be missing from every page in that language.
	 *
	 * @param mixed  $value  The option, as SwiftLing's filter gave it: its translation, or false.
	 * @param string $option Option name.
	 * @return mixed
	 */
	public function keepFormat( $value, $option = '' ) {
		$state = &swiftling_state();
		// While SwiftLing reads the stored option itself, below: the plugin hands some options out
		// through a filter of its own, so the value is a string there too.
		if ( $state['guard'] || ! is_string( $value ) || 'translate' !== $state['mode'] || ! in_array( $option, self::TEXT_OPTIONS, true ) ) {
			return $value;
		}
		$stored = swiftling_raw_option( $option );
		if ( ! is_string( $stored ) || ! preg_match_all( self::PLACEHOLDER, $stored, $needed ) ) {
			return $value;
		}
		preg_match_all( self::PLACEHOLDER, $value, $kept );
		return array_diff( array_unique( $needed[0] ), $kept[0] ) ? false : $value;
	}

	/**
	 * Keeps the placeholders of the plugin's formats as they are when a format goes to machine
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

	/**
	 * Returns a subject of the editor: the plugin's settings that hold text, also those no page shows
	 * until a visitor acts, such as the message after a booking.
	 *
	 * @param string $key Subject key.
	 */
	public function subject( string $key ): ?array {
		if ( self::SETTINGS_SUBJECT !== $key ) {
			return null;
		}
		return array(
			'key'   => $key,
			'kind'  => 'fields',
			'title' => __( 'Events Manager settings', 'swiftling' ),
			'type'  => __( 'Settings', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => __( 'The words Events Manager shows around events, its search form, the messages of bookings and the formats that place an event\'s fields on a page. Keep every placeholder, such as #_EVENTNAME: a translation without one shows the original.', 'swiftling' ),
		);
	}

	/**
	 * Returns the subject of the plugin's settings, for the Templates screen.
	 *
	 * @return array
	 */
	public function subjects(): array {
		return array(
			array(
				'title'       => __( 'Events Manager', 'swiftling' ),
				'description' => __( 'Texts the plugin keeps in its settings.', 'swiftling' ),
				'subjects'    => array(
					array(
						'key'         => self::SETTINGS_SUBJECT,
						'title'       => __( 'Settings', 'swiftling' ),
						'description' => __( 'Search form, messages of bookings, formats of events and locations', 'swiftling' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the texts of an event's tickets for its editor: their names and descriptions, which show
	 * only while bookings are open. Of a recurrence, those of its recurring event's tickets, which it
	 * shows. Each is read as a page in a language reads it, so its object and path are the ones
	 * translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		global $wpdb;
		if ( self::SETTINGS_SUBJECT === $subject['key'] ) {
			return $this->collect(
				static function (): void {
					foreach ( self::TEXT_OPTIONS as $option ) {
						get_option( $option );
					}
				}
			);
		}
		$post = 'post' === $subject['kind'] && isset( $subject['post'] ) && $subject['post'] instanceof \WP_Post ? $subject['post'] : null;
		if ( ! $post || self::EVENT !== $post->post_type ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables, read once for the editor.
		$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT t.ticket_id, t.event_id, t.ticket_name, t.ticket_description, t.ticket_parent FROM %i t JOIN %i e ON e.event_id = t.event_id WHERE e.post_id = %d ORDER BY t.ticket_order, t.ticket_id', $wpdb->prefix . 'em_tickets', $wpdb->prefix . 'em_events', $post->ID ), ARRAY_A );
		$tickets = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// A ticket of a recurrence shows its parent's texts.
			if ( ! empty( $row['ticket_parent'] ) && '' === (string) $row['ticket_name'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As above.
				$parent = $wpdb->get_row( $wpdb->prepare( 'SELECT ticket_id, event_id, ticket_name, ticket_description FROM %i WHERE ticket_id = %d', $wpdb->prefix . 'em_tickets', (int) $row['ticket_parent'] ), ARRAY_A );
				if ( is_array( $parent ) ) {
					$row = $parent;
				}
			}
			$tickets[ (int) $row['ticket_id'] ] = (object) $row;
		}
		if ( ! $tickets ) {
			return array();
		}
		$read = static function () use ( $tickets ): void {
			foreach ( $tickets as $ticket ) {
				$owner = self::ticketOwner( (int) $ticket->ticket_id );
				foreach ( array(
					'name'        => (string) $ticket->ticket_name,
					'description' => (string) $ticket->ticket_description,
				) as $part => $text ) {
					if ( '' !== $owner && '' !== trim( $text ) ) {
						swiftling_transform_text( $owner, self::ticketPath( (int) $ticket->ticket_id, $part ), self::SOURCE, $text );
					}
				}
			}
		};
		return $this->collect( $read );
	}

	/**
	 * Reads texts as a page check would, see swiftling_collect(), and returns them for the editor.
	 *
	 * @param callable $read Reads the texts.
	 * @return array<int, array{o: string, p: string, v: string, r: string, kind: string, place: string}>
	 */
	private function collect( callable $read ): array {
		$texts = array();
		foreach ( swiftling_collect( $read ) as $leaf ) {
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => '/name' === substr( $leaf['p'], -5 ) ? 'heading' : 'paragraph',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns what the editor calls a text of a ticket or of a location's address, such as
	 * "Ticket 2 › Name" or "Address › Town".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is none of these.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 'options' === $owner && preg_match( '#^option/(dbem_[a-z0-9_]+)#', $path, $option ) && in_array( $option[1], self::TEXT_OPTIONS, true ) ) {
			// The plugin's own name of the setting, as "search form submit".
			return ucfirst( str_replace( '_', ' ', substr( $option[1], 5 ) ) );
		}
		if ( preg_match( '#^' . self::BASE . '/ticket/(\d+)/(name|description)$#', $path, $found ) ) {
			/* translators: %d: number of a ticket of an event. */
			$ticket = sprintf( __( 'Ticket %d', 'swiftling' ), (int) $found[1] );
			return $ticket . ' › ' . ( 'name' === $found[2] ? __( 'Name', 'swiftling' ) : __( 'Description', 'swiftling' ) );
		}
		$labels = array(
			'location_address' => __( 'Address', 'swiftling' ),
			'location_town'    => __( 'City', 'swiftling' ),
			'location_state'   => __( 'State', 'swiftling' ),
			'location_region'  => __( 'Region', 'swiftling' ),
		);
		foreach ( $labels as $field => $label ) {
			if ( self::BASE . '/' . $field === $path ) {
				return $label;
			}
		}
		return '';
	}
}
