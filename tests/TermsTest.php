<?php
/**
 * Tests of categories, tags and other lists of terms: which lists are translated, and the fields
 * themes and plugins keep with a term.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use WP_REST_Request;

/**
 * The lists of terms visitors read, and the texts of their terms: names, descriptions and fields.
 */
class TermsTest extends TestCase {

	/**
	 * A category with a text field of the theme.
	 *
	 * @var int
	 */
	private $news = 0;

	/**
	 * Sets up a category with fields: a text its page shows, a text it does not show and settings.
	 */
	public function set_up() {
		parent::set_up();
		$this->news = self::factory()->category->create(
			array(
				'name'        => 'Vijesti',
				'description' => 'Najnovije iz apartmana',
			)
		);
		add_term_meta( $this->news, 'banner_text', 'Ljeto je stiglo na otok' );
		add_term_meta( $this->news, 'menu_subtitle', 'Sve novosti na jednom mjestu' );
		add_term_meta( $this->news, 'display_type', 'products' );
		add_term_meta( $this->news, 'order', '3' );
		add_term_meta( $this->news, 'thumbnail_id', '12' );
	}

	/**
	 * Takes away the lists and types a test registered.
	 */
	public function tear_down() {
		foreach ( array( 'pa_boja', 'library_category', 'pack' ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		if ( post_type_exists( 'library' ) ) {
			unregister_post_type( 'library' );
		}
		parent::tear_down();
	}

	/**
	 * Sends a request to a route of SwiftLing.
	 *
	 * @param string $route  Route, e.g. "/probe".
	 * @param array  $params Body.
	 * @return \WP_REST_Response
	 */
	private function call( $route, $params ) {
		$request = new WP_REST_Request( 'POST', '/swiftling/v1' . $route );
		$request->set_body_params( $params );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Checks the page of a term, as the editor of its list does: the page reads the term's fields,
	 * and each lands where the test says, as visible text by default.
	 *
	 * @param string   $key      Subject key, e.g. "terms:category".
	 * @param callable $render   Reads the values, as the archive of the term would.
	 * @param array    $landings Path => where its marker landed, or null for nowhere.
	 * @param array    $readers  Meta key => package that read it, as the page reports it.
	 * @return \WP_REST_Response
	 */
	private function check_term_page( $key, $render, $landings = array(), $readers = array() ) {
		$leaves = $this->probe( $render );
		$found  = array();
		foreach ( $leaves as $id => $leaf ) {
			$where = array_key_exists( $leaf['p'], $landings ) ? $landings[ $leaf['p'] ] : 'text';
			if ( null !== $where ) {
				$found[ $id ] = array( $this->landing( $where ) );
			}
		}
		$registry = wp_json_encode(
			array(
				'subject'     => $key,
				'template'    => '',
				'leaves'      => (object) $leaves,
				'term_fields' => (object) $readers,
			)
		);
		return $this->call(
			'/probe',
			array(
				'subject'   => $key,
				'lang'      => 'de',
				'registry'  => $registry,
				'signature' => swiftling_probe_signature( $registry ),
				'found'     => $found,
				'control'   => array(),
			)
		);
	}

	/**
	 * Returns the fields of the categories, by meta key.
	 *
	 * @return array
	 */
	private function fields() {
		return swiftling_term_fields( swiftling_subject( 'terms:category' ) );
	}

	/**
	 * Returns the values of the texts the editor of the categories lists.
	 *
	 * @return string[]
	 */
	private function listed() {
		return wp_list_pluck( swiftling_save_term_list( swiftling_subject( 'terms:category' ) )['units'], 'v' );
	}

	/**
	 * Reads a field of the news category on a page in German.
	 *
	 * @param string $key Meta key.
	 * @return mixed
	 */
	private function read_in_german( $key ) {
		$this->reset_state();
		swiftling_start( 'translate', 'de' );
		$value = get_term_meta( $this->news, $key, true );
		$this->reset_state();
		return $value;
	}

	/**
	 * A list a person manages in the admin, of content visitors open, is translated, also without
	 * archives of its own, as WooCommerce's attributes are unless their archives are switched on.
	 * Lists of content visitors never open, such as Divi Library's, and lists nobody manages are not.
	 */
	public function test_lists_of_content_visitors_open() {
		register_taxonomy(
			'pa_boja',
			'post',
			array(
				'public'  => false,
				'show_ui' => true,
				'label'   => 'Boja',
			)
		);
		register_post_type(
			'library',
			array(
				'public'             => false,
				'publicly_queryable' => true,
				'show_ui'            => true,
			)
		);
		register_taxonomy(
			'library_category',
			'library',
			array(
				'public'  => true,
				'show_ui' => true,
			)
		);
		register_taxonomy(
			'pack',
			'post',
			array(
				'public'  => true,
				'show_ui' => false,
			)
		);

		$this->assertNotNull( swiftling_subject( 'terms:pa_boja' ) );
		$this->assertNull( swiftling_subject( 'terms:library_category' ) );
		$this->assertNull( swiftling_subject( 'terms:pack' ) );

		$lists = array_keys( swiftling_term_taxonomies() );
		$this->assertContains( 'category', $lists );
		$this->assertContains( 'post_tag', $lists );
		$this->assertContains( 'pa_boja', $lists );
		$this->assertNotContains( 'library_category', $lists );
		$this->assertNotContains( 'pack', $lists );
		$this->assertNotContains( 'post_format', $lists );
	}

	/**
	 * The editor of a list checks the page of its most used term, where the term's fields show.
	 * A list without pages of its own is not checked.
	 */
	public function test_list_is_checked_on_a_term_page() {
		self::factory()->post->create( array( 'post_category' => array( $this->news ) ) );
		$subject = swiftling_subject( 'terms:category' );
		$this->assertTrue( $subject['probe'] );
		$this->assertSame( get_term_link( $this->news ), $subject['url'] );

		register_taxonomy(
			'pa_boja',
			'post',
			array(
				'public'  => false,
				'show_ui' => true,
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'pa_boja',
				'name'     => 'Crvena',
			)
		);
		$subject = swiftling_subject( 'terms:pa_boja' );
		$this->assertFalse( $subject['probe'] );
		$this->assertSame( '', $subject['url'] );
	}

	/**
	 * A field the page of a term shows as text is translated with the term's name, on every page in
	 * the language. A field the page shows only inside a class is a setting.
	 */
	public function test_field_shown_on_the_page() {
		$this->become( 'administrator' );
		$this->assertSame( array( 'banner_text', 'menu_subtitle' ), array_keys( $this->fields() ), 'Settings such as the display type, the order and the image are never offered' );
		$this->assertNull( $this->fields()['banner_text']['shown'], 'Not checked yet' );
		$this->assertNotContains( 'Ljeto je stiglo na otok', $this->listed(), 'Not translated before it is known as text' );

		$response = $this->check_term_page(
			'terms:category',
			function () {
				get_term_meta( $this->news, 'banner_text', true );
				get_term_meta( $this->news, 'display_type', true );
			},
			array( 'meta/display_type' => 'class' )
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Ljeto je stiglo na otok', $response->get_data()['rows'] );
		$this->assertTrue( $this->fields()['banner_text']['shown'] );
		$this->assertNull( $this->fields()['menu_subtitle']['shown'], 'The page did not show it' );
		$this->assertContains( 'Ljeto je stiglo na otok', $this->listed() );
		$this->assertNotContains( 'Sve novosti na jednom mjestu', $this->listed() );

		$this->assertSame( 'Ljeto je stiglo na otok', $this->read_in_german( 'banner_text' ), 'No translation yet: the original' );
		$this->translate( 'term:' . $this->news, 'meta/banner_text', 'Ljeto je stiglo na otok', 'de', 'Der Sommer ist auf der Insel angekommen' );
		$this->assertSame( 'Der Sommer ist auf der Insel angekommen', $this->read_in_german( 'banner_text' ) );
		$this->assertSame( 'Ljeto je stiglo na otok', get_term_meta( $this->news, 'banner_text', true ), 'The original stays as it is' );
		$this->assertSame( 'products', $this->read_in_german( 'display_type' ) );
	}

	/**
	 * A field the page does not show stays the original until the user chooses to translate it;
	 * once chosen to keep the original again, its translation no longer shows.
	 */
	public function test_field_translated_when_chosen() {
		$this->become( 'administrator' );
		$this->assertSame( '', $this->fields()['menu_subtitle']['choice'] );

		swiftling_choose_term_field( 'category', 'menu_subtitle', 'translate' );
		$this->assertSame( 'translate', $this->fields()['menu_subtitle']['choice'] );
		$this->assertContains( 'Sve novosti na jednom mjestu', $this->listed() );
		$this->translate( 'term:' . $this->news, 'meta/menu_subtitle', 'Sve novosti na jednom mjestu', 'de', 'Alle Neuigkeiten an einem Ort' );
		$this->assertSame( 'Alle Neuigkeiten an einem Ort', $this->read_in_german( 'menu_subtitle' ) );

		swiftling_choose_term_field( 'category', 'menu_subtitle', 'keep' );
		$this->assertNotContains( 'Sve novosti na jednom mjestu', $this->listed() );
		$this->assertSame( 'Sve novosti na jednom mjestu', $this->read_in_german( 'menu_subtitle' ), 'Kept: the original in every language' );

		swiftling_choose_term_field( 'category', 'menu_subtitle', 'nonsense' );
		$this->assertSame( 'keep', $this->fields()['menu_subtitle']['choice'], 'Only translate or keep is saved' );
	}

	/**
	 * A field kept as the original stays so also where the page shows it as text.
	 */
	public function test_shown_field_kept() {
		$this->become( 'administrator' );
		$this->check_term_page(
			'terms:category',
			function () {
				get_term_meta( $this->news, 'banner_text', true );
			}
		);
		$this->translate( 'term:' . $this->news, 'meta/banner_text', 'Ljeto je stiglo na otok', 'de', 'Der Sommer ist da' );
		swiftling_choose_term_field( 'category', 'banner_text', 'keep' );
		$this->assertNotContains( 'Ljeto je stiglo na otok', $this->listed() );
		$this->assertSame( 'Ljeto je stiglo na otok', $this->read_in_german( 'banner_text' ) );
	}

	/**
	 * The choice is made with a form of the editor, by a person who may edit the list's terms.
	 */
	public function test_choice_form() {
		$this->become( 'editor' );
		$this->post_form(
			'swiftling_term_field_category',
			array(
				'action'  => 'swiftling_term_field',
				'subject' => 'terms:category',
				'key'     => 'menu_subtitle',
				'choice'  => 'translate',
				'lang'    => 'de',
			)
		);
		$this->expectException( \WPDieException::class );
		try {
			swiftling_handle_term_field_choice();
		} finally {
			$this->assertSame( '', swiftling_term_field_choice( 'category', 'menu_subtitle' ) );
		}
	}

	/**
	 * An administrator's choice is saved and leads back to the editor in the same language.
	 */
	public function test_choice_form_saves() {
		$this->become( 'administrator' );
		$this->post_form(
			'swiftling_term_field_category',
			array(
				'action'  => 'swiftling_term_field',
				'subject' => 'terms:category',
				'key'     => 'menu_subtitle',
				'choice'  => 'translate',
				'lang'    => 'de',
			)
		);
		$location = $this->redirect_of( 'swiftling_handle_term_field_choice' );
		$this->assertSame( 'translate', swiftling_term_field_choice( 'category', 'menu_subtitle' ) );
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( 'swiftling-translation', $query['page'] );
		$this->assertSame( 'terms:category', $query['subject'] );
		$this->assertSame( 'de', $query['lang'] );
	}

	/**
	 * The editor shows the texts of each term under the term's place in its list, so a description
	 * or a field reads as the term's.
	 */
	public function test_rows_under_their_term() {
		$sport = self::factory()->category->create( array( 'name' => 'Sport' ) );
		self::factory()->category->create(
			array(
				'name'        => 'Nogomet',
				'parent'      => $sport,
				'description' => 'Utakmice',
			)
		);
		$subject = swiftling_subject( 'terms:category' );
		$html    = swiftling_live_rows_html( $subject, 'de', swiftling_save_term_list( $subject ) );

		$this->assertStringContainsString( '<h2>Sport › Nogomet</h2>', $html );
		$this->assertStringContainsString( '<h2>Vijesti</h2>', $html );
		$football = strpos( $html, '<h2>Sport › Nogomet</h2>' );
		$this->assertGreaterThan( $football, strpos( $html, 'Utakmice' ), 'The description under its term' );
		$this->assertSame( 4, substr_count( $html, 'swiftling-live-group is-term' ), 'One group per term: Uncategorized, Vijesti, Sport, Nogomet' );
	}

	/**
	 * Under a term, the fields its page does not show wait folded, each with Translate and Keep
	 * original; a field the page shows is a row, not folded.
	 */
	public function test_fields_not_shown_are_folded_under_the_term() {
		$this->become( 'administrator' );
		$this->check_term_page(
			'terms:category',
			function () {
				get_term_meta( $this->news, 'banner_text', true );
			}
		);
		$subject = swiftling_subject( 'terms:category' );
		$html    = swiftling_live_rows_html( $subject, 'de', swiftling_save_term_list( $subject ) );
		$this->assertSame( 1, substr_count( $html, 'class="swiftling-term-more"' ), 'Only Vijesti has a field not shown' );
		$this->assertStringContainsString( '1 more field, not shown on the page', $html );
		$this->assertStringContainsString( 'Sve novosti na jednom mjestu', $html );
		$this->assertStringContainsString( 'name="key" value="menu_subtitle"', $html );
		$this->assertStringNotContainsString( 'name="key" value="banner_text"', $html, 'Shown on the page: a row of its own' );
		$this->assertSame( 2, substr_count( $html, 'name="_wpnonce" value="' . wp_create_nonce( 'swiftling_term_field_category' ) . '"' ) );
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html, 'No element ID twice' );
	}

	/**
	 * A field says which theme or plugin it comes from: the one the check of the page saw reading it.
	 * A reader that is no active theme or plugin is not believed; without a reader nothing is said.
	 */
	public function test_field_source() {
		switch_theme( 'twentytwentyfive' );
		\SwiftLing\Packages\Packages::forget();
		$this->become( 'administrator' );
		$this->assertSame( '', $this->fields()['banner_text']['source'] );

		$this->check_term_page(
			'terms:category',
			function () {
				get_term_meta( $this->news, 'banner_text', true );
			},
			array(),
			array(
				'banner_text'   => 'theme/twentytwentyfive',
				'menu_subtitle' => 'plugin/not-active',
			)
		);
		$this->assertSame( 'Twenty Twenty-Five', $this->fields()['banner_text']['source'] );
		$this->assertSame( '', $this->fields()['menu_subtitle']['source'] );

		$subject = swiftling_subject( 'terms:category' );
		$this->assertStringContainsString( 'Twenty Twenty-Five', swiftling_live_rows_html( $subject, 'de', swiftling_save_term_list( $subject ) ) );
	}

	/**
	 * In a check of a list's page, the theme or plugin whose code reads a field is noted, once per
	 * field; WordPress, SwiftLing and the tests themselves are no theme or plugin.
	 */
	public function test_field_readers_noted_in_a_check() {
		$this->probe(
			function () {
				get_term_meta( $this->news, 'banner_text', true );
			}
		);
		$this->assertSame( array(), swiftling_term_meta_callers(), 'Code outside themes and plugins names no one' );

		// A plugin's own code reads the field.
		$dir = WP_PLUGIN_DIR . '/swl-reader';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/reader.php', '<?php function swl_reader_banner( $id ) { return get_term_meta( $id, "banner_text", true ); }' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A plugin file for the test.
		require_once $dir . '/reader.php';
		try {
			$this->probe(
				function () {
					swl_reader_banner( $this->news );
					swl_reader_banner( $this->news );
				}
			);
			$this->assertSame( array( 'banner_text' => 'plugin/swl-reader' ), swiftling_term_meta_callers() );
		} finally {
			wp_delete_file( $dir . '/reader.php' );
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The test's own folder.
			$callers = &swiftling_term_meta_callers();
			$callers = array();
		}
	}

	/**
	 * The editor shows one page of terms at a time, and finds terms by their name; the list and its
	 * state in each language still count every term.
	 */
	public function test_pages_and_search() {
		for ( $i = 1; $i <= 25; $i++ ) {
			self::factory()->category->create(
				array(
					'name'        => sprintf( 'Tema %02d', $i ),
					'description' => '',
				)
			);
		}
		$subject = swiftling_subject( 'terms:category' );
		$result  = swiftling_save_term_list( $subject );

		$first = swiftling_term_list_page( $result, array() );
		$this->assertSame( 1, $first['list']['page'] );
		$this->assertSame( 2, $first['list']['pages'] );
		$this->assertSame( 27, $first['list']['shown'], 'Vijesti, Uncategorized and the 25 topics' );
		$this->assertSame( SWIFTLING_TERMS_PER_PAGE, count( array_unique( wp_list_pluck( $first['sections']['page'], 'o' ) ) ) );
		$this->assertSame( $result['maps'], $first['maps'] );

		$second = swiftling_term_list_page( $result, array( 'page' => 2 ) );
		$this->assertSame( 27 - SWIFTLING_TERMS_PER_PAGE, count( array_unique( wp_list_pluck( $second['sections']['page'], 'o' ) ) ) );

		$found = swiftling_term_list_page( $result, array( 'search' => 'tema 07' ) );
		$this->assertSame( 1, $found['list']['shown'] );
		$this->assertSame( array( 'Tema 07' ), wp_list_pluck( $found['sections']['page'], 'v' ) );

		$this->assertSame( 0, swiftling_term_list_page( $result, array( 'search' => 'nema' ) )['list']['shown'] );
		$this->assertSame( 2, swiftling_term_list_page( $result, array( 'page' => 99 ) )['list']['page'], 'A page past the end shows the last' );
	}

	/**
	 * Renders a screen as an administrator sees it.
	 *
	 * @param string $view View.
	 * @param array  $get  Parameters of the address.
	 * @return string
	 */
	private function screen( $view, $get = array() ) {
		$this->become( 'administrator' );
		set_current_screen( 'toplevel_page_swiftling' );
		$_GET = $get;
		ob_start();
		( new Menu() )->renderScreen( $view );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );
		return $html;
	}

	/**
	 * Returns the configuration the editor prints for its script.
	 *
	 * @param string $html The editor.
	 * @return array
	 */
	private function editor_config( $html ) {
		$this->assertSame( 1, preg_match( '~<script type="application/json" id="swiftling-editor-config">(.*?)</script>~s', $html, $match ) );
		return json_decode( $match[1], true );
	}

	/**
	 * The Categories & tags screen lists every translated list under its content, also an attribute
	 * without archives, and says which lists it leaves out; the Templates screen no longer lists them.
	 */
	public function test_screen_lists() {
		register_taxonomy(
			'pa_boja',
			'post',
			array(
				'public'  => false,
				'show_ui' => true,
				'labels'  => array( 'name' => 'Boja' ),
			)
		);
		register_post_type(
			'library',
			array(
				'public'             => false,
				'publicly_queryable' => true,
				'show_ui'            => true,
			)
		);
		register_taxonomy(
			'library_category',
			'library',
			array(
				'public'  => true,
				'show_ui' => true,
				'labels'  => array( 'name' => 'Library categories' ),
			)
		);
		$html = $this->screen( 'terms' );
		$this->assertStringContainsString( 'Boja', $html );
		$this->assertStringContainsString( 'None yet', $html, 'A list without terms has nothing to translate' );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'terms:category' ) ), $html );
		$this->assertStringNotContainsString( esc_url( swiftling_editor_url( 'terms:library_category' ) ), $html );
		$this->assertStringContainsString( 'Why are some lists not here?', $html );
		$this->assertStringContainsString( 'Library categories (Posts)', $html, 'A list left out says what it belongs to' );

		$this->assertStringNotContainsString( 'terms%3Acategory', $this->screen( 'templates' ) );
		$this->assertStringNotContainsString( 'terms:category', $this->screen( 'templates' ) );
	}

	/**
	 * The editor of a list checks its term's page while a field may show there, and then shows the
	 * rows with the fields. Once checked, the rows come at once, with the field's choice.
	 */
	public function test_editor_checks_then_lists() {
		self::factory()->post->create( array( 'post_category' => array( $this->news ) ) );
		$get = array(
			'subject' => 'terms:category',
			'lang'    => 'de',
		);

		$config = $this->editor_config( $this->screen( 'editor', $get ) );
		$this->assertNotNull( $config['probes'], 'A field may show on the page: it is checked' );
		$this->assertNull( $config['rows'] );

		$response = $this->check_term_page(
			'terms:category',
			function () {
				get_term_meta( $this->news, 'banner_text', true );
			}
		);
		$this->assertStringContainsString( 'Ljeto je stiglo na otok', $response->get_data()['rows'] );
		$this->assertStringContainsString( 'more field, not shown on the page', $response->get_data()['rows'] );
		$this->assertArrayNotHasKey( 'fields', $response->get_data() );

		$html   = $this->screen( 'editor', $get );
		$config = $this->editor_config( $html );
		$this->assertNull( $config['probes'], 'Checked: no check again' );
		$this->assertStringContainsString( 'Ljeto je stiglo na otok', $config['rows']['rows'] );
		$this->assertStringContainsString( 'Banner text', $config['rows']['rows'] );
		$this->assertStringContainsString( 'menu_subtitle', $config['rows']['rows'] );
		$this->assertStringNotContainsString( 'Fields of the terms', $html, 'No list of fields at the side' );

		// A new field of the terms is checked for again.
		add_term_meta( $this->news, 'footer_note', 'Dobrodošli na otok' );
		$this->assertNotNull( $this->editor_config( $this->screen( 'editor', $get ) )['probes'] );
	}

	/**
	 * A long list shows its pages and a search; a short one neither.
	 */
	public function test_editor_pages() {
		$get = array(
			'subject' => 'terms:post_tag',
			'lang'    => 'de',
		);
		self::factory()->tag->create( array( 'name' => 'Plaža' ) );
		$this->assertStringNotContainsString( 'swiftling-list-filters', $this->screen( 'editor', $get ) );

		for ( $i = 1; $i <= SWIFTLING_TERMS_PER_PAGE; $i++ ) {
			self::factory()->tag->create( array( 'name' => sprintf( 'Oznaka %02d', $i ) ) );
		}
		$html = $this->screen( 'editor', $get );
		$this->assertStringContainsString( 'swiftling-list-filters', $html );
		$this->assertStringContainsString( 'Search terms', $html );
		$this->assertStringContainsString( 'paged=2', $html );
		$this->assertTrue( $this->editor_config( $html )['paged'], 'Its languages count the whole list' );

		$html = $this->screen( 'editor', $get + array( 'find' => 'plaza' ) );
		$rows = $this->editor_config( $html )['rows']['rows'];
		$this->assertStringContainsString( 'Plaža', $rows );
		$this->assertStringNotContainsString( 'Oznaka 01', $rows );
	}

	/**
	 * Each term of a list with pages of its own gets an Address row in the editor: its original
	 * slug, and the field for the slug in the language. A list without pages has none.
	 */
	public function test_address_row() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		$this->use_pretty_permalinks();
		swiftling_save_slug( 'term:' . $this->news, 'de_DE', 'neuigkeiten' );
		$subject = swiftling_subject( 'terms:category' );
		$html    = swiftling_live_rows_html( $subject, 'de', swiftling_save_term_list( $subject ) );

		$this->assertStringContainsString( 'class="swiftling-term-address"', $html );
		$this->assertStringContainsString( 'data-term="' . $this->news . '"', $html );
		$this->assertMatchesRegularExpression( '~value="neuigkeiten"[^>]*placeholder="vijesti"|placeholder="vijesti"[^>]*value="neuigkeiten"~', $html );
		$this->assertStringContainsString( '/de/category/', $html );
		$this->assertStringNotContainsString( 'id="swiftling-address', $html, 'No element ID that repeats for every term' );

		register_taxonomy(
			'pa_boja',
			'post',
			array(
				'public'  => false,
				'show_ui' => true,
			)
		);
		self::factory()->term->create(
			array(
				'taxonomy' => 'pa_boja',
				'name'     => 'Crvena',
			)
		);
		$subject = swiftling_subject( 'terms:pa_boja' );
		$this->assertStringNotContainsString( 'swiftling-term-address', swiftling_live_rows_html( $subject, 'de', swiftling_save_term_list( $subject ) ), 'No pages, no address' );
	}

	/**
	 * The slug of a term is saved from its list's editor, through SwiftLing's slugs: the term's links in
	 * the language use it. Only a term of the list, by someone who may translate it.
	 */
	public function test_address_saved_from_the_editor() {
		$this->set_languages(
			array(
				'de_DE' => 'de',
				'fr_FR' => 'fr',
			),
			'directory'
		);
		$this->use_pretty_permalinks();
		$this->become( 'administrator' );
		$params = array(
			'subject' => 'terms:category',
			'lang'    => 'de',
			'term'    => $this->news,
			'slug'    => 'Neuigkeiten',
		);

		$response = $this->call( '/slug', $params );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'neuigkeiten', $response->get_data()['slug'] );
		$this->assertSame( 'vijesti', $response->get_data()['original'] );
		$this->assertSame( '/de/category/', $response->get_data()['prefix'] );
		$this->assertSame( 'http://example.org/de/category/neuigkeiten/', $response->get_data()['url'] );
		$this->assertSame( 'http://example.org/category/neuigkeiten/', swiftling_in_link_language( 'de', fn() => get_term_link( $this->news ) ), 'Links in German carry the slug' );
		$this->assertSame( 'http://example.org/category/vijesti/', get_term_link( $this->news ), 'The original keeps its own' );

		$params['slug'] = '';
		$this->assertSame( 'http://example.org/de/category/vijesti/', $this->call( '/slug', $params )->get_data()['url'], 'Empty: the original slug' );

		$tag = self::factory()->tag->create( array( 'name' => 'Plaža' ) );
		$this->assertSame( 400, $this->call( '/slug', array( 'term' => $tag ) + $params )->get_status(), 'A term of another list' );
		$this->assertSame( 400, $this->call( '/slug', array( 'term' => 0 ) + $params )->get_status() );

		$this->become( 'editor' );
		$this->assertSame( 403, $this->call( '/slug', $params )->get_status() );
	}
}
