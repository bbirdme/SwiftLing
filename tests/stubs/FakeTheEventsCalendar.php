<?php
/**
 * The Events Calendar without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\TheEventsCalendar;

/**
 * The Events Calendar, without the plugin: active, with the post types and the category the plugin
 * registers, translation files of its own for the words of the calendar's addresses, and everything
 * else of the real class.
 */
class FakeTheEventsCalendar extends TheEventsCalendar {
	/**
	 * The words of the calendar's addresses the plugin's translation file of each locale has.
	 */
	const WORDS = array(
		'hr'    => array(
			'month' => 'mjesec',
			'list'  => 'popis',
		),
		'de_DE' => array(
			'month' => 'Monat',
			'list'  => 'Liste',
			'page'  => 'Seite',
		),
		'fr_FR' => array(
			'month' => 'mois',
			'list'  => 'liste',
		),
	);

	/**
	 * Translation files made for the test, by locale.
	 *
	 * @var array<string, string>
	 */
	private $files = array();

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Returns the translation file the test made for a locale.
	 *
	 * @param string $locale Locale.
	 */
	protected function languageFile( string $locale ): string {
		if ( ! isset( self::WORDS[ $locale ] ) ) {
			return '';
		}
		if ( ! isset( $this->files[ $locale ] ) ) {
			$translations = new \MO();
			foreach ( self::WORDS[ $locale ] as $word => $translation ) {
				$entry = array(
					'singular'     => $word,
					'translations' => array( $translation ),
				);
				if ( 'page' === $word ) {
					$entry['context'] = 'The "/page/" URL string component.';
				}
				$translations->add_entry( new \Translation_Entry( $entry ) );
			}
			$file = wp_tempnam( 'the-events-calendar-' . $locale . '.mo' );
			$translations->export_to_file( $file );
			$this->files[ $locale ] = $file;
		}
		return $this->files[ $locale ];
	}

	/**
	 * Removes the translation files the test made.
	 */
	public function removeFiles(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		$this->files = array();
	}

	/**
	 * Registers the plugin's post types and category as The Events Calendar 6.18 does: visitors open
	 * events, never venues or organizers.
	 */
	public static function install(): void {
		register_post_type(
			self::EVENT,
			array(
				'public'      => true,
				'has_archive' => 'events',
				'rewrite'     => array( 'slug' => 'event' ),
			)
		);
		register_post_type( self::VENUE, array( 'public' => false ) );
		register_post_type( self::ORGANIZER, array( 'public' => false ) );
		register_taxonomy( 'tribe_events_cat', self::EVENT, array( 'public' => true ) );
	}
}
