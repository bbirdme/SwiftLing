<?php
/**
 * Tests of translated slugs.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * The address of a post or term in each language: how slugs are saved, the links WordPress builds,
 * how a request finds its post, and where an old address leads.
 */
class SlugsTest extends TestCase {

	/**
	 * A post "kontakt", a page "o-nama" with a child "tim", and a category "vijesti".
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * Makes the content, on a site with language folders.
	 */
	public function set_up() {
		parent::set_up();
		$this->use_pretty_permalinks();
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		$this->ids['kontakt'] = self::factory()->post->create(
			array(
				'post_name'    => 'kontakt',
				'post_title'   => 'Kontakt',
				'post_excerpt' => '',
			)
		);
		$this->ids['o-nama']  = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'o-nama',
			)
		);
		$this->ids['tim']     = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'tim',
				'post_parent' => $this->ids['o-nama'],
			)
		);
		$this->ids['vijesti'] = self::factory()->category->create( array( 'slug' => 'vijesti' ) );
	}

	/**
	 * Saves a slug of a post in German.
	 *
	 * @param string $name Original slug of the post.
	 * @param string $slug Slug.
	 * @param string $lang Language.
	 * @return string The slug saved.
	 */
	private function slug( $name, $slug, $lang = 'de' ) {
		return swiftling_save_slug( 'post:' . $this->ids[ $name ], swiftling_target_language( $lang )['locale'], $slug );
	}

	/**
	 * Shows the rest of the test in a language, as a request for its folder would.
	 *
	 * @param string $slug Language.
	 */
	private function in_folder( $slug ) {
		$this->show_in( $slug );
		swiftling_keep_folder( $slug );
	}

	/**
	 * Returns the rows of the slugs table of an object: slug => current.
	 *
	 * @param string $owner  Object key.
	 * @param string $locale Locale.
	 * @return array<string, int>
	 */
	private function rows( $owner, $locale = 'de_DE' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the table in a test.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT slug, current FROM %i WHERE object_key = %s AND lang = %s ORDER BY slug', swiftling_table( 'slugs' ), $owner, $locale ) );
		$found = array();
		foreach ( $rows as $row ) {
			$found[ $row->slug ] = (int) $row->current;
		}
		return $found;
	}

	/**
	 * Slugs are written as WordPress writes them, never longer than an index holds and never with half a character.
	 */
	public function test_sanitize_slug() {
		$this->assertSame( 'uber-uns', swiftling_sanitize_slug( 'Über uns!', '' ) );
		$this->assertSame( 'kontakt-de', swiftling_sanitize_slug( '<b>kontakt-de</b>', '' ) );

		$long = swiftling_sanitize_slug( str_repeat( 'контакт ', 40 ), '' );
		$this->assertLessThanOrEqual( SWIFTLING_SLUG_LENGTH, strlen( $long ) );
		$this->assertMatchesRegularExpression( '/^(?:%[0-9a-f]{2}|-)+$/', $long );
		$this->assertTrue( wp_check_invalid_utf8( rawurldecode( $long ) ) === rawurldecode( $long ), 'No character cut in two' );
	}

	/**
	 * Words WordPress's addresses use are never a translated slug.
	 */
	public function test_reserved_slugs() {
		foreach ( array( 'page', 'feed', '2026', 'comment-page-2', 'category', 'tag', 'de', 'fr', 'hr', 'wp-json', 'author' ) as $word ) {
			$this->assertTrue( swiftling_is_reserved_slug( $word ), $word );
		}
		$this->assertFalse( swiftling_is_reserved_slug( 'kontakt' ) );
	}

	/**
	 * A translated slug is unique in its language: not another translated slug, original slug or reserved word.
	 */
	public function test_slugs_are_unique() {
		$this->assertSame( 'kontaktieren', $this->slug( 'kontakt', 'Kontaktieren' ) );
		$this->assertSame( 'kontaktieren-2', $this->slug( 'o-nama', 'kontaktieren' ), 'Another translated slug' );
		$this->assertSame( 'tim-2', $this->slug( 'o-nama', 'tim' ), 'The original slug of another page' );
		$this->assertSame( 'vijesti-2', $this->slug( 'o-nama', 'vijesti' ), 'The original slug of a category' );
		$this->assertSame( 'page-2', $this->slug( 'o-nama', 'page' ), 'A reserved word' );
		$this->assertSame( 'kontaktieren', $this->slug( 'o-nama', 'kontaktieren', 'fr' ), 'Another language is free' );
	}

	/**
	 * The original slug, or none, means the language uses the original slug.
	 */
	public function test_original_slug_removes_the_translation() {
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->assertSame( '', $this->slug( 'kontakt', 'Kontakt' ) );
		$this->assertNull( swiftling_slug_entry( 'post:' . $this->ids['kontakt'], 'de_DE' ) );
		$this->assertSame( '', swiftling_get_slug( 'post:' . $this->ids['kontakt'], 'de_DE' ) );
		$this->assertSame( array( 'kontaktieren' => 0 ), $this->rows( 'post:' . $this->ids['kontakt'] ), 'The earlier address still leads here' );
	}

	/**
	 * A changed slug keeps the earlier one, which another object may take; taking an earlier one back makes it current.
	 */
	public function test_earlier_slugs() {
		$owner = 'post:' . $this->ids['kontakt'];
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->slug( 'kontakt', 'kontakt-de' );
		$this->assertSame(
			array(
				'kontakt-de'   => 1,
				'kontaktieren' => 0,
			),
			$this->rows( $owner )
		);
		$this->assertSame( 'kontakt-de', swiftling_get_slug( $owner, 'de_DE' ) );

		$this->slug( 'kontakt', 'kontaktieren' );
		$this->assertSame(
			array(
				'kontakt-de'   => 0,
				'kontaktieren' => 1,
			),
			$this->rows( $owner )
		);

		$this->assertSame( 'kontakt-de', $this->slug( 'o-nama', 'kontakt-de' ), 'An earlier slug of another page gives way' );
		$this->assertSame( array( 'kontaktieren' => 1 ), $this->rows( $owner ) );
	}

	/**
	 * Saving the slug it has changes only how it was made.
	 */
	public function test_status() {
		$owner = 'post:' . $this->ids['kontakt'];
		swiftling_save_slug( $owner, 'de_DE', 'kontaktieren', SWIFTLING_MACHINE );
		$this->assertSame( 'machine', swiftling_slug_entry( $owner, 'de_DE' )['state'] );
		swiftling_save_slug( $owner, 'de_DE', 'kontaktieren' );
		$this->assertSame(
			array(
				'slug'  => 'kontaktieren',
				'state' => 'reviewed',
			),
			swiftling_slug_entry( $owner, 'de_DE' )
		);
	}

	/**
	 * Links WordPress builds carry the slugs of the language the page is shown in.
	 */
	public function test_post_links() {
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->assertSame( 'http://example.org/kontakt/', get_permalink( $this->ids['kontakt'] ), 'The original' );

		$this->in_folder( 'de' );
		$this->assertSame( 'http://example.org/de/kontaktieren/', get_permalink( $this->ids['kontakt'] ) );
		$this->assertStringContainsString( '%postname%', get_permalink( $this->ids['kontakt'], true ), 'The editor\'s placeholder stays' );

		$this->in_folder( 'fr' );
		$this->assertSame( 'http://example.org/fr/kontakt/', get_permalink( $this->ids['kontakt'] ), 'No French slug' );
	}

	/**
	 * Any language can be asked for, whatever the page is shown in.
	 */
	public function test_link_language() {
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->slug( 'kontakt', 'contact', 'fr' );
		$this->show_in( 'de' );
		$link = static function () {
			return get_permalink( get_page_by_path( 'kontakt', OBJECT, 'post' ) );
		};
		$this->assertSame( 'http://example.org/contact/', swiftling_in_link_language( 'fr', $link ) );
		$this->assertSame( 'http://example.org/kontakt/', swiftling_in_link_language( '', $link ) );
		$this->assertSame( 'http://example.org/kontaktieren/', $link(), 'The page\'s language again afterwards' );
	}

	/**
	 * A page's path carries the slug of each page above it, translated where it has one.
	 */
	public function test_page_paths() {
		$this->slug( 'tim', 'team' );
		$this->in_folder( 'de' );
		$this->assertSame( 'http://example.org/de/o-nama/team/', get_permalink( $this->ids['tim'] ) );
		$this->slug( 'o-nama', 'uber-uns' );
		$this->assertSame( 'http://example.org/de/uber-uns/team/', get_permalink( $this->ids['tim'] ) );
		$this->assertSame( 'http://example.org/de/uber-uns/', get_permalink( $this->ids['o-nama'] ) );
	}

	/**
	 * A post's own slug is replaced, not the same word earlier in its address.
	 */
	public function test_only_the_posts_own_slug() {
		$this->set_permalink_structure( '/%category%/%postname%/' );
		$category = self::factory()->category->create( array( 'slug' => 'kontakt' ) );
		wp_set_post_categories( $this->ids['kontakt'], array( $category ) );
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->show_in( 'de' );
		$this->assertSame( 'http://example.org/kontakt/kontaktieren/', get_permalink( $this->ids['kontakt'] ) );
	}

	/**
	 * Terms have slugs of their own, their parents too.
	 */
	public function test_term_links() {
		$sport    = self::factory()->category->create( array( 'slug' => 'sport' ) );
		$football = self::factory()->category->create(
			array(
				'slug'   => 'nogomet',
				'parent' => $sport,
			)
		);
		swiftling_save_slug( 'term:' . $this->ids['vijesti'], 'de_DE', 'Nachrichten' );
		swiftling_save_slug( 'term:' . $football, 'de_DE', 'fussball' );

		$this->in_folder( 'de' );
		$this->assertSame( 'http://example.org/de/category/nachrichten/', get_term_link( $this->ids['vijesti'] ) );
		$this->assertSame( 'http://example.org/de/category/sport/fussball/', get_term_link( $football ) );
		swiftling_save_slug( 'term:' . $sport, 'de_DE', 'sportarten' );
		$this->assertSame( 'http://example.org/de/category/sportarten/fussball/', get_term_link( $football ) );
	}

	/**
	 * A page in the original language reads nothing about slugs; a list in another language reads them in one go.
	 */
	public function test_queries() {
		global $wpdb;
		self::factory()->post->create_many( 5 );
		$this->slug( 'kontakt', 'kontaktieren' );
		$slugs   = swiftling_table( 'slugs' );
		$queries = 0;
		$count   = static function ( $query ) use ( &$queries, $slugs ) {
			if ( false !== strpos( $query, $slugs ) ) {
				++$queries;
			}
			return $query;
		};
		add_filter( 'query', $count );

		$links = static function () {
			$query = new \WP_Query( array( 'posts_per_page' => 10 ) );
			return array_map( 'get_permalink', $query->posts );
		};
		$links();
		$this->assertSame( 0, $queries, 'The original language' );

		$this->show_in( 'de' );
		$this->assertContains( 'http://example.org/kontaktieren/', $links() );
		$this->assertLessThanOrEqual( 2, $queries, 'Whether German has slugs, and those of the listed posts' );
		remove_filter( 'query', $count );
	}

	/**
	 * A request finds its post, page or category by the translated slug, in the folder of the language.
	 */
	public function test_requests_in_the_directory_format() {
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->slug( 'o-nama', 'uber-uns' );
		$this->slug( 'tim', 'team' );
		swiftling_save_slug( 'term:' . $this->ids['vijesti'], 'de_DE', 'nachrichten' );
		$this->in_folder( 'de' );

		$this->go_to( 'http://example.org/de/kontaktieren/' );
		$this->assertTrue( is_single() );
		$this->assertSame( $this->ids['kontakt'], get_queried_object_id() );
		$this->assertSame( '/de/kontaktieren/', $_SERVER['REQUEST_URI'], 'The address as asked, for the rest of the request' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Compared, not used.

		$this->go_to( 'http://example.org/de/uber-uns/team/' );
		$this->assertTrue( is_page() );
		$this->assertSame( $this->ids['tim'], get_queried_object_id() );

		$this->go_to( 'http://example.org/de/o-nama/team/' );
		$this->assertSame( $this->ids['tim'], get_queried_object_id(), 'A parent by its original slug' );

		$this->go_to( 'http://example.org/de/category/nachrichten/' );
		$this->assertTrue( is_category() );
		$this->assertSame( $this->ids['vijesti'], get_queried_object_id() );

		$this->go_to( 'http://example.org/de/nichts/' );
		$this->assertTrue( is_404() );
	}

	/**
	 * In the parameter format the slug is translated as well. The links a page builds carry no
	 * language there, so they lead to the original and keep its slug.
	 */
	public function test_requests_in_the_parameter_format() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			)
		);
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->show_in( 'de' );
		$this->assertSame( 'http://example.org/kontakt/', get_permalink( $this->ids['kontakt'] ) );
		$this->assertSame( 'http://example.org/kontaktieren/?lang=de', swiftling_post_language_url( get_post( $this->ids['kontakt'] ), 'de' ) );
		$this->go_to( 'http://example.org/kontaktieren/?lang=de' );
		$this->assertSame( $this->ids['kontakt'], get_queried_object_id() );
		$this->assertSame( 'http://example.org/kontakt/', get_permalink( $this->ids['kontakt'] ) );
	}

	/**
	 * The original slug, or an earlier translated one, leads to the address now, keeping the rest of the address.
	 */
	public function test_redirects() {
		$this->slug( 'kontakt', 'kontaktieren' );
		swiftling_save_slug( 'term:' . $this->ids['vijesti'], 'de_DE', 'nachrichten' );
		$this->in_folder( 'de' );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->go_to( 'http://example.org/de/kontakt/?utm_source=x' );
		$this->assertSame( $this->ids['kontakt'], get_queried_object_id() );
		$this->assertSame( 'http://example.org/de/kontaktieren/?utm_source=x', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );
		$this->assertSame( 301, $this->redirect_status );

		$this->slug( 'kontakt', 'kontakt-de' );
		$this->go_to( 'http://example.org/de/kontaktieren/' );
		$this->assertSame( $this->ids['kontakt'], get_queried_object_id() );
		$this->assertSame( 'http://example.org/de/kontakt-de/', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );

		$this->go_to( 'http://example.org/de/category/vijesti/' );
		$this->assertSame( 'http://example.org/de/category/nachrichten/', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );

		$this->go_to( 'http://example.org/de/kontakt-de/' );
		$this->assert_no_redirect( 'swiftling_redirect_to_translated_slug' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->go_to( 'http://example.org/de/kontakt/' );
		$this->assert_no_redirect( 'swiftling_redirect_to_translated_slug' );
	}

	/**
	 * A slug in another script is kept percent-encoded in lower case, as WordPress writes slugs. A
	 * request may spell the same address in upper case, as proxies such as Traefik rewrite it, or
	 * unencoded: it is the address now and is not sent anywhere, or the proxy would send it back in
	 * upper case forever (#83). Another address of the post still leads to the address now.
	 */
	public function test_non_ascii_slug_is_not_redirected_to_itself() {
		$this->assertSame( '%d0%be-%d0%bd%d0%b0%d1%81', $this->slug( 'kontakt', 'о-нас' ) );
		$this->in_folder( 'de' );
		$_SERVER['REQUEST_METHOD'] = 'GET';

		foreach ( array( '/de/%d0%be-%d0%bd%d0%b0%d1%81/', '/de/%D0%BE-%D0%BD%D0%B0%D1%81/', '/de/о-нас/' ) as $asked ) {
			$this->go_to( 'http://example.org' . $asked . '?a=1' );
			$this->assertSame( $this->ids['kontakt'], get_queried_object_id(), $asked );
			$this->assert_no_redirect( 'swiftling_redirect_to_translated_slug' );
		}

		// The original slug still leads to the address now.
		$this->go_to( 'http://example.org/de/kontakt/' );
		$this->assertSame( 'http://example.org/de/%d0%be-%d0%bd%d0%b0%d1%81/', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );

		// A character sanitize_title() keeps, as a translated title can give (#77).
		$this->assertSame( 'af%c2%b7kontakt', $this->slug( 'kontakt', 'AF·Kontakt' ) );
		$this->go_to( 'http://example.org/de/af%C2%B7kontakt/' );
		$this->assertSame( $this->ids['kontakt'], get_queried_object_id() );
		$this->assert_no_redirect( 'swiftling_redirect_to_translated_slug' );
	}

	/**
	 * On a page asked for in upper case the switcher and hreflang still give each language its slug.
	 */
	public function test_other_languages_of_an_upper_case_address() {
		$this->slug( 'kontakt', 'о-нас' );
		$this->slug( 'kontakt', 'contact', 'fr' );
		$this->in_folder( 'de' );

		$this->go_to( 'http://example.org/de/%D0%BE-%D0%BD%D0%B0%D1%81/?a=1' );
		$this->assertSame( 'http://example.org/fr/contact/?a=1', swiftling_current_url( 'fr' ) );
		$this->assertSame( 'http://example.org/kontakt/?a=1', swiftling_current_url( '' ) );
		$this->assertSame( 'http://example.org/de/%d0%be-%d0%bd%d0%b0%d1%81/?a=1', swiftling_current_url( 'de' ) );
	}

	/**
	 * A post of several pages keeps its page in the redirect.
	 */
	public function test_redirect_keeps_the_page_of_a_post() {
		wp_update_post(
			array(
				'ID'           => $this->ids['kontakt'],
				'post_content' => 'Prva<!--nextpage-->Druga',
			)
		);
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->in_folder( 'de' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->go_to( 'http://example.org/de/kontakt/2/' );
		$this->assertSame( 'http://example.org/de/kontaktieren/2/', $this->redirect_of( 'swiftling_redirect_to_translated_slug' ) );
	}

	/**
	 * The original language has no translated slugs to send anywhere.
	 */
	public function test_original_language_is_not_redirected() {
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->go_to( 'http://example.org/kontakt/' );
		$this->assert_no_redirect( 'swiftling_redirect_to_translated_slug' );
	}

	/**
	 * The language switcher and hreflang give each language its own slugs, keeping the rest of the address.
	 */
	public function test_addresses_of_the_other_languages() {
		$post = get_post( $this->ids['kontakt'] );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
		$this->translate( 'post:' . $post->ID, 'field/post_title', 'Kontakt', 'fr', 'Contact' );
		swiftling_refresh_translated( array( $post->ID ) );
		$this->slug( 'kontakt', 'kontaktieren' );
		$this->slug( 'kontakt', 'contact', 'fr' );

		$this->in_folder( 'de' );
		$this->go_to( 'http://example.org/de/kontaktieren/?a=1' );
		$this->assertSame( 'http://example.org/fr/contact/?a=1', swiftling_current_url( 'fr' ) );
		$this->assertSame( 'http://example.org/kontakt/?a=1', swiftling_current_url( '' ) );
		$this->assertSame( 'http://example.org/de/kontaktieren/?a=1', swiftling_current_url( 'de' ) );

		$this->assertSame(
			array(
				'hr'        => 'http://example.org/kontakt/',
				'de-DE'     => 'http://example.org/de/kontaktieren/',
				'fr-FR'     => 'http://example.org/fr/contact/',
				'x-default' => 'http://example.org/kontakt/',
			),
			swiftling_hreflangs()
		);
	}

	/**
	 * A separate copy lives at its original's address in its language, translated slug included.
	 */
	public function test_copy_address() {
		$this->become( 'administrator' );
		$this->slug( 'kontakt', 'kontaktieren' );
		$copy = swiftling_make_copy( get_post( $this->ids['kontakt'] ), 'de' );
		wp_publish_post( $copy );
		swiftling_published_copies( 'de_DE', true );
		$this->assertSame( 'http://example.org/de/kontaktieren/', get_permalink( $copy ) );

		$this->in_folder( 'de' );
		$this->go_to( 'http://example.org/de/kontaktieren/' );
		$this->assertSame( $copy, get_queried_object_id() );
	}

	/**
	 * A post's address in a language, for the editor's links.
	 */
	public function test_post_language_url() {
		$this->slug( 'kontakt', 'kontaktieren' );
		$post = get_post( $this->ids['kontakt'] );
		$this->assertSame( 'http://example.org/de/kontaktieren/', swiftling_post_language_url( $post, 'de' ) );
		$this->assertSame( 'http://example.org/fr/kontakt/', swiftling_post_language_url( $post, 'fr' ) );
		$this->assertSame( 'http://example.org/kontakt/', swiftling_post_language_url( $post, '' ) );
	}

	/**
	 * A deleted post or term takes its slugs with it.
	 */
	public function test_deleting_removes_slugs() {
		$this->slug( 'kontakt', 'kontaktieren' );
		swiftling_save_slug( 'term:' . $this->ids['vijesti'], 'de_DE', 'nachrichten' );
		wp_delete_post( $this->ids['kontakt'], true );
		wp_delete_term( $this->ids['vijesti'], 'category' );
		$this->assertSame( array(), $this->rows( 'post:' . $this->ids['kontakt'] ) );
		$this->assertSame( array(), $this->rows( 'term:' . $this->ids['vijesti'] ) );
	}

	/**
	 * Sends a request to a route of SwiftLing.
	 *
	 * @param string $route  Route.
	 * @param array  $params Body.
	 * @return \WP_REST_Response
	 */
	private function call( $route, $params ) {
		$request = new \WP_REST_Request( 'POST', '/swiftling/v1' . $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The editor's route saves the address of a post and answers with it, for people who may translate the post.
	 */
	public function test_slug_route() {
		$params = array(
			'subject' => 'post:' . $this->ids['kontakt'],
			'lang'    => 'de',
			'slug'    => 'Kontaktieren Sie uns',
		);
		$this->become( 'editor' );
		$this->assertSame( 403, $this->call( '/slug', $params )->get_status() );

		$this->become( 'administrator' );
		$response = $this->call( '/slug', $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'slug'     => 'kontaktieren-sie-uns',
				'state'    => 'reviewed',
				'original' => 'kontakt',
				'prefix'   => '/de/',
				'url'      => 'http://example.org/de/kontaktieren-sie-uns/',
			),
			$response->get_data()
		);

		$params['slug'] = '';
		$this->assertSame( 'http://example.org/de/kontakt/', $this->call( '/slug', $params )->get_data()['url'] );

		$params['lang'] = 'it';
		$this->assertSame( 400, $this->call( '/slug', $params )->get_status() );
		$params['lang']    = 'de';
		$params['subject'] = 'terms:category';
		$this->assertSame( 400, $this->call( '/slug', $params )->get_status() );
	}

	/**
	 * The first translation of a title gives the post its address in the language; a slug it has, or one a person removed, stays.
	 */
	public function test_title_translation_makes_the_slug() {
		$post = get_post( $this->ids['kontakt'] );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->become( 'administrator' );
		$place = swiftling_place_key( 'post:' . $post->ID, swiftling_path_key( 'field/post_title' ), swiftling_hash( 'Kontakt' ) );
		$save  = function ( $text, $lang = 'de' ) use ( $post, $place ) {
			return $this->call(
				'/translations',
				array(
					'subject' => 'post:' . $post->ID,
					'lang'    => $lang,
					'status'  => 'machine',
					'items'   => array(
						array(
							'key'  => $place,
							'text' => $text,
						),
					),
				)
			)->get_data()['address'];
		};

		$address = $save( 'Kontaktieren Sie uns' );
		$this->assertSame( 'kontaktieren-sie-uns', $address['slug'] );
		$this->assertSame( 'machine', $address['state'] );
		$this->assertSame( 'contact', $save( 'Contact', 'fr' )['slug'], 'French gets its own' );

		$this->assertSame( 'kontaktieren-sie-uns', $save( 'Etwas anderes' )['slug'], 'A slug it has stays' );

		swiftling_save_slug( 'post:' . $post->ID, 'de_DE', '' );
		$this->assertSame( '', $save( 'Noch etwas' )['slug'], 'One a person removed stays removed' );
	}

	/**
	 * A slug is written as WordPress writes it on a site in its language, not in the site's: German
	 * spells ü, ö, ä and ß as ue, oe, ae and ss, so "Über uns" is ueber-uns, as a German site has it.
	 * French has no such rule. A word in front of a list's addresses is written so too, and an address
	 * typed with the letter itself finds the page.
	 */
	public function test_slug_written_as_the_language_writes_it() {
		$this->assertSame( 'uber-uns', swiftling_sanitize_slug( 'Über uns', '' ), 'The original\'s language' );
		$this->assertSame( 'ueber-uns', swiftling_sanitize_slug( 'Über uns', 'de_DE' ) );
		$this->assertSame( 'uber-uns', swiftling_sanitize_slug( 'Über uns', 'fr_FR' ), 'French has no such rule' );

		$post = get_post( $this->ids['o-nama'] );
		$this->check(
			'post:' . $post->ID,
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->become( 'administrator' );
		$place = swiftling_place_key( 'post:' . $post->ID, swiftling_path_key( 'field/post_title' ), swiftling_hash( $post->post_title ) );
		$save  = function ( $text, $lang ) use ( $post, $place ) {
			return $this->call(
				'/translations',
				array(
					'subject' => 'post:' . $post->ID,
					'lang'    => $lang,
					'status'  => 'machine',
					'items'   => array(
						array(
							'key'  => $place,
							'text' => $text,
						),
					),
				)
			)->get_data()['address'];
		};
		$this->assertSame( 'ueber-uns', $save( 'Über uns', 'de' )['slug'], 'Made from the translated title' );
		$this->assertSame( 'a-propos-de-nous', $save( 'À propos de nous', 'fr' )['slug'] );
		$this->assertSame( 'groesse', $this->slug( 'kontakt', 'Größe' ), 'Typed by a person' );
		$this->assertSame( 'nachrichten-uebersicht', swiftling_save_base( 'base:tax:category:0', 'de_DE', 'Nachrichten-Übersicht' ) );

		// A slug saved before slugs were written by the language's rules.
		$this->slug( 'tim', 'grun-team' );

		$this->in_folder( 'de' );
		// On a page in German WordPress's own language is German, as SwiftLing switches it.
		$german = static function () {
			return 'de_DE';
		};
		add_filter( 'locale', $german );
		$this->assertSame( 'uber-uns', swiftling_sanitize_slug( 'Über uns', '' ), 'The original\'s rules, also on a page in German' );
		$this->go_to( 'http://example.org/de/' . rawurlencode( 'über-uns' ) . '/' );
		$this->assertSame( $this->ids['o-nama'], get_queried_object_id(), 'Typed with the letter itself' );
		$this->go_to( 'http://example.org/de/ueber-uns/' . rawurlencode( 'grün-team' ) . '/' );
		$this->assertSame( $this->ids['tim'], get_queried_object_id(), 'A slug saved before, typed with the letter itself' );
		remove_filter( 'locale', $german );
	}

	/**
	 * The editor shows the address in the language beside the texts, with the folder and the pages above.
	 */
	public function test_editor_card() {
		$this->slug( 'tim', 'team' );
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$_GET = array(
			'post' => (string) $this->ids['tim'],
			'lang' => 'de',
		);
		ob_start();
		( new \SwiftLing\Admin\Menu() )->renderScreen( 'editor' );
		$html = ob_get_clean();
		set_current_screen( 'front' );

		$this->assertStringContainsString( 'Address in Deutsch', $html );
		$this->assertStringContainsString( 'id="swiftling-address-prefix">/de/o-nama/</code>', $html );
		$this->assertMatchesRegularExpression( '~id="swiftling-address-slug"[^>]*value="team"[^>]*placeholder="tim"~', $html );
		$this->assertStringContainsString( 'href="http://example.org/de/o-nama/team/"', $html );
	}
}
