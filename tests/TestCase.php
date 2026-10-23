<?php
/**
 * What every SwiftLing test shares.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use ReflectionProperty;
use RuntimeException;
use WP_Error;
use WP_UnitTestCase;

/**
 * Base class of SwiftLing's tests: a site in Croatian translated into German and French, no
 * connection to the internet, and helpers that do what the translation editor and a page check do.
 *
 * A page check normally runs in the editor's browser, which reports where each marker ended up on
 * the page. check() does the same without a browser: it reads the values in probe mode, as the page
 * would while it renders, and reports every marker as visible text, unless a test says otherwise.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Languages WordPress.org would offer, as the Languages screen lists them.
	 */
	const AVAILABLE = array(
		'hr'    => array( 'Hrvatski', 'Croatian', 'hr' ),
		'de_DE' => array( 'Deutsch', 'German', 'de' ),
		'fr_FR' => array( 'Français', 'French', 'fr' ),
		'it_IT' => array( 'Italiano', 'Italian', 'it' ),
		'es_ES' => array( 'Español', 'Spanish (Spain)', 'es' ),
		'es_MX' => array( 'Español de México', 'Spanish (Mexico)', 'es' ),
		'en_GB' => array( 'English (UK)', 'English (UK)', 'en' ),
		'pt_PT' => array( 'Português', 'Portuguese (Portugal)', 'pt' ),
	);

	/**
	 * The request's server variables before the test.
	 *
	 * @var array
	 */
	private $server = array();

	/**
	 * Sets up a site in Croatian, translated into German and French in the parameter format.
	 */
	public function set_up() {
		parent::set_up();
		$this->server = $_SERVER;

		// The rollback after each test puts the options back without saving them, so nothing tells
		// SwiftLing to build its languages and settings again.
		swiftling_forget_memo();
		$this->reset_state();
		swiftling_copy_map( true );
		$kept    = &swiftling_kept_folder();
		$kept    = '';
		$host    = &swiftling_kept_host();
		$host    = '';
		$link    = &swiftling_link_locale_override();
		$link    = null;
		$sitemap = &swiftling_sitemap_language_override();
		$sitemap = null;
		$purge   = &swiftling_purge_queue();
		$purge   = array(
			'all'   => false,
			'urls'  => array(),
			'posts' => array(),
		);
		$request = &swiftling_slug_request();
		$request = array(
			'asked' => '',
			'read'  => '',
		);
		swiftling_forget_slugs();
		$decided = &swiftling_decided_language();
		$decided = null;
		$paused  = &swiftling_paused_translations();
		$paused  = array();
		\SwiftLing\Switcher\Settings::forget();
		\SwiftLing\Packages\Settings::forget();
		\SwiftLing\Packages\Packages::forget();
		self::reset_static( \SwiftLing\Translation\Services::class, 'services' );

		// Tests never reach the internet; a test that needs an answer gives one at a lower priority.
		add_filter( 'pre_http_request', array( $this, 'refuse_http' ), 99, 3 );

		$translations = array();
		foreach ( self::AVAILABLE as $locale => $names ) {
			$translations[ $locale ] = array(
				'language'     => $locale,
				'native_name'  => $names[0],
				'english_name' => $names[1],
				'iso'          => array( 1 => $names[2] ),
				'version'      => '7.1',
				'updated'      => '2026-10-01 00:00:00',
				'package'      => 'https://downloads.wordpress.org/translation/core/7.1/' . $locale . '.zip',
			);
		}
		set_site_transient( 'available_translations', $translations );

		$this->set_languages();
	}

	/**
	 * Puts back what tests change outside the database.
	 */
	public function tear_down() {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_SERVER  = $this->server;
		$this->reset_state();
		swiftling_forget_memo();
		// The rollback of the database takes back a test's permalinks, but not the rewrite object
		// WordPress keeps them in: on a network the next test would build its addresses with them.
		if ( '' !== $GLOBALS['wp_rewrite']->permalink_structure ) {
			$this->set_permalink_structure( '' );
		}
		parent::tear_down();
	}

	/**
	 * Refuses a request to another site that no test answered.
	 *
	 * @param mixed  $pre Answer from an earlier filter, or false.
	 * @param array  $args Request arguments.
	 * @param string $url  Address.
	 * @return mixed
	 */
	public function refuse_http( $pre, $args, $url ) {
		return false === $pre ? new WP_Error( 'swiftling_tests_offline', 'Tests do not reach ' . $url ) : $pre;
	}

	/**
	 * Empties the state of the request, as at the start of a new one.
	 */
	protected function reset_state() {
		$state = &swiftling_state();
		$state = array(
			'mode'      => '',
			'language'  => '',
			'locale'    => '',
			'guard'     => false,
			'registry'  => array(),
			'ids'       => array(),
			'frames'    => array(),
			'roots'     => array(),
			'frame_seq' => 0,
			'content'   => array(),
			'hashes'    => array(),
			'strings'   => array(),
			'partial'   => array(),
			'values'    => array(),
			'owners'    => array(),
			'probe'     => 0,
			'subject'   => '',
			'collect'   => false,
		);
	}

	/**
	 * Sets a private static property of a class back to null, as at the start of a request.
	 *
	 * @param string $class_name Class.
	 * @param string $property   Property.
	 */
	protected static function reset_static( $class_name, $property ) {
		$reflection = new ReflectionProperty( $class_name, $property );
		// Needed before PHP 8.1, deprecated since 8.5.
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$reflection->setValue( null, null );
	}

	/**
	 * Sets a static property of a class, as a test needs it for one test.
	 *
	 * @param string $class_name Class.
	 * @param string $property   Property.
	 * @param mixed  $value      Value.
	 */
	protected static function set_static( $class_name, $property, $value ) {
		$reflection = new ReflectionProperty( $class_name, $property );
		// Needed before PHP 8.1, deprecated since 8.5.
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$reflection->setValue( null, $value );
	}

	/**
	 * Saves the languages of the site.
	 *
	 * @param array  $targets Locale => address slug, or locale => full saved entry.
	 * @param string $format  parameter or directory.
	 * @param array  $extra   More saved settings, such as source_folder or names.
	 */
	protected function set_languages( $targets = array(
		'de_DE' => 'de',
		'fr_FR' => 'fr',
	), $format = 'parameter', $extra = array() ) {
		$saved = array(
			'source'  => array(
				'locale' => 'hr',
				'name'   => 'Hrvatski',
				'slug'   => 'hr',
				'label'  => '',
			),
			'targets' => array(),
			'order'   => array( 'hr' ),
			'names'   => 'native',
			'format'  => $format,
		);
		foreach ( $targets as $locale => $target ) {
			$entry              = is_array( $target ) ? $target : array( 'slug' => $target );
			$saved['targets'][] = $entry + array(
				'locale'  => $locale,
				'name'    => isset( self::AVAILABLE[ $locale ] ) ? self::AVAILABLE[ $locale ][0] : $locale,
				'visible' => true,
				'label'   => '',
			);
			$saved['order'][]   = $locale;
		}
		update_option( 'swiftling_languages', array_merge( $saved, $extra ) );
	}

	/**
	 * Turns on pretty permalinks, which language folders need. Categories and tags get their
	 * addresses when their taxonomies are registered, so they are registered again, as WordPress's
	 * own tests do.
	 */
	protected function use_pretty_permalinks() {
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		$GLOBALS['wp_rewrite']->flush_rules();
	}

	/**
	 * Signs in a new user with a role.
	 *
	 * @param string $role Role.
	 * @return int User ID.
	 */
	protected function become( $role ) {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * HTTP status of the last redirect redirect_of() caught.
	 *
	 * @var int
	 */
	protected $redirect_status = 0;

	/**
	 * Runs code that ends with a redirect and returns where it sends the browser. The redirect is
	 * stopped before WordPress sends it, so the code's exit never runs.
	 *
	 * @param callable $code Code.
	 * @return string The address.
	 */
	protected function redirect_of( $code ) {
		$location = $this->catch_redirect( $code );
		if ( null === $location ) {
			$this->fail( 'The code did not redirect.' );
		}
		return (string) $location;
	}

	/**
	 * Runs code that must not redirect.
	 *
	 * @param callable $code Code.
	 */
	protected function assert_no_redirect( $code ) {
		$location = $this->catch_redirect( $code );
		$this->assertNull( $location, 'The code redirected to ' . $location );
	}

	/**
	 * Runs code and stops a redirect it starts.
	 *
	 * @param callable $code Code.
	 * @return string|null Where the code redirects, or null when it does not.
	 * @throws RuntimeException What the code throws, other than a redirect.
	 */
	private function catch_redirect( $code ) {
		$catch = function ( $location, $status ) {
			$this->redirect_status = (int) $status;
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Caught below, never shown.
			throw new RuntimeException( 'swiftling-redirect:' . $location );
		};
		add_filter( 'wp_redirect', $catch, 1, 2 );
		try {
			$code();
		} catch ( RuntimeException $redirect ) {
			if ( 0 === strpos( $redirect->getMessage(), 'swiftling-redirect:' ) ) {
				return substr( $redirect->getMessage(), strlen( 'swiftling-redirect:' ) );
			}
			throw $redirect;
		} finally {
			remove_filter( 'wp_redirect', $catch, 1 );
		}
		return null;
	}

	/**
	 * Renders a screen of the menu, as the admin shows it.
	 *
	 * @param string $view View, e.g. "switcher".
	 * @return string
	 */
	protected function render_screen( $view ) {
		set_current_screen( 'toplevel_page_swiftling' );
		ob_start();
		( new \SwiftLing\Admin\Menu() )->renderScreen( $view );
		$html = (string) ob_get_clean();
		set_current_screen( 'front' );
		return $html;
	}

	/**
	 * Fills $_POST and $_REQUEST as a form of an admin screen sends them, with a valid nonce.
	 *
	 * @param string $action Nonce action.
	 * @param array  $fields Fields of the form.
	 */
	protected function post_form( $action, $fields ) {
		$_POST                     = wp_slash( $fields );
		$_POST['_wpnonce']         = wp_create_nonce( $action );
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The form the test sends, nonce included.
		$_SERVER['HTTP_REFERER']   = admin_url();
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	/**
	 * Does what a page check of the translation editor does: reads the values in probe mode while
	 * the page "renders", then stores what was found.
	 *
	 * @param string   $key      Subject key, e.g. "post:12".
	 * @param callable $render   Reads the values, as the theme and builder would.
	 * @param array    $landings Path => where its marker landed (text, class, id, href, image, …), or
	 *                           null when it landed nowhere. Every other value landed in visible text,
	 *                           except texts from code: WordPress reads some only to work with them,
	 *                           such as the word list of wptexturize(), so they land nowhere unless
	 *                           the test says where.
	 * @param array    $control  Texts of the page rendered without markers.
	 * @return array What swiftling_save_probe() returns.
	 */
	protected function check( $key, $render, $landings = array(), $control = array() ) {
		$subject = swiftling_subject( $key );
		$this->assertNotNull( $subject, 'No subject ' . $key );

		$leaves = $this->probe( $render );

		$found = array();
		$order = 0;
		foreach ( $leaves as $id => $leaf ) {
			$where = array_key_exists( $leaf['p'], $landings ) ? $landings[ $leaf['p'] ] : ( 0 === strpos( $leaf['o'], 'gettext:' ) ? null : 'text' );
			if ( null === $where ) {
				continue;
			}
			$found[ $id ] = array( $this->landing( $where, ++$order ) );
		}

		return swiftling_save_probe( $subject, array( 'leaves' => $leaves ), $found, $control, true );
	}

	/**
	 * Reads values in probe mode, as a page check renders the page, and returns the marked values.
	 *
	 * @param callable $render Reads the values.
	 * @return array Leaf number => o, p, v, r.
	 */
	protected function probe( $render ) {
		$this->reset_state();
		// Options, terms and texts from code count only once the page renders.
		$GLOBALS['wp_actions']['template_redirect'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As if WordPress had reached the page; tests restore hooks and actions.
		swiftling_start( 'probe' );
		$render();
		$state  = &swiftling_state();
		$leaves = $state['registry'];
		$this->reset_state();
		unset( $GLOBALS['wp_actions']['template_redirect'] );
		return $leaves;
	}

	/**
	 * Returns both pages of a page check as the site renders them for the server, see
	 * swiftling_server_check(): each value the probe marked in a heading of the post's content, with
	 * its marker and the signed list of values, and the same headings as visitors see them. Texts
	 * from code are left out, as check() leaves them out: a page reads many it never shows, such as
	 * the rules of wptexturize().
	 *
	 * @param string $key    Subject key.
	 * @param array  $leaves Marked values, as probe() returns them.
	 * @return array{marked: string, control: string}
	 */
	protected function check_pages( $key, $leaves ) {
		$marked  = '';
		$control = '';
		foreach ( $leaves as $id => $leaf ) {
			if ( 0 === strpos( $leaf['o'], 'gettext:' ) ) {
				continue;
			}
			$marked  .= '<h1>' . $leaf['v'] . swiftling_marker( $id ) . '</h1>';
			$control .= '<h1>' . $leaf['v'] . '</h1>';
		}
		$payload = wp_json_encode(
			array(
				'subject'  => $key,
				'template' => '',
				'leaves'   => (object) $leaves,
			),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		return array(
			'marked'  => '<!doctype html><html><head><title>Check</title></head><body><main><!--swiftling:content-->' . $marked . '<!--/swiftling:content--></main><script type="application/json" id="swiftling-probe" data-signature="' . esc_attr( swiftling_probe_signature( $payload ) ) . '">' . $payload . '</script></body></html>',
			'control' => '<!doctype html><html><head><title>Check</title></head><body><main><!--swiftling:content-->' . $control . '<!--/swiftling:content--></main></body></html>',
		);
	}

	/**
	 * Answers the server's page checks of a subject from now on, see swiftling_server_check(): each
	 * check renders the values $render reads as they are at that moment, see check_pages().
	 *
	 * @param string   $key    Subject key.
	 * @param callable $render Reads the values, as the page would while it renders.
	 */
	protected function serve_page_check( $key, $render ) {
		$pages = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $key, $render, &$pages ) {
				wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				if ( ! isset( $query['swiftling_probe'], $query['swiftling_subject'] ) || rawurldecode( $query['swiftling_subject'] ) !== $key ) {
					return $pre;
				}
				// The marked page comes first; the control page is the same render without markers.
				if ( 'marked' === $query['swiftling_probe'] || ! $pages ) {
					$pages = $this->check_pages( $key, $this->probe( $render ) );
				}
				return array(
					'headers'  => array(),
					'body'     => $pages[ 'marked' === $query['swiftling_probe'] ? 'marked' : 'control' ],
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	/**
	 * Returns a landing as the browser reports it.
	 *
	 * @param string $where Where the marker landed.
	 * @param int    $order Position on the page.
	 * @param array  $more  Other fields, such as link or el.
	 * @return array
	 */
	protected function landing( $where, $order = 1, $more = array() ) {
		return array_merge(
			array(
				'where'   => $where,
				'kind'    => 'paragraph',
				'region'  => '',
				'order'   => $order,
				'el'      => -1,
				'link'    => -1,
				'content' => true,
			),
			$more
		);
	}

	/**
	 * Saves the translation of one text, as the editor saves it.
	 *
	 * @param string $owner    Object key, e.g. "post:12".
	 * @param string $path     Path of the text inside its object, e.g. "field/post_title".
	 * @param string $original The original text, as stored.
	 * @param string $slug     Address slug of the language.
	 * @param string $text     Translation.
	 * @param int    $status   SWIFTLING_MACHINE or SWIFTLING_REVIEWED.
	 */
	protected function translate( $owner, $path, $original, $slug, $text, $status = SWIFTLING_REVIEWED ) {
		$language = swiftling_target_language( $slug );
		$this->assertNotNull( $language, 'No language ' . $slug );
		$place = swiftling_place_key( $owner, swiftling_path_key( $path ), swiftling_hash( $original ) );
		swiftling_save_translations(
			$language['locale'],
			array(
				$place => array(
					'text'   => $text,
					'status' => $status,
				),
			)
		);
	}

	/**
	 * Saves the translation of an address: of a link, an image or a video.
	 *
	 * @param string $owner    Object key.
	 * @param string $path     Path of the address inside its object.
	 * @param string $original The original address.
	 * @param string $slug     Address slug of the language.
	 * @param string $address  Address in that language.
	 */
	protected function translate_address( $owner, $path, $original, $slug, $address ) {
		$language = swiftling_target_language( $slug );
		$place    = swiftling_place_key( $owner, swiftling_link_key( $path ), swiftling_hash( $original ) );
		swiftling_save_translations(
			$language['locale'],
			array(
				$place => array(
					'text'   => $address,
					'status' => SWIFTLING_REVIEWED,
				),
			)
		);
	}

	/**
	 * Returns the text a page check found whose value contains some words.
	 *
	 * @param array  $result What check() returned.
	 * @param string $needle Words in the value.
	 * @return array The text: o, p, v, r and what the editor needs.
	 */
	protected function found( $result, $needle ) {
		foreach ( $result['units'] as $unit ) {
			if ( false !== strpos( $unit['v'], $needle ) ) {
				return $unit;
			}
		}
		$this->fail( 'The page check found no text with "' . $needle . '".' );
		return array();
	}

	/**
	 * Shows the rest of the test in a language, as a request for its address would.
	 *
	 * @param string $slug Address slug of the language.
	 */
	protected function show_in( $slug ) {
		$this->reset_state();
		swiftling_start( 'translate', $slug );
	}
}
