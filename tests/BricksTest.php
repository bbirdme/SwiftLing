<?php
/**
 * Tests of the Bricks class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Bricks;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeBricks.php';

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Bricks' own filters, applied as the theme applies them.

/**
 * What SwiftLing does for a site built with the Bricks theme: its builder edits the original also
 * at a language's address, its templates are translated where they show, the fields it declares
 * as text are found also when they look like a setting, the texts that show only after a form is
 * sent are offered, and what visitors load later is answered in the language of the page.
 */
class BricksTest extends TestCase {

	/**
	 * The class, with the elements of the theme.
	 *
	 * @var FakeBricks
	 */
	private $bricks;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the theme.
	 */
	public function set_up() {
		parent::set_up();
		FakeBricks::install();
		$this->bricks = new FakeBricks();
		$builders     = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof Bricks ? $this->bricks : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->bricks->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		unset( $_GET['bricks'], $_GET['brickspreview'] );
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		unregister_post_type( 'bricks_template' );
		parent::tear_down();
	}

	/**
	 * Makes a post built with Bricks: its elements in the meta Bricks keeps them in.
	 *
	 * @param array  $elements Elements, as Bricks stores them.
	 * @param array  $post     Fields of the post.
	 * @param string $area     content, header or footer.
	 * @return int Post ID.
	 */
	private function built( array $elements, array $post = array(), $area = 'content' ) {
		$id = self::factory()->post->create( $post + array( 'post_type' => 'page' ) );
		update_post_meta( $id, '_bricks_page_' . $area . '_2', $elements );
		update_post_meta( $id, '_bricks_editor_mode', 'bricks' );
		return $id;
	}

	/**
	 * An element as Bricks stores it.
	 *
	 * @param string $id       Element ID.
	 * @param string $name     Element name.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private static function element( $id, $name, array $settings ) {
		return array(
			'id'       => $id,
			'name'     => $name,
			'parent'   => 0,
			'children' => array(),
			'settings' => $settings,
		);
	}

	/**
	 * Takes away what an earlier start of translation in this test hooked, as a new request starts
	 * without it.
	 */
	private function new_request() {
		foreach ( swiftling_source_hooks( 'translate' ) as $hook ) {
			remove_filter( $hook[0], $hook[1], $hook[2] );
		}
		$this->reset_state();
	}

	/**
	 * Reads a post's Bricks content as a page check does and returns the marked values.
	 *
	 * @param int $id Post ID.
	 * @return string[] Values, by path.
	 */
	private function marked( $id ) {
		$values = array();
		foreach ( $this->probe(
			static function () use ( $id ) {
				get_post_meta( $id, '_bricks_page_content_2', true );
			}
		) as $leaf ) {
			$values[ $leaf['p'] ] = $leaf['v'];
		}
		return $values;
	}

	/**
	 * The Bricks builder runs on the front end, at the address of the page it edits. Opened at a
	 * language's address, through "Edit with Bricks" on a translated page, it read the translated
	 * texts, and saving wrote them over the original: measured in Bricks 2.4.4, where changing one
	 * heading saved every translated text of the page into the original. Its window and the preview
	 * inside it read every stored value as it is, so what it saves is the original.
	 */
	public function test_builder_edits_the_original() {
		$id   = $this->built( array( self::element( 'aaaaaa', 'heading', array( 'text' => 'Svjež kruh svako jutro' ) ) ) );
		$path = 'meta/_bricks_page_content_2/#aaaaaa/settings/text';
		$this->check(
			'post:' . $id,
			static function () use ( $id ) {
				get_post_meta( $id, '_bricks_page_content_2', true );
			}
		);
		$this->translate( 'post:' . $id, $path, 'Svjež kruh svako jutro', 'de', 'Frisches Brot jeden Morgen' );

		$this->show_in( 'de' );
		$this->assertSame( 'Frisches Brot jeden Morgen', get_post_meta( $id, '_bricks_page_content_2', true )[0]['settings']['text'], 'The page itself is translated' );

		$_GET['bricks'] = 'run';
		$this->new_request();
		$this->show_in( 'de' );
		$this->assertSame( 'Svjež kruh svako jutro', get_post_meta( $id, '_bricks_page_content_2', true )[0]['settings']['text'], 'The builder' );
		$this->assertSame( array( 'translate', 'de' ), array( swiftling_mode(), swiftling_state()['language'] ), 'The address still names the language, so the page and its translated slug are found' );

		$_GET['brickspreview'] = '1';
		$this->new_request();
		$this->show_in( 'de' );
		$this->assertSame( 'Svjež kruh svako jutro', get_post_meta( $id, '_bricks_page_content_2', true )[0]['settings']['text'], 'The preview inside the builder' );
	}

	/**
	 * "Edit with Bricks" on a translated page opens the builder at the original's address, where it
	 * shows what it edits: the original. A separate copy is a post of its own and keeps its address.
	 */
	public function test_edit_link_opens_the_original() {
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$this->use_pretty_permalinks();
		$id  = $this->built( array(), array( 'post_name' => 'o-nama' ) );
		$url = add_query_arg( 'bricks', 'run', get_permalink( $id ) );

		$this->show_in( 'de' );
		$this->assertSame( $url, apply_filters( 'bricks/get_builder_edit_link', add_query_arg( 'bricks', 'run', swiftling_language_url( get_permalink( $id ), 'de' ) ), $id ) );

		$copy = $this->built( array(), array( 'post_name' => 'ueber-uns' ) );
		update_post_meta( $copy, SWIFTLING_COPY_LANG, 'de_DE' );
		update_post_meta( $copy, SWIFTLING_COPY_OF, $id );
		$link = add_query_arg( 'bricks', 'run', get_permalink( $copy ) );
		$this->assertSame( $link, apply_filters( 'bricks/get_builder_edit_link', $link, $copy ), 'A separate copy is edited where it is' );

		$this->reset_state();
		$this->assertSame( $url, apply_filters( 'bricks/get_builder_edit_link', $url, $id ), 'On the original nothing changes' );
	}

	/**
	 * Bricks keeps its header, footer, popups, section templates and the templates of single posts
	 * and archives as posts of its own type. Visitors can open that type, to edit a template, but it
	 * is left out of search: it is no page of its own, so its texts are translated where they show.
	 * Before, the page check found them and no editor offered them.
	 */
	public function test_templates_are_translated_where_they_show() {
		$section = self::factory()->post->create( array( 'post_type' => 'bricks_template' ) );
		$header  = self::factory()->post->create( array( 'post_type' => 'bricks_template' ) );
		$footer  = self::factory()->post->create( array( 'post_type' => 'bricks_template' ) );
		$popup   = self::factory()->post->create( array( 'post_type' => 'bricks_template' ) );
		update_post_meta( $section, '_bricks_template_type', 'section' );
		update_post_meta( $header, '_bricks_template_type', 'header' );
		update_post_meta( $footer, '_bricks_template_type', 'footer' );
		update_post_meta( $popup, '_bricks_template_type', 'popup' );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$unit = static function ( $owner, $content, $region = '' ) {
			return array(
				'o'       => 'post:' . $owner,
				'p'       => 'meta/_bricks_page_content_2/#aaaaaa/settings/text',
				'kind'    => 'paragraph',
				'region'  => $region,
				'content' => $content,
			);
		};

		$this->assertSame( 'page', swiftling_unit_section( $unit( $section, true ), 'post:' . $page, true ), 'A section template placed in the page' );
		$this->assertSame( 'page', swiftling_template_unit_section( $unit( $popup, false ) ), 'A popup is the template\'s own text' );
		$this->assertSame( 'site', swiftling_template_unit_section( $unit( $header, false ) ) );
		$this->assertSame( 'header', swiftling_unit_site_part( $unit( $header, false, 'main' ) ) );
		$this->assertSame( 'footer', swiftling_unit_site_part( $unit( $footer, false, 'main' ) ), 'Bricks says it, whatever tag it prints' );
		$this->assertSame( '', $this->bricks->sitePart( 'post:' . $popup ) );
		$this->assertSame( '', $this->bricks->sitePart( 'post:' . $page ) );

		$other = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$title = array( 'p' => 'field/post_title' ) + $unit( $other, true );
		$this->assertSame( '', swiftling_unit_section( $title, 'post:' . $page, true ), 'The title of another page listed in this one is translated in its own editor' );
		$this->assertSame( 'page', swiftling_unit_section( array( 'p' => 'field/post_title' ) + $unit( $section, true ), 'post:' . $page, true ), 'A template has no editor of its own' );
	}

	/**
	 * A field Bricks declares as text is found also when it looks like a setting, as a short phrase
	 * in lowercase; a style, a CSS class or ID, executable code and an identifier stay unmarked.
	 */
	public function test_fields_bricks_declares() {
		$id     = $this->built(
			array(
				self::element(
					'aaaaaa',
					'heading',
					array(
						'text'        => 'Fresh bread',
						'tag'         => 'h2',
						'_cssClasses' => 'Hero Title',
						'_cssId'      => 'Main Heading',
						'_typography' => array( 'font-family' => 'Open Sans' ),
						'_attributes' => array(
							array(
								'id'    => 'at1',
								'name'  => 'data-Label',
								'value' => 'Big One',
							),
						),
					)
				),
				self::element(
					'bbbbbb',
					'animated-typing',
					array(
						'prefix'     => 'We cook ',
						'suffix'     => ' for you',
						'strings'    => array( array( 'text' => 'pasta' ), array( 'text' => 'soup' ) ),
						'cursorChar' => '|',
					)
				),
				self::element(
					'cccccc',
					'post-reading-time',
					array(
						'prefix' => 'Reading time: ',
						'suffix' => ' minutes',
					)
				),
				self::element( 'dddddd', 'video', array( 'youTubeId' => 'dqw4w9wgxcq' ) ),
				self::element(
					'eeeeee',
					'code',
					array(
						'code' => '<?php echo "Hello World"; ?>',
						'css'  => '.Hero { content: "Big Title" }',
					)
				),
				self::element( 'ffffff', 'countdown', array( 'fields' => array( array( 'format' => '%D days' ) ) ) ),
				self::element( 'gggggg', 'heading', array( 'text' => '{post_title}' ) ),
				self::element( 'hhhhhh', 'text-basic', array( 'text' => ' by {author_name}' ) ),
			)
		);
		$marked = $this->marked( $id );
		$base   = 'meta/_bricks_page_content_2/';

		$this->assertSame( ' for you', $marked[ $base . '#bbbbbb/settings/suffix' ] ?? null );
		$this->assertSame( 'pasta', $marked[ $base . '#bbbbbb/settings/strings/0/text' ] ?? null, 'A script types these words, Bricks declares them as text' );
		$this->assertSame( ' minutes', $marked[ $base . '#cccccc/settings/suffix' ] ?? null );
		$this->assertSame( '%D days', $marked[ $base . '#ffffff/settings/fields/0/format' ] ?? null );
		$this->assertSame( 'Fresh bread', $marked[ $base . '#aaaaaa/settings/text' ] ?? null );
		$this->assertSame( ' by {author_name}', $marked[ $base . '#hhhhhh/settings/text' ] ?? null );
		$this->assertArrayNotHasKey( $base . '#gggggg/settings/text', $marked, 'Only dynamic data: nothing to translate here' );

		foreach ( array( '#aaaaaa/settings/_cssClasses', '#aaaaaa/settings/_cssId', '#aaaaaa/settings/_typography/font-family', '#aaaaaa/settings/_attributes/#at1/value', '#dddddd/settings/youTubeId', '#eeeeee/settings/code', '#eeeeee/settings/css', '#aaaaaa/settings/tag' ) as $setting ) {
			$this->assertArrayNotHasKey( $base . $setting, $marked, $setting . ' is no text' );
		}
	}

	/**
	 * Texts of a component are found where Bricks keeps them: the defaults in the option of all
	 * components, and what a page sets for its instance in the page.
	 */
	public function test_component_texts() {
		update_option(
			'bricks_components',
			array(
				array(
					'id'         => 'cmp001',
					'elements'   => array(
						array(
							'id'       => 'cmp001',
							'name'     => 'block',
							'settings' => array(),
						),
						self::element( 'cmph01', 'heading', array( 'text' => 'card title' ) ),
					),
					'properties' => array(
						array(
							'id'          => 'ptitle',
							'label'       => 'Title',
							'type'        => 'text',
							'default'     => 'card title',
							'connections' => array( 'cmph01' => array( 'text' ) ),
						),
					),
				),
			)
		);
		$id = $this->built(
			array(
				array(
					'id'         => 'inst01',
					'name'       => 'block',
					'cid'        => 'cmp001',
					'settings'   => array(),
					'properties' => array( 'ptitle' => 'fresh bread' ),
				),
			)
		);

		$this->assertTrue( $this->bricks->fieldHoldsText( 'options', 'option/bricks_components/#cmp001/elements/#cmph01/settings/text' ) );
		$this->assertTrue( $this->bricks->fieldHoldsText( 'options', 'option/bricks_components/#cmp001/properties/#ptitle/default' ) );
		$this->assertTrue( $this->bricks->fieldHoldsText( 'post:' . $id, 'meta/_bricks_page_content_2/#inst01/properties/ptitle' ) );
		$this->assertNull( $this->bricks->fieldHoldsText( 'options', 'option/bricks_components/#cmp001/properties/#ptitle/label' ), 'The builder shows the label of a property, no page does' );
		$this->assertNull( $this->bricks->fieldHoldsText( 'options', 'option/other/#cmp001/elements/#cmph01/settings/text' ) );
	}

	/**
	 * Bricks reads its components when the theme loads, before a page check counts the texts of
	 * options, and keeps them: their texts were never found. Once the page renders they are read
	 * again, so the check marks them and a translated page shows them translated.
	 */
	public function test_components_are_read_when_the_page_renders() {
		$components = array(
			array(
				'id'       => 'cmp001',
				'elements' => array( self::element( 'cmph01', 'heading', array( 'text' => 'Card title' ) ) ),
			),
		);
		update_option( 'bricks_components', $components );
		\Bricks\Database::$global_data['components'] = $components;

		$this->reset_state();
		swiftling_start( 'probe' );
		$this->bricks->start( 'probe', true );
		$this->assertSame( 'Card title', \Bricks\Database::$global_data['components'][0]['elements'][0]['settings']['text'], 'As the theme read it' );
		$this->assertNotFalse( has_action( 'template_redirect', array( $this->bricks, 'readComponents' ) ) );
		$GLOBALS['wp_actions']['template_redirect'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As if WordPress had reached the page; tests restore hooks and actions.
		$this->bricks->readComponents();
		$this->assertTrue( swiftling_has_marker( \Bricks\Database::$global_data['components'][0]['elements'][0]['settings']['text'] ), 'Read again, marked' );

		unset( $GLOBALS['wp_actions']['template_redirect'] );
		$this->reset_state();
		\Bricks\Database::$global_data = array();
	}

	/**
	 * A popup Bricks loads only when it opens is printed with the page while a page check runs, so
	 * the check finds its texts. Visitors still get it loaded later.
	 */
	public function test_page_check_prints_popups_loaded_later() {
		$popup = self::factory()->post->create( array( 'post_type' => 'bricks_template' ) );
		update_post_meta(
			$popup,
			'_bricks_template_settings',
			array(
				'popupAjax'          => true,
				'templateConditions' => array( array( 'main' => 'any' ) ),
			)
		);

		$this->assertTrue( get_post_meta( $popup, '_bricks_template_settings', true )['popupAjax'], 'For visitors' );
		$this->reset_state();
		swiftling_start( 'probe' );
		$this->bricks->start( 'probe', true );
		$settings = get_post_meta( $popup, '_bricks_template_settings', true );
		$this->assertArrayNotHasKey( 'popupAjax', $settings );
		$this->assertSame( array( array( 'main' => 'any' ) ), $settings['templateConditions'] );
		$this->reset_state();
	}

	/**
	 * A form's messages and emails show only after a visitor sends it, so no page check sees them.
	 * The editor of the page lists them, with the names Bricks gives the fields; texts of actions
	 * the form does not use, settings and texts the page shows are left out.
	 */
	public function test_texts_a_page_shows_later() {
		$id = $this->built(
			array(
				self::element( 'aaaaaa', 'heading', array( 'text' => 'Contact us' ) ),
				self::element(
					'bbbbbb',
					'form',
					array(
						'fields'                  => array(
							array(
								'id'          => 'f00001',
								'label'       => 'Your email',
								'placeholder' => 'name@example.com',
								'name'        => 'email',
							),
						),
						'actions'                 => array( 'email' ),
						'successMessage'          => 'thanks, we got it',
						'emailSubject'            => 'New message from the site',
						'emailContent'            => 'Someone wrote: {{all_fields}}',
						'fromEmail'               => 'shop@example.com',
						'mailchimpPendingMessage' => 'Check your inbox.',
					)
				),
			)
		);

		$texts = array();
		foreach ( $this->bricks->listTexts( swiftling_subject( 'post:' . $id ) ) as $text ) {
			$texts[ $text['v'] ] = $text;
		}

		$this->assertArrayHasKey( 'thanks, we got it', $texts );
		$this->assertSame( 'post:' . $id, $texts['thanks, we got it']['o'] );
		$this->assertSame( 'meta/_bricks_page_content_2/#bbbbbb/settings/successMessage', $texts['thanks, we got it']['p'] );
		$this->assertSame( 'meta:_bricks_page_content_2|form|settings/successMessage', $texts['thanks, we got it']['r'], 'The rule key the page check gives it' );
		$this->assertSame( 'Form › Success message', $texts['thanks, we got it']['place'] );
		$this->assertArrayHasKey( 'New message from the site', $texts );
		$this->assertArrayHasKey( 'Someone wrote: {{all_fields}}', $texts );
		$this->assertArrayNotHasKey( 'Check your inbox.', $texts, 'The form does not subscribe to Mailchimp' );
		$this->assertArrayNotHasKey( 'shop@example.com', $texts );
		$this->assertSame( 'Form › Fields 1 › Label', $texts['Your email']['place'] ?? '', 'Texts the page shows too are listed; the editor shows each once' );

		// The editor shows what the page check found once, and lists the rest under the page's fields.
		$result = $this->check(
			'post:' . $id,
			static function () use ( $id ) {
				get_post_meta( $id, '_bricks_page_content_2', true );
			},
			array(
				'meta/_bricks_page_content_2/#bbbbbb/settings/successMessage' => null,
				'meta/_bricks_page_content_2/#bbbbbb/settings/emailSubject'   => null,
				'meta/_bricks_page_content_2/#bbbbbb/settings/emailContent'   => null,
				'meta/_bricks_page_content_2/#bbbbbb/settings/mailchimpPendingMessage' => null,
			)
		);
		$fields = wp_list_pluck( $result['sections']['fields'] ?? array(), 'v' );
		sort( $fields );
		$this->assertSame( array( 'New message from the site', 'Someone wrote: {{all_fields}}', 'thanks, we got it' ), $fields );
	}

	/**
	 * Sending a form, and what a page loads later from Bricks' REST API, a popup and the next page of
	 * a query loop, answer in the language of the page they come from. The builder's own route stays
	 * in the original.
	 */
	public function test_requests_in_the_language_of_the_page() {
		$this->assertContains( 'bricks_form_submit', $this->bricks->sendingActions() );
		$routes  = $this->bricks->sendingRoutes();
		$matches = static function ( $route ) use ( $routes ) {
			foreach ( $routes as $pattern ) {
				if ( preg_match( $pattern, $route ) ) {
					return true;
				}
			}
			return false;
		};
		$this->assertTrue( $matches( '/bricks/v1/load_popup_content' ) );
		$this->assertTrue( $matches( '/bricks/v1/load_query_page' ) );
		$this->assertTrue( $matches( '/bricks/v1/query_result' ) );
		$this->assertFalse( $matches( '/bricks/v1/render_element' ), 'The builder renders an element in the original' );
		$this->assertFalse( $matches( '/bricks/v1/get-templates-data' ) );
	}

	/**
	 * Bricks' breadcrumbs print the title of the page and of its parents as stored, past the_title
	 * (Bricks 2.4.4, includes/elements/breadcrumbs.php); on a translated page they show translated.
	 */
	public function test_breadcrumbs_show_translated_titles() {
		$parent = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Usluge & cijene',
			)
		);
		$child  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Dostava',
				'post_parent' => $parent,
			)
		);
		foreach ( array( $parent, $child ) as $id ) {
			$this->check(
				'post:' . $id,
				static function () use ( $id ) {
					get_the_title( $id );
				}
			);
		}
		// WordPress stores "&" in a title as "&amp;"; Bricks prints what is stored.
		$this->translate( 'post:' . $parent, 'field/post_title', get_post_field( 'post_title', $parent, 'raw' ), 'de', 'Leistungen & Preise' );
		$this->translate( 'post:' . $child, 'field/post_title', 'Dostava', 'de', 'Lieferung' );
		$items = array(
			'<a class="item" href="/">Home</a>',
			'<a class="item" href="/usluge/">Usluge &amp; cijene</a>',
			'<span class="item" aria-current="page">Dostava</span>',
		);

		$this->go_to( get_permalink( $child ) );
		$this->show_in( 'de' );
		$this->assertSame(
			array(
				'<a class="item" href="/">Home</a>',
				'<a class="item" href="/usluge/">Leistungen &amp; Preise</a>',
				'<span class="item" aria-current="page">Lieferung</span>',
			),
			apply_filters( 'bricks/breadcrumbs/items', $items )
		);

		$this->reset_state();
		$this->assertSame( $items, apply_filters( 'bricks/breadcrumbs/items', $items ), 'The original' );
	}

	/**
	 * Machine translation leaves Bricks' dynamic data tags and the units of a countdown as they are:
	 * Bricks replaces them on the page, so a translated tag shows nothing.
	 */
	public function test_machine_translation_keeps_dynamic_data() {
		$items = apply_filters(
			'swiftling_machine_items',
			array(
				'a' => array(
					'text' => 'Written by {author_name} on {post_date:F j} in {post_terms_category:plain}',
					'html' => false,
				),
				'b' => array(
					'text' => '%D days %h hours',
					'html' => false,
				),
				'c' => array(
					'text' => 'Hello {echo:my_greeting(\'{post_title}\')} and 100% fresh',
					'html' => false,
					'keep' => array( 'Lavanda' ),
				),
				'd' => array(
					'text' => 'No tags here',
					'html' => false,
				),
			),
			null,
			array()
		);

		$this->assertEqualsCanonicalizing( array( '{author_name}', '{post_date:F j}', '{post_terms_category:plain}' ), $items['a']['keep'] );
		$this->assertEqualsCanonicalizing( array( '%D', '%h' ), $items['b']['keep'] );
		$this->assertEqualsCanonicalizing( array( 'Lavanda', '{echo:my_greeting(\'{post_title}\')}' ), $items['c']['keep'], 'What another plugin keeps stays, and "100% fresh" is no unit' );
		$this->assertArrayNotHasKey( 'keep', $items['d'] );
	}

	/**
	 * The page check marks where a page built with Bricks has its content: Bricks prints it without
	 * the_content, so texts placed in it, such as a section template or a component, are listed in
	 * the page's editor. A single post's template is printed the same way, but it is no content of
	 * the post: its texts stay the template's.
	 */
	public function test_page_check_knows_the_content() {
		$own      = array( self::element( 'aaaaaa', 'heading', array( 'text' => 'Ours' ) ) );
		$id       = $this->built( $own );
		$template = $this->built( array( self::element( 'tttttt', 'heading', array( 'text' => 'Template' ) ) ), array( 'post_type' => 'bricks_template' ) );

		$state          = &swiftling_state();
		$state['probe'] = $id;
		$this->bricks->start( 'probe', true );
		$this->assertSame( '<!--swiftling:content-->', apply_filters( 'bricks/content/html_after_begin', '', $own, array(), 'main' ) );
		$this->assertSame( '<!--/swiftling:content-->', apply_filters( 'bricks/content/html_before_end', '', $own, array(), 'main' ) );
		$this->assertSame( '', apply_filters( 'bricks/content/html_after_begin', '', get_post_meta( $template, '_bricks_page_content_2', true ), array(), 'main' ), 'A template of single posts' );
		$this->assertSame( '', apply_filters( 'bricks/content/html_before_end', '', get_post_meta( $template, '_bricks_page_content_2', true ), array(), 'main' ) );
	}
}
