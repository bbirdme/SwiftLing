<?php
/**
 * Tests of the log.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use WP_REST_Request;

/**
 * The log keeps what failed and what SwiftLing did, never content or secrets, for a while, and
 * shows it on its screen.
 */
class LogTest extends TestCase {

	/**
	 * Returns every entry of the log, the newest first.
	 *
	 * @return object[]
	 */
	private function entries() {
		return swiftling_log_entries( array( 'per_page' => 5000 ) )['entries'];
	}

	/**
	 * Sends a request to a route of SwiftLing.
	 *
	 * @param string $route  Route.
	 * @param array  $params Body.
	 * @return \WP_REST_Response
	 */
	private function call( $route, $params ) {
		$request = new WP_REST_Request( 'POST', '/swiftling/v1' . $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Renders the Log screen.
	 *
	 * @param array $get Query of the screen.
	 * @return string
	 */
	private function screen( $get = array() ) {
		$this->become( 'administrator' );
		$_GET = $get;
		return $this->render_screen( 'log' );
	}

	/**
	 * An entry is kept with its level, area, message, detail and object, and the log counts its
	 * levels and finds entries by level, area and words.
	 */
	public function test_entries_are_kept_and_found() {
		$this->assertTrue(
			swiftling_log(
				'error',
				'service',
				'Google refused the key.',
				array(
					'detail' => 'google · 403',
					'object' => 'post:12',
				)
			)
		);
		swiftling_log( 'warning', 'addresses', 'The address “lavanda” is taken in Deutsch.' );
		swiftling_log( 'event', 'all', 'Deutsch finished.' );
		$this->assertFalse( swiftling_log( 'loud', 'service', 'No such level.' ) );
		$this->assertFalse( swiftling_log( 'event', 'service', '   ' ), 'Nothing to say' );

		$all = swiftling_log_entries();
		$this->assertSame( 3, $all['shown'] );
		$this->assertSame(
			array(
				'error'   => 1,
				'warning' => 1,
				'event'   => 1,
			),
			$all['counts']
		);
		$this->assertSame( 'Deutsch finished.', $all['entries'][0]->message, 'The newest first' );
		$this->assertSame( 'google · 403', $all['entries'][2]->detail );
		$this->assertSame( 'post:12', $all['entries'][2]->object_key );

		$this->assertSame( 1, swiftling_log_entries( array( 'level' => 'warning' ) )['shown'] );
		$this->assertSame( 1, swiftling_log_entries( array( 'area' => 'all' ) )['shown'] );
		$this->assertSame( 1, swiftling_log_entries( array( 'search' => 'lavanda' ) )['shown'] );
		$this->assertSame( 1, swiftling_log_entries( array( 'search' => 'post:12' ) )['shown'] );
	}

	/**
	 * Keys, tokens, email addresses and IP addresses never reach the log, should a message carry them.
	 */
	public function test_secrets_and_visitors_data_are_left_out() {
		swiftling_log(
			'error',
			'service',
			'Request to https://translation.googleapis.com/?key=AIzaSyA-1234567890abcdefghijklmnopqrstu failed for ana@apartmani-marija.hr from 192.168.1.20.',
			array( 'detail' => 'Bearer abc.def-123 · sk-proj-ABCDEFGHIJKLMNOPqrstu' )
		);
		$entry = $this->entries()[0];
		foreach ( array( 'AIzaSy', 'ana@apartmani-marija.hr', '192.168.1.20', 'abc.def-123', 'sk-proj-ABCDEFGHIJKLMNOPqrstu' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $entry->message . ' ' . $entry->detail, $secret );
		}
		$this->assertStringContainsString( '[email]', $entry->message );
		$this->assertStringContainsString( '[ip]', $entry->message );
	}

	/**
	 * The same entry again within ten minutes counts as a repeat; another object, or the same entry
	 * later, is an entry of its own.
	 */
	public function test_repeats() {
		swiftling_log( 'error', 'service', 'Quota exceeded.', array( 'object' => 'post:1' ) );
		swiftling_log( 'error', 'service', 'Quota exceeded.', array( 'object' => 'post:1' ) );
		swiftling_log( 'error', 'service', 'Quota exceeded.', array( 'object' => 'post:1' ) );
		$this->assertCount( 1, $this->entries() );
		$this->assertSame( 3, (int) $this->entries()[0]->repeats );

		swiftling_log( 'error', 'service', 'Quota exceeded.', array( 'object' => 'post:2' ) );
		$this->assertCount( 2, $this->entries() );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET logged = logged - %d', swiftling_table( 'log' ), SWIFTLING_LOG_REPEAT_WINDOW + 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As if the entries were older.
		swiftling_log( 'error', 'service', 'Quota exceeded.', array( 'object' => 'post:2' ) );
		$this->assertCount( 3, $this->entries() );
	}

	/**
	 * The log never holds more than its limit: the oldest go as soon as a new one comes.
	 */
	public function test_never_more_than_the_limit() {
		for ( $i = 1; $i <= SWIFTLING_LOG_LIMIT + 5; $i++ ) {
			swiftling_log( 'event', 'cache', 'Entry ' . $i );
		}
		$entries = $this->entries();
		$this->assertCount( SWIFTLING_LOG_LIMIT, $entries );
		$this->assertSame( 'Entry ' . ( SWIFTLING_LOG_LIMIT + 5 ), $entries[0]->message );
		$this->assertSame( 'Entry 6', end( $entries )->message );
	}

	/**
	 * Once a day the entries older than the days they are kept go: 30 by default, or what the screen
	 * saved, 7 or 90; any other value is 30.
	 */
	public function test_old_entries_go() {
		global $wpdb;
		$table = swiftling_table( 'log' );
		foreach ( array( 3, 20, 45, 100 ) as $days ) {
			swiftling_log( 'event', 'cache', $days . ' days old' );
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET logged = %d WHERE message = %s', $table, time() - $days * DAY_IN_SECONDS, $days . ' days old' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As if the entry were older.
		}

		$this->assertSame( 30, swiftling_log_keep_days() );
		do_action( SWIFTLING_LOG_CRON );
		$this->assertEqualsCanonicalizing( array( '3 days old', '20 days old' ), wp_list_pluck( $this->entries(), 'message' ) );

		update_option( SWIFTLING_LOG_KEEP_OPTION, 7 );
		do_action( SWIFTLING_LOG_CRON );
		$this->assertSame( array( '3 days old' ), wp_list_pluck( $this->entries(), 'message' ) );

		update_option( SWIFTLING_LOG_KEEP_OPTION, 12 );
		$this->assertSame( 30, swiftling_log_keep_days(), 'Not a choice of the screen' );
	}

	/**
	 * The daily deletion is scheduled from an admin screen, and stops when the plugin is
	 * deactivated.
	 */
	public function test_daily_deletion_is_scheduled() {
		wp_clear_scheduled_hook( SWIFTLING_LOG_CRON );
		$this->assertNotFalse( has_action( 'admin_init', 'swiftling_log_schedule' ) );
		swiftling_log_schedule();
		$this->assertNotFalse( wp_next_scheduled( SWIFTLING_LOG_CRON ) );
		$this->assertSame( 'daily', wp_get_schedule( SWIFTLING_LOG_CRON ) );
		\SwiftLing\Plugin::deactivate();
		$this->assertFalse( wp_next_scheduled( SWIFTLING_LOG_CRON ) );
	}

	/**
	 * The screen keeps the days a person chooses and empties the log after a confirmation; both
	 * need the right to manage SwiftLing and the form's nonce.
	 */
	public function test_forms_of_the_screen() {
		swiftling_log( 'error', 'service', 'Google refused the key.' );

		$this->become( 'administrator' );
		$this->post_form( 'swiftling_save_log_keep', array( 'keep' => '90' ) );
		$this->assertStringContainsString( 'swiftling_notice=saved', $this->redirect_of( 'swiftling_save_log_keep' ) );
		$this->assertSame( 90, swiftling_log_keep_days() );
		$this->post_form( 'swiftling_save_log_keep', array( 'keep' => '365' ) );
		$this->redirect_of( 'swiftling_save_log_keep' );
		$this->assertSame( 90, swiftling_log_keep_days(), 'Only a choice of the screen' );

		$this->post_form( 'swiftling_clear_log', array() );
		$this->assertStringContainsString( 'swiftling_notice=cleared', $this->redirect_of( 'swiftling_handle_clear_log' ) );
		$this->assertSame( array(), $this->entries() );

		swiftling_log( 'error', 'service', 'Google refused the key.' );
		$this->become( 'editor' );
		$this->post_form( 'swiftling_clear_log', array() );
		try {
			swiftling_handle_clear_log();
			$this->fail( 'An editor may not empty the log.' );
		} catch ( \WPDieException $die ) {
			$this->assertCount( 1, $this->entries() );
		}
	}

	/**
	 * The screen is its own item of the menu, Logs, lists the entries with their counts and links an entry about a
	 * page to its editor; clearing asks first, and only when there is something to clear.
	 */
	public function test_screen() {
		$this->assertSame( 'Logs', Menu::screens()['swiftling-log']['menu'] );

		$html = $this->screen();
		$this->assertStringContainsString( 'Nothing logged yet.', $html );
		$this->assertStringNotContainsString( 'swiftling-clear-log-dialog', $html, 'Nothing to clear' );

		$post = self::factory()->post->create( array( 'post_title' => 'Kontakt' ) );
		swiftling_log( 'error', 'check', 'The page check of “Kontakt” failed.', array( 'object' => 'post:' . $post ) );
		swiftling_log( 'event', 'all', 'Deutsch finished.' );
		$html = $this->screen();
		$this->assertStringContainsString( 'The page check of “Kontakt” failed.', $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'post:' . $post ) ), $html );
		$this->assertMatchesRegularExpression( '~Errors?\s*<span class="swiftling-filter-pill__count">1</span>~', $html );
		$this->assertStringContainsString( 'id="swiftling-clear-log-dialog" class="swiftling-dialog" aria-labelledby="swiftling-clear-log-title" >', $html, 'Closed until asked' );
		$this->assertStringContainsString( 'Clear all 2 entries?', $html );

		$html = $this->screen( array( 'swiftling_confirm' => '1' ) );
		$this->assertStringContainsString( 'aria-labelledby="swiftling-clear-log-title" open>', $html, 'Without the script, the confirmation shows in place' );

		$html  = $this->screen( array( 'level' => 'event' ) );
		$table = substr( $html, (int) strpos( $html, '<table' ), (int) strpos( $html, '</table>' ) - (int) strpos( $html, '<table' ) );
		$this->assertStringContainsString( 'Deutsch finished.', $table );
		$this->assertStringNotContainsString( 'The page check of', $table, 'The report for support below still has it' );
	}

	/**
	 * The report for support shows whole, one row a line and one to spare before its script makes it
	 * as tall as its wrapped text, and can be changed before it is copied, as the screen says.
	 */
	public function test_report_shows_whole_and_can_be_changed() {
		$html = $this->screen();
		$this->assertSame( 1, preg_match( '~<textarea id="swiftling-support-text"([^>]*)>~', $html, $tag ) );
		$this->assertStringNotContainsString( 'readonly', $tag[1] );
		$this->assertStringContainsString( 'rows="' . ( substr_count( swiftling_log_report(), "\n" ) + 2 ) . '"', $tag[1] );
	}

	/**
	 * The report for support has the versions and setup of the site and the latest errors and
	 * warnings, not events, and nothing secret.
	 */
	public function test_report_for_support() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		swiftling_log( 'error', 'service', 'Fake refused the key.', array( 'detail' => 'fake · 403' ) );
		swiftling_log( 'event', 'all', 'Deutsch finished.' );

		$report = swiftling_log_report();
		$this->assertStringContainsString( 'WordPress ' . get_bloginfo( 'version' ), $report );
		$this->assertStringContainsString( 'PHP ' . PHP_VERSION, $report );
		$this->assertStringContainsString( 'SwiftLing ' . SWIFTLING_VERSION, $report );
		$this->assertStringContainsString( 'Languages: Hrvatski (original) → Deutsch, Français', $report );
		$this->assertStringContainsString( 'Translation service: Fake (key saved)', $report );
		$this->assertStringContainsString( 'Error · Translation service · Fake refused the key. [fake · 403]', $report );
		$this->assertStringNotContainsString( 'Deutsch finished.', $report, 'Errors and warnings only' );
		$this->assertStringNotContainsString( 'fake-key', $report, 'Never the key' );
		$this->assertStringNotContainsString( (string) get_option( 'admin_email' ), $report );
	}

	/**
	 * A translation service that refuses, stops or returns translations that would break a page is
	 * written down, with how many texts stay empty.
	 */
	public function test_translation_service_failures() {
		FakeService::connect();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );
		$this->become( 'administrator' );
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Kontakt' ) );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$place = swiftling_place_key( 'post:' . $post->ID, swiftling_path_key( 'field/post_title' ), swiftling_hash( 'Kontakt' ) );
		$send  = function () use ( $post, $place ) {
			return $this->call(
				'/machine',
				array(
					'subject' => 'post:' . $post->ID,
					'lang'    => 'de',
					'items'   => array(
						array(
							'key'  => $place,
							'text' => 'Kontakt',
						),
					),
				)
			);
		};

		FakeService::$failure = new \WP_Error( 'fake_key', 'The key was refused.' );
		$send();
		$entry = $this->entries()[0];
		$this->assertSame( 'error', $entry->level );
		$this->assertSame( 'service', $entry->area );
		$this->assertStringContainsString( 'Fake did not translate: The key was refused. 1 text stays empty.', $entry->message );
		$this->assertSame( 'post:' . $post->ID, $entry->object_key );

		FakeService::$failure = null;
		FakeService::$stopped = 'The quota is used up.';
		$send();
		$this->assertStringContainsString( 'Fake stopped translating: The quota is used up.', $this->entries()[0]->message );
	}

	/**
	 * A page check that failed in the editor's browser is written down by the editor; one whose
	 * report cannot be verified is too.
	 */
	public function test_failed_page_checks() {
		$this->become( 'administrator' );
		$post     = self::factory()->post->create( array( 'post_title' => 'Kontakt' ) );
		$response = $this->call(
			'/check-failed',
			array(
				'subject' => 'post:' . $post,
				'lang'    => 'de',
				'reason'  => 'The page did not load within 60 seconds.',
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$entry = $this->entries()[0];
		$this->assertSame( 'error', $entry->level );
		$this->assertStringContainsString( 'The page check of “Kontakt” failed', $entry->message );
		$this->assertSame( 'The page did not load within 60 seconds. · Deutsch', $entry->detail );

		$this->call(
			'/probe',
			array(
				'subject'   => 'post:' . $post,
				'lang'      => 'de',
				'registry'  => '{"subject":"post:' . $post . '","leaves":[]}',
				'signature' => 'forged',
				'found'     => array(),
				'control'   => array(),
			)
		);
		$this->assertSame( 'warning', $this->entries()[0]->level );
		$this->assertStringContainsString( 'could not be verified', $this->entries()[0]->message );

		$this->become( 'subscriber' );
		$this->assertSame(
			403,
			$this->call(
				'/check-failed',
				array(
					'subject' => 'post:' . $post,
					'lang'    => 'de',
				)
			)->get_status()
		);
	}

	/**
	 * A translated address taken by another page, a language added and the end of a run of
	 * Translate everything are written down.
	 */
	public function test_events_of_addresses_languages_and_runs() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$first  = self::factory()->post->create( array( 'post_title' => 'Lavanda' ) );
		$second = self::factory()->post->create( array( 'post_title' => 'Ruzmarin' ) );
		swiftling_save_slug( 'post:' . $first, 'de_DE', 'lavendel' );
		swiftling_save_slug( 'post:' . $second, 'de_DE', 'lavendel' );
		$entry = $this->entries()[0];
		$this->assertSame( 'warning', $entry->level );
		$this->assertStringContainsString( 'The address “lavendel” is taken in Deutsch; “lavendel-2” was saved instead.', $entry->message );
		$this->assertSame( 'post:' . $second, $entry->object_key );

		$this->set_languages(
			array(
				'de_DE' => 'de',
				'it_IT' => 'it',
			),
			'directory'
		);
		$this->assertSame( 'Italiano was added.', $this->entries()[0]->message );
		$this->set_languages( array( 'de_DE' => 'de' ), 'parameter' );
		$messages = wp_list_pluck( array_slice( $this->entries(), 0, 2 ), 'message' );
		$this->assertContains( 'Italiano was removed.', $messages );
		$this->assertContains( 'The address format changed from directory to parameter.', $messages );

		$this->become( 'administrator' );
		$this->call(
			'/translate-all/progress',
			array(
				'lang'    => 'de',
				'page'    => 'post:' . $first,
				'outcome' => 'skipped',
				'reason'  => 'The page did not load.',
			)
		);
		$this->assertStringContainsString( 'Translate everything skipped “Lavanda” in Deutsch: The page did not load.', $this->entries()[0]->message );
		$this->call(
			'/translate-all/progress',
			array(
				'lang'       => 'de',
				'page'       => 'post:' . $second,
				'outcome'    => 'done',
				'texts'      => 4,
				'characters' => 120,
				'finish'     => true,
			)
		);
		$this->assertSame( 'Deutsch finished: 1 pages, 4 texts, 120 characters sent to the translation service, 1 pages skipped.', $this->entries()[0]->message );
	}

	/**
	 * A host of a language that does not answer is written down as an error.
	 */
	public function test_host_that_does_not_answer() {
		swiftling_check_hosts( array( 'de.example.org' ) );
		$entry = $this->entries()[0];
		$this->assertSame( 'error', $entry->level );
		$this->assertSame( 'de.example.org does not answer. Pages in its language stay at the original address until it does.', $entry->message );
	}

	/**
	 * Emptying the cache after a translation is written down when a cache plugin is active, and not
	 * when none is: then nothing was emptied.
	 */
	public function test_cache_emptied() {
		$queue          = &swiftling_purge_queue();
		$queue['urls']  = array( 'http://example.org/de/kontakt/' => true );
		$queue['posts'] = array( 5 => true );
		swiftling_run_purge();
		// Other tests leave stand-ins of cache plugins behind, which PHP cannot take back.
		if ( ! swiftling_active_cache_plugins() ) {
			$this->assertSame( array(), $this->entries(), 'No cache plugin' );
		}

		add_action( 'litespeed_purge_post', '__return_null' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- As if LiteSpeed Cache were active.
		$queue          = &swiftling_purge_queue();
		$queue['urls']  = array( 'http://example.org/de/kontakt/' => true );
		$queue['posts'] = array( 5 => true );
		swiftling_run_purge();
		$this->assertContains( 'LiteSpeed Cache', swiftling_active_cache_plugins() );
		$this->assertSame( 'Emptied 1 address from the cache of ' . wp_sprintf( '%l', swiftling_active_cache_plugins() ) . ' after a translation changed.', $this->entries()[0]->message );
	}

	/**
	 * An update of the tables is written down once the log's table is there.
	 */
	public function test_update_of_the_tables() {
		update_option( 'swiftling_db_version', 9 );
		swiftling_maybe_install_tables();
		$this->assertSame( SWIFTLING_DB_VERSION, (int) get_option( 'swiftling_db_version' ) );
		$this->assertSame( 'SwiftLing\'s tables were updated from version 9 to ' . SWIFTLING_DB_VERSION . '.', $this->entries()[0]->message );
	}
}
