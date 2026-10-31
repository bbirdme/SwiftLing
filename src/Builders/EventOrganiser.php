<?php
/**
 * Event Organiser: what of an event SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Event Organiser. An event is a post visitors open, and its venue and categories are terms, which
 * SwiftLing translates as on any post and term. What it does not find by itself:
 *
 * - A venue keeps its address and its description in the plugin's own table of venue fields, which
 *   the plugin reads through get_metadata() of its own type, past term meta. They are read as fields
 *   of the venue's term, see filterVenueMeta(), so a translation shows wherever the plugin prints the
 *   venue: on the event, on the venue's page and on its map. Venues have no list of terms on the
 *   Terms screen, as the plugin keeps them from the admin's term screens: the editor of each event
 *   offers its venue's name, address and description, see listTexts(). Its position on the map is
 *   no text, see fieldHoldsText().
 * - The calendar and the agenda load their events from the page through admin-ajax.php: in the
 *   language of that page, see sendingActions(). The plugin can keep what they load in caches of its
 *   own, by the query only: a page in a language and a page check neither read nor fill them, see
 *   start().
 * - An event gets no separate copy: its dates are rows of the plugin's own table, which a copy does
 *   not have. It is translated in place, see Builder::noCopyTypes().
 *
 * Checked against the code of Event Organiser 3.12 (the free plugin), in tests/EventOrganiserTest.php
 * with venues of the same shape, and by hand on a site with the plugin: its calendar, not its agenda
 * or its calendar widget, which load their events the same way. The plugin's pages of events, venues
 * and categories stay empty with a block theme, with or without SwiftLing; they were checked with a
 * classic theme.
 */
class EventOrganiser extends Builder {
	/**
	 * The plugin's type of post of an event.
	 */
	const EVENT = 'event';

	/**
	 * The plugin's taxonomy of venues.
	 */
	const VENUE = 'event-venue';

	/**
	 * Type of the plugin's venue fields for get_metadata().
	 */
	const META_TYPE = 'eo_venue';

	/**
	 * The fields of a venue that hold text a visitor reads. Its postcode and its position on the map
	 * are none.
	 */
	const VENUE_TEXTS = array( '_address', '_city', '_state', '_country', '_description' );

	/**
	 * The caches the plugin keeps of its calendar, its agenda and its calendar widget, each one
	 * transient.
	 */
	const CACHES = array( 'eo_full_calendar_public', 'eo_widget_agenda', 'eo_widget_calendar' );

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'event-organiser';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Event Organiser';
	}

	/**
	 * Whether the plugin is on the site: it names its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'EVENT_ORGANISER_VER' );
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
	 * Reads the venue fields, and leaves the plugin's caches of its calendar alone, in a page check and
	 * a page in a language.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		unset( $probe );
		if ( 'control' === $mode ) {
			return;
		}
		add_filter( 'get_' . self::META_TYPE . '_metadata', array( $this, 'filterVenueMeta' ), 100, 4 );
		foreach ( self::CACHES as $cache ) {
			add_filter( 'pre_transient_' . $cache, array( $this, 'noCache' ) );
			add_filter( 'eventorganiser_cache_expiration_' . $cache, array( $this, 'noExpiration' ) );
		}
	}

	/**
	 * Returns an empty cache of the plugin: the plugin keys what it keeps by the query only, so it would
	 * serve its calendar in another language, or one translated before the translation changed.
	 *
	 * @return array
	 */
	public function noCache(): array {
		return array();
	}

	/**
	 * Returns the time the plugin keeps a calendar it made: not at all, see noCache().
	 *
	 * @return int
	 */
	public function noExpiration(): int {
		return -1;
	}

	/**
	 * Returns the actions of admin-ajax.php at which the calendar, the agenda and the calendar widget
	 * load events from the page: answered in the language of that page, so the events' titles and
	 * links are in it.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'eventorganiser-fullcal', 'eo_widget_agenda', 'eo_widget_cal' );
	}

	/**
	 * Returns the plugin's type of post that gets no separate copy: the dates of an event are rows of
	 * the plugin's own table.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::EVENT );
	}

	/**
	 * Returns a field of a venue in the mode of the moment, as SwiftLing reads a field of a term: the
	 * venue is a term, and its fields are kept in the plugin's own table.
	 *
	 * @param mixed  $check     A value another filter returned, or null.
	 * @param int    $object_id Venue ID, the ID of its term.
	 * @param string $meta_key  Field name, or empty for all of them.
	 * @param bool   $single    Whether one value is asked for.
	 * @return mixed
	 */
	public function filterVenueMeta( $check, $object_id, $meta_key = '', $single = false ) {
		$state = &swiftling_state();
		if ( 'probe' === $state['mode'] && ! $state['collect'] && ! did_action( 'template_redirect' ) ) {
			return $check;
		}
		return swiftling_filter_meta( self::META_TYPE, $check, (int) $object_id, (string) $meta_key, (bool) $single );
	}

	/**
	 * Declares which fields of a venue hold text: the address and the description, never the postcode
	 * or the position on the map.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of no venue.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, 'term:' ) || 0 !== strpos( $path, 'meta/_' ) || self::VENUE !== swiftling_term_taxonomy_of( (int) substr( $owner, 5 ) ) ) {
			return null;
		}
		$key = explode( '/', substr( $path, 5 ) )[0];
		return in_array( $key, self::VENUE_TEXTS, true );
	}

	/**
	 * Returns the texts of an event's venue for its editor: its name, address and description, which
	 * have no editor of their own. Each is read as a page in a language reads it, so its object and path
	 * are the ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$post = 'post' === $subject['kind'] && isset( $subject['post'] ) && $subject['post'] instanceof \WP_Post ? $subject['post'] : null;
		if ( ! $post || self::EVENT !== $post->post_type ) {
			return array();
		}
		$venues = wp_get_object_terms( $post->ID, self::VENUE );
		if ( is_wp_error( $venues ) || ! $venues ) {
			return array();
		}
		$read  = function () use ( $venues ): void {
			add_filter( 'get_' . self::META_TYPE . '_metadata', array( $this, 'filterVenueMeta' ), 100, 4 );
			try {
				foreach ( $venues as $venue ) {
					swiftling_transform_text( 'term:' . $venue->term_id, 'field/name', 'term:name', $venue->name );
					foreach ( self::VENUE_TEXTS as $key ) {
						get_metadata( self::META_TYPE, $venue->term_id, $key, true );
					}
				}
			} finally {
				remove_filter( 'get_' . self::META_TYPE . '_metadata', array( $this, 'filterVenueMeta' ), 100 );
			}
		};
		$texts = array();
		foreach ( swiftling_collect( $read ) as $leaf ) {
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'field/name' === $leaf['p'] ? 'heading' : 'paragraph',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Returns what the editor calls a text of a venue, such as "Venue: Green Hall › Address".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is no venue's.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, 'term:' ) ) {
			return '';
		}
		$state          = &swiftling_state();
		$guard          = $state['guard'];
		$state['guard'] = true;
		try {
			$venue = get_term( (int) substr( $owner, 5 ), self::VENUE );
		} finally {
			$state['guard'] = $guard;
		}
		if ( ! $venue instanceof \WP_Term ) {
			return '';
		}
		/* translators: %s: name of a venue of an event. */
		$whole  = sprintf( __( 'Venue: %s', 'swiftling' ), $venue->name );
		$labels = array(
			'field/name'        => __( 'Name', 'swiftling' ),
			'meta/_address'     => __( 'Address', 'swiftling' ),
			'meta/_city'        => __( 'City', 'swiftling' ),
			'meta/_state'       => __( 'State', 'swiftling' ),
			'meta/_country'     => __( 'Country', 'swiftling' ),
			'meta/_description' => __( 'Description', 'swiftling' ),
		);
		foreach ( $labels as $prefix => $label ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return $whole . ' › ' . $label;
			}
		}
		return $whole;
	}
}
