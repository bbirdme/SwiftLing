<?php
/**
 * The Events Calendar: what of an event SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * The Events Calendar. An event is a post visitors open, and its title, content, excerpt and cost
 * reach the page through get_the_title(), the_content and get_post_meta(), where SwiftLing translates
 * them as on any post. What it does not find by itself:
 *
 * - The views show the excerpt of an event that the plugin makes from the stored excerpt or content,
 *   past get_the_excerpt(): it is made again from their translation, see translateExcerpt().
 * - The calendar file of an event or a view (iCal) writes the title of each event and the name of its
 *   organizer straight from the posts, see translateCalendarItem(), and the link that adds an event
 *   to Google Calendar its stored description, see translateGoogleDetails().
 * - A venue and an organizer are posts of types visitors never open on their own, so they have no
 *   editor of their own: the editor of each event that names them offers their name, description and
 *   the venue's address, see listTexts(). A translation shows wherever the venue or the organizer
 *   shows, with every event. The list, day and month views print the venue's name straight from the
 *   post, see translateVenue().
 * - The words the plugin's settings put before and after the calendar, and the formats of its dates,
 *   are read once a request into the plugin's own copy of its settings, before SwiftLing starts. They
 *   are a subject of their own on the Templates screen, and translated where the plugin hands each
 *   setting out, see translateOption(). The other settings, such as the slugs of the calendar's
 *   addresses, are never marked: the plugin builds its addresses from them.
 * - The fields of an event, a venue and an organizer that hold dates, times, time zones, phone
 *   numbers and choices are declared as no text, see fieldHoldsText(): the plugin reads its dates
 *   back from them.
 * - The calendar's addresses hold words the plugin translates, as /events/month/ or /events/monat/:
 *   its rules know the words of every language of the site, and an address shows the word of the
 *   language of its page, see addAddressWords(). The language switcher and the alternates of a view
 *   lead to the same view in each language, with its words, see currentAddress().
 * - The calendar loads another month, view or page of events from the page through its REST API: in
 *   the language of that page, see sendingRoutes().
 * - The plugin keeps the HTML of the month view in its cache, keyed by the locale and emptied only
 *   when an event changes: a page in a language or a page check renders it anew, see
 *   cacheOnlyOriginal().
 * - An event gets no separate copy: a copy has no dates in the plugin's own tables, so its page is
 *   not found. It is translated in place, see Builder::noCopyTypes().
 *
 * Checked against the code of The Events Calendar 6.18 (the free plugin), in
 * tests/TheEventsCalendarTest.php with posts and settings of the same shape, and by hand on a site
 * with the plugin, also with a domain for each language and a classic theme. Tickets are Event
 * Tickets', see EventTickets. Events Calendar Pro (series, recurring events, additional fields) was
 * not checked, nor a persistent object cache, in which the plugin keeps its events by their ID.
 */
class TheEventsCalendar extends Builder {
	/**
	 * Name of the option the plugin keeps its settings in.
	 */
	const OPTION = 'tribe_events_calendar_options';

	/**
	 * Key of the subject of the plugin's settings.
	 */
	const SETTINGS_SUBJECT = 'tec-settings';

	/**
	 * Post types of the plugin.
	 */
	const EVENT     = 'tribe_events';
	const VENUE     = 'tribe_venue';
	const ORGANIZER = 'tribe_organizer';

	/**
	 * The fields of a venue that hold text a visitor reads, by meta key.
	 */
	const VENUE_TEXTS = array( '_VenueAddress', '_VenueCity', '_VenueStateProvince', '_VenueProvince', '_VenueState', '_VenueCountry' );

	/**
	 * The fields of an event, a venue and an organizer that hold an address, left to the probe as any
	 * address. Every other field of theirs but the cost and the venue's address is a date, a time, a
	 * number, a choice, or the description the plugin also keeps as meta and never shows: it shows the
	 * post's content.
	 */
	const FIELD_ADDRESSES = array( '_EventURL', '_VenueURL', '_VenueWebsite', '_OrganizerWebsite' );

	/**
	 * The plugin's settings that hold text a visitor reads: the words before and after the calendar,
	 * and the formats of dates, which differ by language.
	 */
	const SETTINGS_TEXTS = array( 'tribeEventsBeforeHTML', 'tribeEventsAfterHTML', 'dateWithYearFormat', 'dateWithoutYearFormat', 'monthAndYearFormat', 'dateTimeSeparator', 'timeRangeSeparator' );

	/**
	 * Settings that are formats or separators: the plugin hands them to date(), so a marker in one
	 * would end up inside a date. They are offered on the subject of the settings only, never marked
	 * on a page.
	 */
	const FORMATS = array( 'dateWithYearFormat', 'dateWithoutYearFormat', 'monthAndYearFormat', 'dateTimeSeparator', 'timeRangeSeparator' );

	/**
	 * The words of the calendar's addresses the plugin translates, by the name of its base: the word in
	 * English and, for a word the plugin translates with a context, that context.
	 */
	const ADDRESS_WORDS = array(
		'month'    => array( 'month', '' ),
		'list'     => array( 'list', '' ),
		'today'    => array( 'today', '' ),
		'day'      => array( 'day', '' ),
		'tag'      => array( 'tag', '' ),
		'tax'      => array( 'category', '' ),
		'page'     => array( 'page', 'The "/page/" URL string component.' ),
		'featured' => array( 'featured', 'featured events slug' ),
	);

	/**
	 * The words of the calendar's addresses in each language of the site, by locale and base, see
	 * addressWords().
	 *
	 * @var array<string, array<string, string>>|null
	 */
	private $words = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'the-events-calendar';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'The Events Calendar';
	}

	/**
	 * Whether the plugin is on the site: it names its main file while its files load.
	 */
	public function isActive(): bool {
		return defined( 'TRIBE_EVENTS_FILE' );
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
	 * Hooks where the plugin hands out a venue, an organizer and a setting.
	 */
	public function register(): void {
		add_filter( 'tribe_get_venue_object_after', array( $this, 'translateVenue' ), 10, 1 );
		add_filter( 'tribe_get_organizer_object_after', array( $this, 'translateVenue' ), 10, 1 );
		add_filter( 'tribe_get_option', array( $this, 'translateOption' ), 10, 2 );
		add_filter( 'tribe_events_get_the_excerpt', array( $this, 'translateExcerpt' ), 10, 2 );
		add_filter( 'tribe_ical_feed_item', array( $this, 'translateCalendarItem' ), 10, 2 );
		add_filter( 'tec_views_v2_single_event_gcal_link_parameters', array( $this, 'translateGoogleDetails' ), 10, 2 );
		add_filter( 'tribe_events_views_v2_should_cache_html', array( $this, 'cacheOnlyOriginal' ), 100, 1 );
		add_filter( 'tribe_events_rewrite_i18n_slugs_raw', array( $this, 'addAddressWords' ), 20, 1 );
		add_filter( 'tec_common_rewrite_localize_matcher', array( $this, 'addressWord' ), 20, 2 );
		add_action( 'update_option_swiftling_languages', array( $this, 'forgetRules' ) );
		add_filter( 'swiftling_hreflangs', array( $this, 'viewAlternates' ), 10, 1 );
	}

	/**
	 * Whether the page being shown is a view of the calendar: a month, a list or a day of events, of
	 * all of them or of a category.
	 */
	private static function isView(): bool {
		return is_post_type_archive( self::EVENT ) || is_tax( 'tribe_events_cat' ) || ( is_archive() && '' !== (string) get_query_var( 'eventDisplay' ) );
	}

	/**
	 * Returns the address of a view of the calendar in a language with that language's words, as
	 * "/de/events/monat/2026-12/" for "/events/month/2026-12/". SwiftLing builds the address from the
	 * request, which has the words of the page's language.
	 *
	 * @param string $url  The address in that language.
	 * @param string $slug Address slug of the language, or an empty string for the original.
	 */
	public function currentAddress( string $url, string $slug ): string {
		if ( ! self::isView() ) {
			return $url;
		}
		$words = $this->addressWords();
		$from  = (string) swiftling_page_language()['locale'];
		$to    = '' === $slug ? (string) swiftling_languages()['source']['locale'] : '';
		foreach ( swiftling_languages()['targets'] as $target ) {
			if ( (string) $target['slug'] === $slug ) {
				$to = (string) $target['locale'];
			}
		}
		if ( ! isset( $words[ $from ], $words[ $to ] ) || $from === $to || ! preg_match( '~^((?:[a-z][a-z0-9+.\-]*:)?//[^/?#]*)?([^?#]*)(.*)$~is', $url, $parts ) ) {
			return $url;
		}
		$swap = array();
		foreach ( $words[ $from ] as $base => $word ) {
			$swap[ $word ] = $words[ $to ][ $base ];
		}
		$segments = explode( '/', $parts[2] );
		foreach ( $segments as $index => $segment ) {
			if ( isset( $swap[ $segment ] ) ) {
				$segments[ $index ] = $swap[ $segment ];
			}
		}
		return $parts[1] . implode( '/', $segments ) . $parts[3];
	}

	/**
	 * Returns the alternates of a view of the calendar in other languages: the same view, month or day
	 * in each, see currentAddress(). SwiftLing would name the calendar's first page for every view, as
	 * it names a content type's archive.
	 *
	 * @param mixed $links Address of the page in each language, by language tag.
	 * @return mixed
	 */
	public function viewAlternates( $links ) {
		if ( ! is_array( $links ) || ! self::isView() ) {
			return $links;
		}
		foreach ( swiftling_ordered_languages() as $language ) {
			$code = swiftling_hreflang_code( (string) $language['locale'] );
			if ( isset( $links[ $code ] ) ) {
				$links[ $code ] = swiftling_current_url( $language['source'] ? '' : (string) $language['slug'] );
			}
		}
		if ( isset( $links['x-default'] ) ) {
			$links['x-default'] = swiftling_current_url( '' );
		}
		return $links;
	}

	/**
	 * Returns the words of the calendar's addresses in every language of the site, the same in every
	 * request, whatever its language: "month" and "monat", in the order of the locales.
	 *
	 * The plugin builds its rules of addresses from the words in the language of the request that
	 * makes the rules, and later finds the rule an address of the calendar is made from by the words
	 * in the language of the request that makes the address. In a language other than the one the
	 * rules were made in, no rule matched and the address kept the rule's pattern, as
	 * "/de/events/(/?:month)/". With the words of every language in both, the rules are the same in
	 * every request, and an address in any language is understood in all of them.
	 *
	 * @param mixed $bases The words of each base, as the plugin made them: in English and in the
	 *                     language of the request.
	 * @return mixed
	 */
	public function addAddressWords( $bases ) {
		if ( ! is_array( $bases ) ) {
			return $bases;
		}
		foreach ( self::ADDRESS_WORDS as $base => $word ) {
			if ( ! isset( $bases[ $base ] ) || ! is_array( $bases[ $base ] ) ) {
				continue;
			}
			// The word in English, as the plugin has it, then the word in each language. The plugin's
			// own word in the language of the request is one of them, or a form of one that differs only
			// in case, as "Seite", which would give the rules another pattern in that language.
			$words = array( reset( $bases[ $base ] ) );
			foreach ( $this->addressWords() as $by_base ) {
				$words[] = $by_base[ $base ];
			}
			$bases[ $base ] = array_values( array_unique( array_filter( $words, 'strlen' ) ) );
		}
		return $bases;
	}

	/**
	 * Returns the word an address of the calendar shows for a base: the one of the language of the
	 * page. The plugin would take the last word it knows, of whichever language that is.
	 *
	 * @param mixed  $word The word the plugin chose, or null.
	 * @param string $base Name of the base, e.g. month.
	 * @return mixed
	 */
	public function addressWord( $word, $base = '' ) {
		$words = $this->addressWords();
		$here  = (string) swiftling_page_language()['locale'];
		return isset( $words[ $here ][ $base ] ) ? $words[ $here ][ $base ] : $word;
	}

	/**
	 * Returns the words of the calendar's addresses in each language of the site, by locale and base,
	 * as the plugin makes them: its translation in that locale, as an address segment. The translations
	 * are read from the plugin's files of each locale, without loading them: loading another locale's
	 * texts into WordPress would leave the page in that language.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function addressWords(): array {
		if ( null !== $this->words ) {
			return $this->words;
		}
		$languages = swiftling_languages();
		$locales   = array( (string) $languages['source']['locale'] );
		foreach ( $languages['targets'] as $target ) {
			$locales[] = (string) $target['locale'];
		}
		$locales = array_unique( $locales );
		sort( $locales );
		$this->words = array();
		foreach ( $locales as $locale ) {
			$file         = $this->languageFile( $locale );
			$translations = new \MO();
			if ( '' !== $file ) {
				$translations->import_from_file( $file );
			}
			foreach ( self::ADDRESS_WORDS as $base => $word ) {
				$text                            = '' === $word[1] ? $translations->translate( $word[0] ) : $translations->translate( $word[0], $word[1] );
				$this->words[ $locale ][ $base ] = sanitize_title( $text );
			}
		}
		return $this->words;
	}

	/**
	 * Returns the plugin's translation file of a locale: the one WordPress installs, or the plugin's own.
	 *
	 * @param string $locale Locale, e.g. de_DE.
	 * @return string Path, or empty when there is none.
	 */
	protected function languageFile( string $locale ): string {
		$candidates = array( WP_LANG_DIR . '/plugins/the-events-calendar-' . $locale . '.mo' );
		if ( defined( 'TRIBE_EVENTS_FILE' ) ) {
			$candidates[] = dirname( (string) constant( 'TRIBE_EVENTS_FILE' ) ) . '/lang/the-events-calendar-' . $locale . '.mo';
		}
		foreach ( $candidates as $file ) {
			if ( is_readable( $file ) ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Makes WordPress build its rules of addresses again on the next request, once the languages
	 * changed: the calendar's rules hold the words of every language, see addAddressWords(). Not in
	 * this request, which still knows the languages from before.
	 */
	public function forgetRules(): void {
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Whether the plugin keeps the HTML of a view in its cache, the month view: only in the original.
	 * The plugin keys the cache by the locale but empties it only when an event changes, so a page in
	 * a language would keep showing a translation from before it was changed; and a page check
	 * renders in the original's locale, so its markers would be served to visitors of the original.
	 *
	 * @param mixed $should Whether the plugin would keep it.
	 * @return mixed
	 */
	public function cacheOnlyOriginal( $should ) {
		return self::inMode() ? false : $should;
	}

	/**
	 * Returns the route of the plugin's REST API the calendar asks for another month, view or page of
	 * events, with the address of the page it shows: answered in the language of the page it was
	 * sent from, so its events, their links and the words around them are in it.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array( '#^/tribe/views/v2/html/?$#' );
	}

	/**
	 * Returns the plugin's type of post that gets no separate copy: a copy of an event has no dates in
	 * the plugin's tables, and its page is not found.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( self::EVENT );
	}

	/**
	 * Whether SwiftLing changes the request: a page check or a page in a language.
	 */
	private static function inMode(): bool {
		return in_array( swiftling_mode(), array( 'probe', 'translate' ), true );
	}

	/**
	 * Returns a venue or an organizer as the plugin hands it to its templates, with its name in the mode
	 * of the moment. The views print the name of a venue straight from the post. The post the plugin
	 * keeps in its cache stays as it is: this runs on every call, after the cache.
	 *
	 * @param mixed $post The venue or the organizer, a post with the plugin's properties.
	 * @return mixed
	 */
	public function translateVenue( $post ) {
		if ( ! $post instanceof \WP_Post || ! self::inMode() ) {
			return $post;
		}
		$title = swiftling_filter_title( $post->post_title, $post->ID );
		if ( ! is_string( $title ) || $title === $post->post_title ) {
			return $post;
		}
		$copy             = clone $post;
		$copy->post_title = $title;
		return $copy;
	}

	/**
	 * Returns the excerpt of an event the views show in a language: the plugin makes it from the stored
	 * excerpt, or from the start of the stored content, past get_the_excerpt(). It is made again the
	 * same way from the translation of either, when there is one.
	 *
	 * @param mixed $excerpt The excerpt as the plugin made it, as HTML.
	 * @param mixed $post    The event.
	 * @return mixed
	 */
	public function translateExcerpt( $excerpt, $post = null ) {
		if ( ! is_string( $excerpt ) || ! $post instanceof \WP_Post || 'translate' !== swiftling_mode() ) {
			return $excerpt;
		}
		$owner = swiftling_post_owner( $post->ID );
		if ( '' !== trim( $post->post_excerpt ) ) {
			$text = swiftling_transform( $owner, 'field/post_excerpt', 'field:post_excerpt', $post->post_excerpt );
			return is_string( $text ) && $text !== $post->post_excerpt ? wpautop( wp_kses_post( $text ) ) : $excerpt;
		}
		$text = swiftling_translate_post_content( $owner, $post->post_content );
		if ( ! is_string( $text ) || $text === $post->post_content ) {
			return $excerpt;
		}
		$text   = (string) preg_replace( '#\[.+\]#U', '', excerpt_remove_blocks( $text ) );
		$length = (int) apply_filters( 'excerpt_length', 55 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as the plugin applies it to the excerpt.
		$more   = (string) apply_filters( 'excerpt_more', ' [&hellip;]' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As above.
		return wpautop( wp_trim_words( wp_kses_post( $text ), $length, $more ) );
	}

	/**
	 * Returns an event as the calendar file of a page in a language lists it, with its title and the
	 * name of its organizer in that language: the plugin writes both straight from the posts. Its
	 * description, place and categories reach the file translated already.
	 *
	 * @param mixed $item  The lines of the event in the file, by name.
	 * @param mixed $event The event.
	 * @return mixed
	 */
	public function translateCalendarItem( $item, $event = null ) {
		if ( ! is_array( $item ) || ! $event instanceof \WP_Post || 'translate' !== swiftling_mode() ) {
			return $item;
		}
		$title = swiftling_filter_title( $event->post_title, $event->ID );
		if ( isset( $item['SUMMARY'] ) && is_string( $title ) && $title !== $event->post_title ) {
			$item['SUMMARY'] = 'SUMMARY:' . str_replace( array( ',', "\n", "\r" ), array( '\\,', '\\n', '' ), html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES ) );
		}
		// The plugin names the event's first organizer.
		$organizer = current(
			array_filter(
				$this->related( (int) $event->ID ),
				static function ( \WP_Post $item ): bool {
					return self::ORGANIZER === $item->post_type;
				}
			)
		);
		if ( isset( $item['ORGANIZER'] ) && is_string( $item['ORGANIZER'] ) && $organizer instanceof \WP_Post ) {
			$name = swiftling_filter_title( $organizer->post_title, $organizer->ID );
			if ( is_string( $name ) && $name !== $organizer->post_title ) {
				$item['ORGANIZER'] = str_replace( 'CN="' . self::calendarName( $organizer->post_title ) . '"', 'CN="' . self::calendarName( $name ) . '"', $item['ORGANIZER'] );
			}
		}
		return $item;
	}

	/**
	 * Returns what the link that adds an event to Google Calendar sends, with the event's description in
	 * the language of the page: the plugin writes the stored content into the link. Its title and place
	 * are in the language already.
	 *
	 * @param mixed $pieces What the link sends, by name.
	 * @param mixed $event  The event.
	 * @return mixed
	 */
	public function translateGoogleDetails( $pieces, $event = null ) {
		if ( ! is_array( $pieces ) || empty( $pieces['details'] ) || ! $event instanceof \WP_Post || 'translate' !== swiftling_mode() || '' === trim( (string) $event->post_content ) ) {
			return $pieces;
		}
		$content = swiftling_translate_post_content( swiftling_post_owner( $event->ID ), $event->post_content );
		if ( ! is_string( $content ) || $content === $event->post_content ) {
			return $pieces;
		}
		$details = (string) preg_replace( '/(<!-- .* \/?-->)/m', '', $content );
		$link    = 'Tribe\\Events\\Views\\V2\\iCalendar\\Links\\Google_Calendar';
		$details = function_exists( 'tribe' ) && class_exists( $link ) ? (string) tribe( $link )->format_event_details_for_url( $details, $event, 996 ) : str_replace( '</p>', '</p> ', $details );

		$pieces['details'] = urlencode( $details ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- The plugin encodes the description so, with + for a space.
		return $pieces;
	}

	/**
	 * Returns a name as the plugin writes it into a calendar file: without the characters the format
	 * does not allow there, and with single quotes for double ones.
	 *
	 * @param string $name The name.
	 */
	private static function calendarName( string $name ): string {
		$allowed = array( ' ', '&', '!', '?', "'", '*', '(', ')', '@', '#', '$', '%', '^', '+', '=', '{', '}', '[', ']', '|', '\\', '/', '<', '>', '`', '~' );
		$encoded = array_map( 'rawurlencode', $allowed );

		$encoded[] = rawurlencode( '"' );
		$allowed[] = "'";
		return str_replace( $encoded, $allowed, rawurlencode( $name ) );
	}

	/**
	 * Returns a setting of the plugin in the mode of the moment: in a language its translation, in a
	 * page check with a marker, as SwiftLing reads any option. The plugin reads its settings once a
	 * request, before SwiftLing starts, so they never pass get_option() while the page renders.
	 *
	 * Only the settings that hold text are read so, and a format never gets a marker, see FORMATS.
	 *
	 * @param mixed  $value The setting, as the plugin hands it out.
	 * @param string $name  Its name.
	 * @return mixed
	 */
	public function translateOption( $value, $name = '' ) {
		$name = (string) $name;
		if ( ! is_string( $value ) || '' === trim( $value ) || ! in_array( $name, self::SETTINGS_TEXTS, true ) ) {
			return $value;
		}
		$mode = swiftling_mode();
		if ( 'translate' !== $mode && ( 'probe' !== $mode || in_array( $name, self::FORMATS, true ) || ! did_action( 'template_redirect' ) ) ) {
			return $value;
		}
		// The whole option is walked, so the value has the path and the rule key it has when the
		// subject of the settings lists it.
		$walked = swiftling_transform( 'options', 'option/' . self::OPTION, 'option:' . self::OPTION, array( $name => $value ) );
		return is_array( $walked ) && isset( $walked[ $name ] ) && is_string( $walked[ $name ] ) ? $walked[ $name ] : $value;
	}

	/**
	 * Declares which fields of the plugin hold text: of its settings, those in SETTINGS_TEXTS, and
	 * none of the others, such as the slugs its addresses are built from; of an event, a venue and an
	 * organizer, the cost, the description and the address, and none of the dates, times, time zones,
	 * phone numbers and choices, which the plugin reads back as they are.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for values of no field of the plugin.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 'options' === $owner ) {
			$prefix = 'option/' . self::OPTION;
			if ( $path === $prefix ) {
				return null;
			}
			if ( 0 !== strpos( $path, $prefix . '/' ) ) {
				return null;
			}
			$key = explode( '/', substr( $path, strlen( $prefix ) + 1 ) )[0];
			if ( ! in_array( $key, self::SETTINGS_TEXTS, true ) ) {
				return false;
			}
			// A format is read into date() wherever the plugin wants it, so a page check never marks
			// it; the subject of the settings lists it all the same.
			$state = &swiftling_state();
			return ! in_array( $key, self::FORMATS, true ) || 'probe' !== $state['mode'] || $state['collect'];
		}
		if ( 0 !== strpos( $path, 'meta/' ) || ! preg_match( '#^meta/(_(?:Event|Venue|Organizer)[A-Za-z]*)(?:/|$)#', $path, $found ) ) {
			return null;
		}
		$key = $found[1];
		if ( '_EventCost' === $key || in_array( $key, self::VENUE_TEXTS, true ) ) {
			return true;
		}
		return in_array( $key, self::FIELD_ADDRESSES, true ) ? null : false;
	}

	/**
	 * Returns a subject of the editor: the plugin's settings that hold text.
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
			'title' => __( 'The Events Calendar settings', 'swiftling' ),
			'type'  => __( 'Settings', 'swiftling' ),
			'url'   => '',
			'probe' => false,
			'post'  => null,
			'cap'   => 'manage_options',
			'note'  => __( 'The words the calendar shows before and after its events, and the formats of its dates, which differ from language to language.', 'swiftling' ),
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
				'title'       => __( 'The Events Calendar', 'swiftling' ),
				'description' => __( 'Texts the calendar keeps in its settings.', 'swiftling' ),
				'subjects'    => array(
					array(
						'key'         => self::SETTINGS_SUBJECT,
						'title'       => __( 'Settings', 'swiftling' ),
						'description' => __( 'Before and after the calendar, formats of dates', 'swiftling' ),
					),
				),
			),
		);
	}

	/**
	 * Returns the texts the plugin keeps outside the page of an event, for its editor: the name,
	 * description and address of its venue and the name and description of its organizers, which
	 * have no editor of their own. For the subject of the settings, the settings that hold text.
	 * Each text is read as a page in a language reads it, so its object and path are the ones
	 * translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		if ( self::SETTINGS_SUBJECT === $subject['key'] ) {
			return $this->collect(
				static function (): void {
					$stored = get_option( self::OPTION );
					// A format no one changed is not saved: the plugin uses its default, which is
					// offered under the same place a saved one would have.
					$defaults = array(
						'dateWithYearFormat'    => (string) swiftling_raw_option( 'date_format', 'F j, Y' ),
						'dateWithoutYearFormat' => 'F j',
						'monthAndYearFormat'    => 'F Y',
					);
					$missing  = array_diff_key( $defaults, is_array( $stored ) ? array_filter( $stored, 'is_string' ) : array() );
					if ( $missing ) {
						swiftling_transform( 'options', 'option/' . self::OPTION, 'option:' . self::OPTION, $missing );
					}
				}
			);
		}
		$post = 'post' === $subject['kind'] && isset( $subject['post'] ) && $subject['post'] instanceof \WP_Post ? $subject['post'] : null;
		if ( ! $post || self::EVENT !== $post->post_type ) {
			return array();
		}
		$related = $this->related( (int) $post->ID );
		return $this->collect(
			static function () use ( $related ): void {
				foreach ( $related as $item ) {
					swiftling_filter_title( $item->post_title, $item->ID );
					if ( '' !== trim( $item->post_content ) ) {
						swiftling_translate_post_content( swiftling_post_owner( $item->ID ), $item->post_content );
					}
					foreach ( self::VENUE === $item->post_type ? self::VENUE_TEXTS : array() as $key ) {
						get_post_meta( $item->ID, $key, true );
					}
				}
			}
		);
	}

	/**
	 * Returns the venue and the organizers of an event, as stored.
	 *
	 * @param int $event_id Event ID.
	 * @return \WP_Post[]
	 */
	private function related( int $event_id ): array {
		$state          = &swiftling_state();
		$guard          = $state['guard'];
		$state['guard'] = true;
		try {
			$ids = array_merge( (array) get_post_meta( $event_id, '_EventVenueID', false ), (array) get_post_meta( $event_id, '_EventOrganizerID', false ) );
		} finally {
			$state['guard'] = $guard;
		}
		$posts = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$item = get_post( $id );
			if ( $item instanceof \WP_Post && in_array( $item->post_type, array( self::VENUE, self::ORGANIZER ), true ) ) {
				$posts[] = $item;
			}
		}
		return $posts;
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
			if ( 'options' === $leaf['o'] && true !== $this->fieldHoldsText( 'options', $leaf['p'] ) ) {
				continue;
			}
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
	 * Returns what the editor calls a text of a venue, an organizer or the settings, such as
	 * "Venue: Green Hall › Address".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is none of these.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 'options' === $owner ) {
			$prefix = 'option/' . self::OPTION . '/';
			$key    = 0 === strpos( $path, $prefix ) ? explode( '/', substr( $path, strlen( $prefix ) ) )[0] : '';
			return in_array( $key, self::SETTINGS_TEXTS, true ) ? self::settingLabel( $key ) : '';
		}
		if ( 0 !== strpos( $owner, 'post:' ) ) {
			return '';
		}
		$post = get_post( (int) substr( $owner, 5 ) );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( self::VENUE, self::ORGANIZER ), true ) ) {
			return '';
		}
		$name = (string) $post->post_title;
		/* translators: %s: name of a venue of an event. */
		$venue = __( 'Venue: %s', 'swiftling' );
		/* translators: %s: name of an organizer of an event. */
		$organizer = __( 'Organizer: %s', 'swiftling' );
		$whole     = sprintf( self::VENUE === $post->post_type ? $venue : $organizer, $name );
		if ( 'field/post_title' === $path ) {
			return $whole . ' › ' . __( 'Name', 'swiftling' );
		}
		if ( 0 === strpos( $path, 'field/post_content' ) ) {
			return $whole . ' › ' . __( 'Description', 'swiftling' );
		}
		foreach ( self::VENUE_TEXTS as $key ) {
			if ( 0 === strpos( $path, 'meta/' . $key ) ) {
				return $whole . ' › ' . self::venueLabel( $key );
			}
		}
		return $whole;
	}

	/**
	 * Returns what the editor calls a field of a venue's address.
	 *
	 * @param string $key Meta key.
	 */
	private static function venueLabel( string $key ): string {
		switch ( $key ) {
			case '_VenueAddress':
				return __( 'Address', 'swiftling' );
			case '_VenueCity':
				return __( 'City', 'swiftling' );
			case '_VenueCountry':
				return __( 'Country', 'swiftling' );
			case '_VenueProvince':
				return __( 'Province', 'swiftling' );
			case '_VenueState':
				return __( 'State', 'swiftling' );
			default:
				return __( 'State or province', 'swiftling' );
		}
	}

	/**
	 * Returns what the editor calls a setting of the plugin.
	 *
	 * @param string $key Name of the setting.
	 */
	private static function settingLabel( string $key ): string {
		switch ( $key ) {
			case 'tribeEventsBeforeHTML':
				return __( 'Before the calendar', 'swiftling' );
			case 'tribeEventsAfterHTML':
				return __( 'After the calendar', 'swiftling' );
			case 'dateWithYearFormat':
				return __( 'Format of a date with the year', 'swiftling' );
			case 'dateWithoutYearFormat':
				return __( 'Format of a date without the year', 'swiftling' );
			case 'monthAndYearFormat':
				return __( 'Format of a month and year', 'swiftling' );
			case 'dateTimeSeparator':
				return __( 'Between the date and the time', 'swiftling' );
			default:
				return __( 'Between the start and the end time', 'swiftling' );
		}
	}
}
