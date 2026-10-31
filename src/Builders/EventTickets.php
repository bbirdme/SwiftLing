<?php
/**
 * Event Tickets: the tickets of an event or a post, in the language of the page.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Event Tickets, with its own shop (Tickets Commerce). A ticket is a post of a type visitors never
 * open, kept with the event or the post it is sold on. What SwiftLing does not find by itself:
 *
 * - The name and the description of a ticket are the title and the excerpt of its post, which the
 *   plugin reads straight from the post, past get_the_title(). The editor of the event or post offers
 *   them, see listTexts(), and they show translated wherever the plugin hands out a ticket: the form
 *   on the page, the cart and the checkout, see translateTicket().
 * - The plugin keeps each ticket it built in the object cache by its ID only. A page in a language,
 *   and a page check, keep their own for the request, see start().
 * - The checkout sends the order through the plugin's REST API, which answers with the address of the
 *   page that confirms the order: in the language of the page it was sent from, see sendingRoutes().
 *
 * The checkout and the confirmation are the same page in every language, so the plugin knows them by
 * their ID in each. Checked against the code of Event Tickets 5.30 (the free plugin), in
 * tests/EventTicketsTest.php, and by hand with The Events Calendar. The emails of an order, Event
 * Tickets Plus and the payment gateways' own pages were not checked.
 */
class EventTickets extends Builder {
	/**
	 * The plugin's type of post of a ticket.
	 */
	const TICKET = 'tec_tc_ticket';

	/**
	 * Meta key that names the event or post a ticket is sold on.
	 */
	const EVENT_KEY = '_tec_tickets_commerce_event';

	/**
	 * Group of the object cache the plugin keeps its tickets in.
	 */
	const CACHE_GROUP = 'tec_tickets';

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'event-tickets';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Event Tickets';
	}

	/**
	 * Whether the plugin is on the site: it names its main file while its files load.
	 */
	public function isActive(): bool {
		return defined( 'EVENT_TICKETS_MAIN_PLUGIN_FILE' );
	}

	/**
	 * Whether a post is built with the plugin. A post with tickets is read as any other.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A post with tickets needs nothing of a builder.
		return false;
	}

	/**
	 * Hooks where the plugin hands out a ticket it built.
	 */
	public function register(): void {
		add_filter( 'tec_tickets_commerce_get_ticket_legacy', array( $this, 'translateTicket' ), 10, 1 );
		add_filter( 'tec_tickets_commerce_get_attendee_after', array( $this, 'translateAttendee' ), 10, 1 );
	}

	/**
	 * Returns an attendee of an order with the name of its ticket in the language of the page, as the
	 * confirmation of the order and the attendee's tickets list it: the plugin copies the name from the
	 * ticket's post as stored. This runs on every call, after the plugin's cache.
	 *
	 * @param mixed $attendee The attendee, a post with the plugin's properties, or an array of them.
	 * @return mixed
	 */
	public function translateAttendee( $attendee ) {
		if ( 'translate' !== swiftling_mode() ) {
			return $attendee;
		}
		$is_object = $attendee instanceof \WP_Post;
		$fields    = $is_object ? get_object_vars( $attendee ) : $attendee;
		if ( ! is_array( $fields ) || empty( $fields['product_id'] ) || ! isset( $fields['ticket'] ) || ! is_string( $fields['ticket'] ) ) {
			return $attendee;
		}
		$ticket = get_post( (int) $fields['product_id'] );
		if ( ! $ticket instanceof \WP_Post || $ticket->post_title !== $fields['ticket'] ) {
			return $attendee;
		}
		$name = swiftling_filter_title( $ticket->post_title, $ticket->ID );
		if ( ! is_string( $name ) || $name === $fields['ticket'] ) {
			return $attendee;
		}
		if ( $is_object ) {
			$attendee         = clone $attendee;
			$attendee->ticket = $name;
			return $attendee;
		}
		$attendee['ticket'] = $name;
		return $attendee;
	}

	/**
	 * Keeps the tickets the plugin builds in a page in a language, or in a page check, to this request:
	 * the plugin keys its cache by the ticket only, so with a persistent object cache a ticket in one
	 * language would be served in the others.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		unset( $probe );
		if ( 'control' !== $mode ) {
			wp_cache_add_non_persistent_groups( array( self::CACHE_GROUP ) );
		}
	}

	/**
	 * Returns the routes of the plugin's REST API at which the checkout sends an order: answered in the
	 * language of the page it was sent from, with the address of the confirmation in it.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/tribe/tickets/v1/commerce/(free|paypal|square|stripe)/order(/|$)#' );
	}

	/**
	 * Returns a ticket the plugin built with its name and description in the mode of the moment.
	 *
	 * @param mixed $ticket The ticket, an object of the plugin with the post's ID.
	 * @return mixed
	 */
	public function translateTicket( $ticket ) {
		if ( ! is_object( $ticket ) || empty( $ticket->ID ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $ticket;
		}
		$id    = (int) $ticket->ID;
		$owner = swiftling_post_owner( $id );
		if ( isset( $ticket->name ) && is_string( $ticket->name ) ) {
			$ticket->name = swiftling_filter_title( $ticket->name, $id );
		}
		if ( isset( $ticket->description ) && is_string( $ticket->description ) && '' !== trim( $ticket->description ) ) {
			$ticket->description = swiftling_transform( $owner, 'field/post_excerpt', 'field:post_excerpt', $ticket->description );
		}
		return $ticket;
	}

	/**
	 * Returns the names and descriptions of the tickets sold on a post, for its editor: a ticket has no
	 * editor of its own. Each is read as a page in a language reads it, so its object and path are the
	 * ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$post = 'post' === $subject['kind'] && isset( $subject['post'] ) && $subject['post'] instanceof \WP_Post ? $subject['post'] : null;
		if ( ! $post || self::TICKET === $post->post_type ) {
			return array();
		}
		$tickets = get_posts(
			array(
				'post_type'      => self::TICKET,
				'post_status'    => array( 'publish', 'private', 'future', 'draft', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
				'meta_key'       => self::EVENT_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The plugin's own link from a ticket to its post, read once for the editor.
				'meta_value'     => (string) $post->ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);
		if ( ! $tickets ) {
			return array();
		}
		$texts = array();
		$read  = static function () use ( $tickets ): void {
			foreach ( $tickets as $ticket ) {
				swiftling_filter_title( $ticket->post_title, $ticket->ID );
				if ( '' !== trim( $ticket->post_excerpt ) ) {
					swiftling_transform( swiftling_post_owner( $ticket->ID ), 'field/post_excerpt', 'field:post_excerpt', $ticket->post_excerpt );
				}
			}
		};
		foreach ( swiftling_collect( $read ) as $leaf ) {
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'field/post_title' === $leaf['p'] ? 'heading' : 'paragraph',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns what the editor calls the name or the description of a ticket, such as
	 * "Ticket: Workshop seat › Name".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no ticket's.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, 'post:' ) ) {
			return '';
		}
		$ticket = get_post( (int) substr( $owner, 5 ) );
		if ( ! $ticket instanceof \WP_Post || self::TICKET !== $ticket->post_type ) {
			return '';
		}
		/* translators: %s: name of a ticket of an event. */
		$whole = sprintf( __( 'Ticket: %s', 'swiftling' ), $ticket->post_title );
		if ( 'field/post_title' === $path ) {
			return $whole . ' › ' . __( 'Name', 'swiftling' );
		}
		return 0 === strpos( $path, 'field/post_excerpt' ) ? $whole . ' › ' . __( 'Description', 'swiftling' ) : $whole;
	}
}
