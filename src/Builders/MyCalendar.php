<?php
/**
 * My Calendar: what of an event SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * My Calendar. An event and a location are rows of the plugin's own tables, each mirrored by a post
 * visitors can open, which holds the event's title, description and short description. The plugin
 * prints an event from its row, through template tags it makes for each event. What SwiftLing does
 * not find by itself:
 *
 * - The plugin makes the template tags of an event from its row and its location, past get_the_title()
 *   and the_content. While it makes them, the event's title, description, short description and
 *   registration information, the name and address of its location and the names of its categories
 *   are in the mode of the moment, under the paths of the mirroring posts and terms, see startTags(),
 *   and so they are while the plugin draws the event, whose templates read the row itself. Once the
 *   tags are made and the event drawn, the row is as stored again, see endTags(): the plugin keeps
 *   rows in caches of its own, by the event only. On an event's page the plugin puts the row's title
 *   in place of the post's, see translateTitle().
 * - A location's page prints its address from the location's row, as its map and its structured
 *   data do, see translateLocationPage() and translateLocationSchema().
 * - The names of an event's categories are terms of the plugin's taxonomy, whose pages show events
 *   but not the name: the editor of the event offers them, see listTexts(), and the calendar's legend
 *   prints them from the plugin's table, see translateCategories().
 * - The list of upcoming events loads more events, and the search its locations, from the page
 *   through admin-ajax.php: in the language of that page, see sendingActions(). The calendar moves to
 *   another month by loading its own page again, in the language already.
 * - An event and a location get no separate copy: a copy has no row in the plugin's tables. They are
 *   translated in place, see Builder::noCopyTypes().
 *
 * Checked against the code of My Calendar 3.8 (the free plugin), in tests/MyCalendarTest.php, and by
 * hand on a site with the plugin.
 */
class MyCalendar extends Builder {
	/**
	 * Where the texts of an event's row and a location's address are in the maps of their posts.
	 */
	const BASE = 'my-calendar';

	/**
	 * Source of such a text, for its rule key.
	 */
	const SOURCE = 'my-calendar:row';

	/**
	 * The plugin's types of post that mirror an event and a location.
	 */
	const EVENT    = 'mc-events';
	const LOCATION = 'mc-locations';

	/**
	 * The fields of a location's address that hold text. Its postcode, country code, phone numbers and
	 * position on the map are none.
	 */
	const ADDRESS = array( 'location_street', 'location_street2', 'location_city', 'location_state', 'location_region' );

	/**
	 * What the rows showed while their tags were made or the event was drawn, to put back: each an
	 * object, a field and the value it had, in the order they were changed, by the event's object.
	 *
	 * @var array<int, array<int, array<int, array{0: object, 1: string, 2: mixed}>>>
	 */
	private $changed = array();

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'my-calendar';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'My Calendar';
	}

	/**
	 * Whether the plugin is on the site.
	 */
	public function isActive(): bool {
		return function_exists( 'mc_event_object' );
	}

	/**
	 * Whether a post is built with the plugin. An event's post is read as any other.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- An event needs nothing of a builder.
		return false;
	}

	/**
	 * Hooks where the plugin makes the template tags of an event, and the page of a location.
	 */
	public function register(): void {
		add_action( 'mc_create_tags', array( $this, 'startTags' ), 10, 1 );
		add_action( 'mc_tags_created', array( $this, 'endTags' ), 10, 1 );
		// The plugin's templates read the event's row itself while it draws the event.
		add_action( 'my_calendar_drawing_event', array( $this, 'startTags' ), 10, 1 );
		add_action( 'my_calendar_event_drawn', array( $this, 'endTags' ), 10, 1 );
		// After the plugin's own (10), which puts the event's title as stored in place of the post's.
		add_filter( 'the_title', array( $this, 'translateTitle' ), 11, 2 );
		add_filter( 'mc_display_location_events', array( $this, 'translateLocationPage' ), 10, 2 );
		add_filter( 'mc_location_schema', array( $this, 'translateLocationSchema' ), 10, 2 );
		add_filter( 'mc_category_key_array', array( $this, 'translateCategories' ), 10, 1 );
	}

	/**
	 * Returns the actions of admin-ajax.php at which the list of upcoming events loads more, and the
	 * search of locations: answered in the language of the page they were sent from.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array( 'mcjs_action', 'mc_core_autocomplete_search_locations' );
	}

	/**
	 * Returns the plugin's types of post that get no separate copy: a copy has no row in the plugin's
	 * tables.
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
	 * Puts the texts of an event's row, its location and its categories in the mode of the moment while
	 * the plugin makes the event's template tags.
	 *
	 * @param mixed $event The event's row.
	 */
	public function startTags( $event ): void {
		if ( ! is_object( $event ) || ! self::inMode() || empty( $event->event_post ) ) {
			return;
		}
		$post_id = (int) $event->event_post;
		$owner   = swiftling_post_owner( $post_id );
		$changes = array();

		$changes[] = array( $event, 'event_title', swiftling_filter_title( (string) $event->event_title, $post_id ) );
		if ( isset( $event->event_desc ) && '' !== trim( (string) $event->event_desc ) ) {
			$changes[] = array( $event, 'event_desc', swiftling_translate_post_content( $owner, (string) $event->event_desc ) );
		}
		if ( isset( $event->event_short ) && '' !== trim( (string) $event->event_short ) ) {
			$changes[] = array( $event, 'event_short', swiftling_transform( $owner, 'field/post_excerpt', 'field:post_excerpt', (string) $event->event_short ) );
		}
		if ( isset( $event->event_registration ) && '' !== trim( (string) $event->event_registration ) ) {
			$changes[] = array( $event, 'event_registration', swiftling_transform_text( $owner, self::BASE . '/event_registration', self::SOURCE, (string) $event->event_registration ) );
		}
		if ( isset( $event->category_name, $event->category_term ) && (int) $event->category_term ) {
			$changes[] = array( $event, 'category_name', swiftling_transform_text( 'term:' . (int) $event->category_term, 'field/name', 'term:name', (string) $event->category_name ) );
		}
		foreach ( isset( $event->categories ) && is_array( $event->categories ) ? $event->categories : array() as $category ) {
			if ( is_object( $category ) && isset( $category->category_name, $category->category_term ) && (int) $category->category_term ) {
				$changes[] = array( $category, 'category_name', swiftling_transform_text( 'term:' . (int) $category->category_term, 'field/name', 'term:name', (string) $category->category_name ) );
			}
		}
		$location = isset( $event->location ) && is_object( $event->location ) ? $event->location : ( ! empty( $event->event_location ) && function_exists( 'mc_get_location' ) ? mc_get_location( (int) $event->event_location ) : null );
		if ( is_object( $location ) ) {
			$changes = array_merge( $changes, self::locationChanges( $location ) );
		}

		$stored = array();
		foreach ( $changes as $change ) {
			list( $item, $field, $text ) = $change;
			if ( is_string( $text ) && $text !== $item->$field ) {
				$stored[]     = array( $item, $field, $item->$field );
				$item->$field = $text;
			}
		}
		// The plugin makes the tags of an event while it draws it: the same event is started twice.
		$this->changed[ spl_object_id( $event ) ][] = $stored;
	}

	/**
	 * Puts back what the rows held once the plugin made the event's template tags, so a row the plugin
	 * keeps in its cache, or saves, is as stored.
	 *
	 * @param mixed $event The event's row.
	 */
	public function endTags( $event ): void {
		$id = is_object( $event ) ? spl_object_id( $event ) : 0;
		if ( ! $id || empty( $this->changed[ $id ] ) ) {
			return;
		}
		foreach ( array_reverse( (array) array_pop( $this->changed[ $id ] ) ) as $change ) {
			list( $item, $field, $value ) = $change;
			$item->$field                 = $value;
		}
		if ( ! $this->changed[ $id ] ) {
			unset( $this->changed[ $id ] );
		}
	}

	/**
	 * Returns the title of an event's page in the mode of the moment: the plugin puts the title of the
	 * event's row, as stored, in place of the post's, after SwiftLing translated the post's.
	 *
	 * @param mixed $title   The title, as the plugin made it.
	 * @param mixed $post_id Post ID.
	 * @return mixed
	 */
	public function translateTitle( $title, $post_id = 0 ) {
		$post = $post_id ? get_post( (int) $post_id ) : null;
		if ( ! is_string( $title ) || ! $post instanceof \WP_Post || self::EVENT !== $post->post_type || ! self::inMode() ) {
			return $title;
		}
		$stored = (string) $post->post_title;
		$shown  = swiftling_filter_title( $stored, $post->ID );
		if ( '' === $stored || ! is_string( $shown ) || $shown === $stored || false === strpos( $title, $stored ) ) {
			return $title;
		}
		return str_replace( $stored, $shown, $title );
	}

	/**
	 * Returns the changes that put a location's name and address in the mode of the moment.
	 *
	 * @param object $location The location's row.
	 * @return array<int, array{0: object, 1: string, 2: mixed}>
	 */
	private static function locationChanges( $location ): array {
		$post_id = isset( $location->location_post ) ? (int) $location->location_post : 0;
		if ( ! $post_id ) {
			return array();
		}
		$owner   = swiftling_post_owner( $post_id );
		$changes = array();
		if ( isset( $location->location_label ) ) {
			$changes[] = array( $location, 'location_label', swiftling_filter_title( (string) $location->location_label, $post_id ) );
		}
		foreach ( self::ADDRESS as $field ) {
			if ( isset( $location->$field ) && '' !== trim( (string) $location->$field ) ) {
				$changes[] = array( $location, $field, swiftling_transform_text( $owner, self::BASE . '/' . $field, self::SOURCE, (string) $location->$field ) );
			}
		}
		return $changes;
	}

	/**
	 * Puts the location a location's page prints in the mode of the moment: the plugin reads it once
	 * for its address, its map and its events, and hands it to this filter first. The plugin's cache of
	 * locations between requests was written before, as stored.
	 *
	 * @param mixed $args     What the page lists of the location's events.
	 * @param mixed $location The location's row.
	 * @return mixed
	 */
	public function translateLocationPage( $args, $location = null ) {
		if ( is_object( $location ) && self::inMode() ) {
			foreach ( self::locationChanges( $location ) as $change ) {
				list( $item, $field, $text ) = $change;
				if ( is_string( $text ) ) {
					$item->$field = $text;
				}
			}
		}
		return $args;
	}

	/**
	 * Returns the structured data of a location's page with its name and address in the mode of the
	 * moment: the plugin makes it from the location's row in the head of the page.
	 *
	 * @param mixed $schema   The structured data.
	 * @param mixed $location The location's row.
	 * @return mixed
	 */
	public function translateLocationSchema( $schema, $location = null ) {
		if ( ! is_array( $schema ) || ! is_object( $location ) || ! self::inMode() ) {
			return $schema;
		}
		$shown = clone $location;
		foreach ( self::locationChanges( $shown ) as $change ) {
			list( $item, $field, $text ) = $change;
			if ( is_string( $text ) ) {
				$item->$field = $text;
			}
		}
		$places = array(
			'location_label'  => array( 'name' ),
			'location_street' => array( 'address', 'streetAddress' ),
			'location_city'   => array( 'address', 'addressLocality' ),
			'location_state'  => array( 'address', 'addressRegion' ),
		);
		foreach ( $places as $field => $place ) {
			if ( 1 === count( $place ) && isset( $schema[ $place[0] ] ) && $schema[ $place[0] ] === $location->$field ) {
				$schema[ $place[0] ] = $shown->$field;
			} elseif ( 2 === count( $place ) && isset( $schema[ $place[0] ][ $place[1] ] ) && $schema[ $place[0] ][ $place[1] ] === $location->$field ) {
				$schema[ $place[0] ][ $place[1] ] = $shown->$field;
			}
		}
		return $schema;
	}

	/**
	 * Returns the categories of the calendar's legend with their names in the mode of the moment: the
	 * plugin reads them from its own table, each with the term that mirrors it.
	 *
	 * @param mixed $categories The categories' rows.
	 * @return mixed
	 */
	public function translateCategories( $categories ) {
		if ( ! is_array( $categories ) || ! self::inMode() ) {
			return $categories;
		}
		foreach ( $categories as $index => $category ) {
			if ( is_object( $category ) && isset( $category->category_name, $category->category_term ) && (int) $category->category_term ) {
				$shown                = clone $category;
				$shown->category_name = swiftling_transform_text( 'term:' . (int) $category->category_term, 'field/name', 'term:name', (string) $category->category_name );
				$categories[ $index ] = $shown;
			}
		}
		return $categories;
	}

	/**
	 * Declares the texts of an event's row and of a location's address as text: one word, as a city,
	 * is one.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for everything else.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		return 0 === strpos( $owner, 'post:' ) && 0 === strpos( $path, self::BASE . '/' ) ? true : null;
	}

	/**
	 * Returns what the editor calls a text of an event's row or a location's address.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is none of these.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		unset( $owner );
		$labels = array(
			'event_registration' => __( 'Registration information', 'swiftling' ),
			'location_street'    => __( 'Address', 'swiftling' ),
			'location_street2'   => __( 'Address', 'swiftling' ),
			'location_city'      => __( 'City', 'swiftling' ),
			'location_state'     => __( 'State', 'swiftling' ),
			'location_region'    => __( 'Region', 'swiftling' ),
		);
		foreach ( $labels as $field => $label ) {
			if ( self::BASE . '/' . $field === $path ) {
				return $label;
			}
		}
		return '';
	}

	/**
	 * Returns the registration information of an event for its editor, which shows only on the event's
	 * own view, and the names of its categories. Read as a page in a language reads them, so their
	 * objects and paths are the ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$post = 'post' === $subject['kind'] && isset( $subject['post'] ) && $subject['post'] instanceof \WP_Post ? $subject['post'] : null;
		if ( ! $post || self::EVENT !== $post->post_type || ! function_exists( 'mc_get_first_event' ) ) {
			return array();
		}
		$event = mc_get_first_event( (int) swiftling_raw_post_meta( $post->ID, '_mc_event_id' ), true );
		if ( ! is_object( $event ) ) {
			return array();
		}
		$owner = swiftling_post_owner( $post->ID );
		$terms = wp_get_object_terms( $post->ID, 'mc-event-category' );
		$texts = array();
		$read  = static function () use ( $owner, $event, $terms ): void {
			if ( '' !== trim( (string) $event->event_registration ) ) {
				swiftling_transform_text( $owner, self::BASE . '/event_registration', self::SOURCE, (string) $event->event_registration );
			}
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				swiftling_transform_text( 'term:' . $term->term_id, 'field/name', 'term:name', $term->name );
			}
		};
		foreach ( swiftling_collect( $read ) as $leaf ) {
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'paragraph',
				'place' => 'field/name' === $leaf['p'] ? __( 'Category', 'swiftling' ) : $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}
}
