<?php
/**
 * Tests of the search in the visitor's language.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WP_Query;
use WP_REST_Request;

/**
 * What the index holds, and what a visitor finds when searching in their own language.
 */
class SearchTest extends TestCase {

	/**
	 * Makes a post whose page check found its title and a text in a field, and translates both.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Text of the field.
	 * @param array  $into     Language slug => array( translated title, translated field ).
	 * @param string $type     Post type.
	 * @return \WP_Post
	 */
	private function post( $title, $subtitle, $into = array(), $type = 'post' ) {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => $title,
				'post_excerpt' => '',
				'post_type'    => $type,
			)
		);
		add_post_meta( $post->ID, 'subtitle', $subtitle );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'subtitle', true );
			}
		);
		foreach ( $into as $slug => $texts ) {
			$this->translate( 'post:' . $post->ID, 'field/post_title', $title, $slug, $texts[0] );
			$this->translate( 'post:' . $post->ID, 'meta/subtitle', $subtitle, $slug, $texts[1] );
		}
		swiftling_refresh_translated( array( $post->ID ) );
		return $post;
	}

	/**
	 * Returns the index row of a post in a language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $locale  Locale.
	 * @return array{title: string, body: string}|null
	 */
	private function row( $post_id, $locale ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading SwiftLing's own table in a test.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT title, body FROM %i WHERE post_id = %d AND lang = %s', swiftling_table( 'index' ), $post_id, $locale ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Returns the IDs a search finds, as a visitor of a language.
	 *
	 * @param string $slug  Address slug of the language; empty for the original.
	 * @param string $words What is searched for.
	 * @return int[]
	 */
	private function find( $slug, $words ) {
		if ( '' !== $slug ) {
			$this->show_in( $slug );
		}
		$query = new WP_Query(
			array(
				's'                   => $words,
				'fields'              => 'ids',
				'posts_per_page'      => 20,
				'ignore_sticky_posts' => true,
			)
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * The texts of a post are written to the index in every language they are translated into,
	 * the title apart from the rest.
	 */
	public function test_translations_are_indexed() {
		$post = $this->post(
			'Kontakt',
			'Javite nam se',
			array(
				'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ),
				'fr' => array( 'Contactez-nous', 'Écrivez-nous' ),
			)
		);

		$this->assertSame( 'Kontaktieren Sie uns', $this->row( $post->ID, 'de_DE' )['title'] );
		$this->assertSame( 'Schreiben Sie uns', $this->row( $post->ID, 'de_DE' )['body'] );
		$this->assertSame( 'Contactez-nous', $this->row( $post->ID, 'fr_FR' )['title'] );
		// A language the post is not translated into has no row.
		$this->assertNull( $this->row( $post->ID, 'it_IT' ) );
	}

	/**
	 * Two runs writing the same post at once (languages translated in parallel) both delete its rows
	 * and both insert them. The second insert must not fail on a row the first one wrote.
	 */
	public function test_a_row_written_at_the_same_time_does_not_fail() {
		global $wpdb;
		$post  = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		$table = swiftling_table( 'index' );

		$injected = false;
		$other    = static function ( $query ) use ( &$injected, $wpdb, $table, $post ) {
			// Just before this process writes its rows, another one has written the same row.
			if ( ! $injected && ( 0 === strpos( $query, 'REPLACE INTO ' ) || 0 === strpos( $query, 'INSERT INTO `' . $table ) ) ) {
				$injected = true;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The test plays the other process.
				$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (post_id, lang, title, body) VALUES (%d, %s, %s, %s)', $table, $post->ID, 'de_DE', 'other', 'other' ) );
			}
			return $query;
		};
		add_filter( 'query', $other );
		$wpdb->last_error = '';
		swiftling_refresh_index( array( $post->ID ) );
		remove_filter( 'query', $other );

		$this->assertTrue( $injected, 'The other process wrote first' );
		$this->assertSame( '', $wpdb->last_error, 'No duplicate entry' );
		$this->assertSame( 'Kontaktieren Sie uns', $this->row( $post->ID, 'de_DE' )['title'], 'The row of this run is the one that stays' );
	}

	/**
	 * A visitor searching in their language finds the post by its translated words, and the
	 * original words still find it.
	 */
	public function test_search_finds_a_post_by_its_translation() {
		$post  = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		$other = $this->post( 'O nama', 'Tko smo mi', array( 'de' => array( 'Über uns', 'Wer wir sind' ) ) );

		$this->assertSame( array( $post->ID ), $this->find( 'de', 'Kontaktieren' ), 'The translated title' );
		$this->assertSame( array( $post->ID ), $this->find( 'de', 'Schreiben' ), 'A translated text of a field' );
		$this->assertSame( array( $other->ID ), $this->find( 'de', 'Wer wir sind' ) );
		// The original words keep working in every language: nothing is replaced, the index is added.
		$this->assertSame( array( $post->ID ), $this->find( 'de', 'Kontakt' ) );
		$this->assertSame( array( $post->ID ), $this->find( '', 'Kontakt' ) );
		// In the original language the German words find nothing: the pages are Croatian there.
		$this->reset_state();
		$this->assertSame( array(), $this->find( '', 'Kontaktieren' ) );
	}

	/**
	 * Every word of the search must be there, as WordPress asks of the original.
	 */
	public function test_every_word_must_match() {
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );

		$this->assertSame( array( $post->ID ), $this->find( 'de', 'Kontaktieren Schreiben' ) );
		$this->assertSame( array(), $this->find( 'de', 'Kontaktieren Fahrrad' ) );
	}

	/**
	 * A word too short for the full-text index is found all the same.
	 */
	public function test_short_words_are_found() {
		$post = $this->post( 'Dan', 'Svaki dan', array( 'de' => array( 'Tag', 'Jeden Tag' ) ) );

		$this->assertSame( array( $post->ID ), $this->find( 'de', 'Tag' ) );
	}

	/**
	 * Whole words of four letters and more are looked up in the full-text index first; shorter
	 * ones, which it does not keep, are read from the rows themselves. A full-text answer with
	 * nothing in it is read again the slow way, since the index has a word list of its own.
	 */
	public function test_long_words_ask_the_full_text_index_first() {
		$this->post( 'Dan', 'Svaki dan', array( 'de' => array( 'Tag', 'Jeden Tag' ) ) );
		$this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );

		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};

		add_filter( 'query', $record );
		$this->find( 'de', 'Kontaktieren' );
		remove_filter( 'query', $record );
		$this->assertNotEmpty( $this->queries_like( $queries, 'MATCH(title, body) AGAINST' ), 'A long word asks the index' );
		$this->assertNotEmpty( $this->queries_like( $queries, 'title LIKE' ), 'and is read again when it answers with nothing' );

		$queries = array();
		add_filter( 'query', $record );
		$this->find( 'de', 'Tag' );
		remove_filter( 'query', $record );
		$this->assertSame( array(), $this->queries_like( $queries, 'MATCH(title, body) AGAINST' ), 'A short word is read from the rows' );
		$this->assertNotEmpty( $this->queries_like( $queries, 'title LIKE' ) );
	}

	/**
	 * Returns the recorded queries that hold a piece of SQL.
	 *
	 * @param string[] $queries Queries.
	 * @param string   $needle  Piece of SQL.
	 * @return string[]
	 */
	private function queries_like( $queries, $needle ) {
		return array_values(
			array_filter(
				$queries,
				static function ( $query ) use ( $needle ) {
					return false !== strpos( (string) $query, $needle );
				}
			)
		);
	}

	/**
	 * A text whose original changed since it was translated is not in the index: visitors read the
	 * new original, so searching the old translation must not find the page.
	 */
	public function test_outdated_translations_are_not_indexed() {
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		wp_update_post(
			array(
				'ID'         => $post->ID,
				'post_title' => 'Kontaktirajte nas',
			)
		);
		$post = get_post( $post->ID );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'subtitle', true );
			}
		);
		swiftling_refresh_translated( array( $post->ID ) );

		$this->assertSame( '', $this->row( $post->ID, 'de_DE' )['title'] );
		$this->assertSame( 'Schreiben Sie uns', $this->row( $post->ID, 'de_DE' )['body'] );
		$this->assertSame( array(), $this->find( 'de', 'Kontaktieren' ) );
	}

	/**
	 * Only the post's own texts are indexed: the header, the footer and other shared texts would
	 * otherwise match every page, and an address is not text.
	 */
	public function test_shared_texts_and_addresses_are_left_out() {
		update_option( 'blogname', 'Moja stranica' );
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		add_post_meta( $post->ID, 'cta', 'https://example.org/kontakt/' );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
				get_option( 'blogname' );
				get_post_meta( $post->ID, 'cta', true );
			},
			array( 'meta/cta' => 'href' )
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren Sie uns' );
		$this->translate( 'options', 'option/blogname', 'Moja stranica', 'de', 'Meine Webseite' );
		$this->translate_address( 'post:' . $post->ID, 'meta/cta', 'https://example.org/kontakt/', 'de', 'https://example.org/de/kontakt/' );
		swiftling_refresh_translated( array( $post->ID ) );

		$this->assertSame( 'Kontaktieren Sie uns', $this->row( $post->ID, 'de_DE' )['title'] );
		$this->assertSame( '', $this->row( $post->ID, 'de_DE' )['body'] );
		$this->assertSame( array(), $this->find( 'de', 'Webseite' ), 'The site title is not a text of this page' );
	}

	/**
	 * Posts of a type the Settings screen sets to Never are left out of the index.
	 */
	public function test_types_set_to_never_are_left_out() {
		register_post_type(
			'project',
			array(
				'public' => true,
				'labels' => array( 'singular_name' => 'Project' ),
			)
		);
		$project = $this->post( 'Projekt', 'Naš projekt', array( 'de' => array( 'Projekt DE', 'Unser Projekt' ) ), 'project' );
		update_option( 'swiftling_settings', array( 'never' => array( 'project' ) ) );
		swiftling_refresh_index( array( $project->ID ) );

		$this->assertNull( $this->row( $project->ID, 'de_DE' ) );
	}

	/**
	 * A search in quotation marks looks for the words together, and a query that asks for the
	 * text exactly compares the whole text, as WordPress does with the original.
	 */
	public function test_phrases_and_exact_searches() {
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );

		$this->assertSame( array( $post->ID ), $this->find( 'de', '"Schreiben Sie"' ) );
		$this->assertSame( array(), $this->find( 'de', '"Schreiben uns"' ), 'Not in this order' );

		$this->show_in( 'de' );
		$whole = new WP_Query(
			array(
				's'        => 'Kontaktieren Sie uns',
				'exact'    => true,
				'sentence' => true,
				'fields'   => 'ids',
			)
		);
		$this->assertSame( array( $post->ID ), array_map( 'intval', $whole->posts ) );

		$part = new WP_Query(
			array(
				's'        => 'Kontaktieren',
				'exact'    => true,
				'sentence' => true,
				'fields'   => 'ids',
			)
		);
		$this->assertSame( array(), $part->posts, 'Only a piece of the title' );
	}

	/**
	 * The clause SwiftLing adds keeps what WordPress built, including the rule that hides
	 * password-protected posts from people who are not signed in.
	 */
	public function test_the_password_rule_survives() {
		global $wpdb;
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		$this->show_in( 'de' );

		$query = new WP_Query(
			array(
				's'      => 'Kontaktieren',
				'fields' => 'ids',
			)
		);
		$this->assertStringContainsString( $wpdb->posts . '.post_password', $query->request );
		$this->assertStringContainsString( $wpdb->posts . '.ID IN (' . $post->ID . ')', $query->request );
		// The password rule stays outside the group SwiftLing widened.
		$this->assertStringNotContainsString( "post_password = '') )", $query->request );
	}

	/**
	 * A post behind a password is not found by its translated words either: the rule WordPress
	 * writes for visitors who are not signed in still holds.
	 */
	public function test_a_post_behind_a_password_is_not_found() {
		$post = $this->post( 'Tajna', 'Skriveni tekst', array( 'de' => array( 'Geheimnis', 'Versteckter Text' ) ) );
		wp_update_post(
			array(
				'ID'            => $post->ID,
				'post_password' => 'tajna',
			)
		);

		$this->assertSame( array(), $this->find( 'de', 'Geheimnis' ) );
		$this->assertSame( array(), $this->find( 'de', 'Versteckter' ) );
	}

	/**
	 * A post that is hidden in a language because it is not translated yet stays out of its
	 * search, however many of its texts are translated already.
	 */
	public function test_hidden_posts_stay_out_of_search() {
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		add_post_meta( $post->ID, 'subtitle', 'Javite nam se' );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
				get_post_meta( $post->ID, 'subtitle', true );
			}
		);
		// Only the title is translated, so the post does not count as translated yet.
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren Sie uns' );
		swiftling_refresh_translated( array( $post->ID ) );
		update_option(
			'swiftling_settings',
			array(
				'untranslated'   => array( 'post' => 'hide' ),
				'hidden_address' => 'original',
			)
		);

		$this->assertSame( 'Kontaktieren Sie uns', $this->row( $post->ID, 'de_DE' )['title'], 'What is translated is indexed' );
		$this->assertSame( array(), $this->find( 'de', 'Kontaktieren' ), 'but the post is hidden in this language' );
	}

	/**
	 * Updating the plugin writes the index for the translations the site already has.
	 */
	public function test_the_upgrade_builds_the_index() {
		global $wpdb;
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Emptying SwiftLing's own table in a test.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', swiftling_table( 'index' ) ) );
		update_option( 'swiftling_db_version', 8 );

		swiftling_maybe_install_tables();

		$this->assertSame( SWIFTLING_DB_VERSION, (int) get_option( 'swiftling_db_version' ) );
		$this->assertSame( 'Kontaktieren Sie uns', $this->row( $post->ID, 'de_DE' )['title'] );
	}

	/**
	 * A search clause of a shape SwiftLing does not know, from another plugin, is left alone.
	 */
	public function test_an_unknown_clause_is_left_alone() {
		$this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		$this->show_in( 'de' );
		$query = new WP_Query(
			array(
				's'      => 'Kontaktieren',
				'fields' => 'ids',
			)
		);

		$this->assertSame( 'WHERE something else', swiftling_filter_search( 'WHERE something else', $query ) );
	}

	/**
	 * Switched off on the Settings screen, search works as WordPress built it.
	 */
	public function test_the_setting_switches_it_off() {
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		$this->assertTrue( swiftling_settings()['search'], 'On unless it is switched off' );

		update_option( 'swiftling_settings', array( 'search' => false ) );
		$this->assertSame( array(), $this->find( 'de', 'Kontaktieren' ) );
		$this->assertSame( array( $post->ID ), $this->find( 'de', 'Kontakt' ), 'The original still works' );
	}

	/**
	 * A deleted post leaves the index.
	 */
	public function test_a_deleted_post_leaves_the_index() {
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		wp_delete_post( $post->ID, true );

		$this->assertNull( $this->row( $post->ID, 'de_DE' ) );
	}

	/**
	 * Building the index again writes every translated post and drops the languages the site no
	 * longer has.
	 */
	public function test_building_the_index_again() {
		global $wpdb;
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		// A row of a language that was removed, and an empty index.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preparing SwiftLing's own table in a test.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', swiftling_table( 'index' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preparing SwiftLing's own table in a test.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (post_id, lang, title, body) VALUES (%d, %s, %s, %s)', swiftling_table( 'index' ), $post->ID, 'it_IT', 'Contatto', '' ) );

		swiftling_refresh_all_index();

		$this->assertSame( 'Kontaktieren Sie uns', $this->row( $post->ID, 'de_DE' )['title'] );
		$this->assertNull( $this->row( $post->ID, 'it_IT' ), 'A language the site no longer has' );
	}

	/**
	 * The screen builds the index in batches; visitors and editors may not.
	 */
	public function test_the_rebuild_route() {
		$post = $this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );

		$request = new WP_REST_Request( 'POST', '/swiftling/v1/search-index' );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
		$this->become( 'editor' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$this->become( 'administrator' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preparing SwiftLing's own table in a test.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', swiftling_table( 'index' ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['finished'] );
		$this->assertSame( 1, $data['done'] );
		$this->assertSame( 1, $data['pages'] );
		$this->assertGreaterThan( 0, $data['built'] );
		$this->assertSame( 'Kontaktieren Sie uns', $this->row( $post->ID, 'de_DE' )['title'] );
	}

	/**
	 * The state of the index, as the Settings screen shows it.
	 */
	public function test_the_state_of_the_index() {
		$this->post( 'Kontakt', 'Javite nam se', array( 'de' => array( 'Kontaktieren Sie uns', 'Schreiben Sie uns' ) ) );
		$this->post( 'O nama', 'Tko smo mi', array( 'de' => array( 'Über uns', 'Wer wir sind' ) ) );

		$state = swiftling_search_state();
		$this->assertSame( 2, $state['pages'] );
		$this->assertSame( 1, $state['languages'] );
	}
}
