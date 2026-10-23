<?php
/**
 * Tests of checking a page on the server.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\PageCheck\Reader;

/**
 * The server checks a page as the editor's browser does: it asks the site for both pages of the
 * check with a signed token, reads them and stores what it found. A token is for one subject and
 * variant, for a few minutes; a user who may not translate the subject gets no check.
 */
class ServerCheckTest extends TestCase {

	/**
	 * Pages the site answers the server's requests with: variant => HTML, and the addresses asked.
	 *
	 * @var array
	 */
	private $pages = array();

	/**
	 * Addresses the server asked for.
	 *
	 * @var string[]
	 */
	private $asked = array();

	/**
	 * The status the site answers with.
	 *
	 * @var int
	 */
	private $status = 200;

	/**
	 * The site answers the server's requests from $this->pages.
	 */
	public function set_up() {
		parent::set_up();
		require_once SWIFTLING_DIR . 'includes/probe-results.php';
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->asked[] = $url;
				wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$variant = $query['swiftling_probe'] ?? '';
				return array(
					'headers'  => array(),
					'body'     => $this->pages[ $variant ] ?? '',
					'response' => array(
						'code'    => isset( $this->pages[ $variant ] ) ? $this->status : 500,
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
	 * A token is good for its subject, variant and user, until it runs out.
	 */
	public function test_token() {
		$token = swiftling_check_token( 'post:7', 'marked', 3 );
		$this->assertSame( 3, swiftling_verify_check_token( $token, 'post:7', 'marked' ) );
		$this->assertNull( swiftling_verify_check_token( $token, 'post:7', 'control' ), 'Another variant' );
		$this->assertNull( swiftling_verify_check_token( $token, 'post:8', 'marked' ), 'Another subject' );
		$this->assertNull( swiftling_verify_check_token( '9.' . ( time() + 60 ) . '.' . explode( '.', $token )[2], 'post:7', 'marked' ), 'Another user' );

		$past = time() - 1;
		$this->assertNull( swiftling_verify_check_token( '3.' . $past . '.' . swiftling_check_token_signature( 'post:7', 'marked', 3, $past ), 'post:7', 'marked' ), 'Run out' );
		$this->assertNull( swiftling_verify_check_token( 'nonsense', 'post:7', 'marked' ) );
	}

	/**
	 * A page check with a token that is not good is refused, as one with a bad nonce.
	 */
	public function test_probe_refuses_a_bad_token() {
		$post = self::factory()->post->create();
		$_GET = array(
			'swiftling_probe'   => 'marked',
			'swiftling_subject' => 'post:' . $post,
			'swiftling_token'   => swiftling_check_token( 'post:' . $post, 'control', 1 ),
		);
		$this->expectException( \WPDieException::class );
		swiftling_maybe_start_probe();
	}

	/**
	 * The server asks for both pages with a token, never a nonce, reads them and stores the post's
	 * texts as the editor's check does; a user who may not translate the post gets no check; a page
	 * the site does not answer is logged.
	 */
	public function test_server_check_stores_the_texts() {
		$post   = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Kontakt',
				'post_content' => '',
			)
		);
		$leaves = $this->probe(
			static function () use ( $post ) {
				get_the_title( $post );
			}
		);
		$this->assertNotEmpty( $leaves );
		$this->pages = $this->check_pages( 'post:' . $post->ID, $leaves );

		$admin  = $this->become( 'administrator' );
		$stored = swiftling_server_check( 'post:' . $post->ID );
		$this->assertIsArray( $stored, is_wp_error( $stored ) ? $stored->get_error_message() : '' );
		$this->assertCount( 2, $this->asked );
		foreach ( $this->asked as $url ) {
			$this->assertStringContainsString( 'swiftling_token=' . $admin . '.', $url );
			$this->assertStringNotContainsString( 'swiftling_nonce', $url );
		}
		$this->assertArrayHasKey( swiftling_path_key( 'field/post_title' ), swiftling_get_maps( array( 'post:' . $post->ID ) )[ 'post:' . $post->ID ]['units'] );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$refused    = swiftling_server_check( 'post:' . $post->ID, $subscriber );
		$this->assertWPError( $refused );
		$this->assertSame( 'swiftling_check_rights', $refused->get_error_code() );

		$this->pages = array();
		$failed      = swiftling_server_check( 'post:' . $post->ID, $admin );
		$this->assertWPError( $failed );
		$this->assertStringContainsString( 'The server could not check a page', wp_json_encode( swiftling_log_entries() ) );
	}

	/**
	 * The page of the "Page not found" kind of page is not found: its check takes the 404 it
	 * answers with. Any other page that answers 404 is no check.
	 */
	public function test_not_found_page() {
		$this->become( 'administrator' );
		$this->assertSame( home_url( '/swiftling-page-not-found/' ), swiftling_subject( 'view:404' )['url'] );
		$leaves       = $this->probe(
			static function () {
				get_bloginfo( 'name' );
			}
		);
		$this->pages  = $this->check_pages( 'view:404', $leaves );
		$this->status = 404;
		$stored       = swiftling_server_check( 'view:404' );
		$this->assertIsArray( $stored, is_wp_error( $stored ) ? $stored->get_error_message() : '' );

		$post        = self::factory()->post->create( array( 'post_title' => 'Kontakt' ) );
		$this->pages = $this->check_pages( 'post:' . $post, $leaves );
		$this->assertSame( 'swiftling_check_status', swiftling_server_check( 'post:' . $post )->get_error_code() );
	}

	/**
	 * The reader tells where each marker landed as the browser does: the title and description of
	 * the page, a link in the navigation, a button, an image's text, the post's content; and the
	 * texts a visitor sees, but those meant to stay as they are.
	 */
	public function test_reader() {
		$html   = '<!doctype html><html><head><title>Naslov zqk1kqz</title><meta name="description" content="Opis zqk2kqz"></head><body>'
			. '<nav><ul><li><a href="/kontakt/">Kontakt zqk3kqz</a></li></ul></nav>'
			. '<!--swiftling:content--><p>Tekst <a href="/x/">poveznica</a> zqk4kqz</p><a class="wp-block-button__link">Kupi zqk5kqz</a><img src="a.jpg" alt="Slika zqk6kqz"><!--/swiftling:content-->'
			. '<footer><p translate="no">Hrvatski</p><p>Podnožje</p></footer>'
			. '<script type="application/json" id="swiftling-probe" data-signature="sig">{"subject":"post:1"}</script></body></html>';
		$reader = new Reader();
		$marked = $reader->marked( $html );
		$this->assertSame( '{"subject":"post:1"}', $marked['registry'] );
		$this->assertSame( 'sig', $marked['signature'] );
		$this->assertTrue( $marked['scoped'] );
		$this->assertSame( array( 'head-title', 'seo_title', 'head' ), array( $marked['found']['1'][0]['where'], $marked['found']['1'][0]['kind'], $marked['found']['1'][0]['region'] ) );
		$this->assertSame( array( 'meta', 'meta_description' ), array( $marked['found']['2'][0]['where'], $marked['found']['2'][0]['kind'] ) );
		$this->assertSame( array( 'text', 'link', 'navigation', 0 ), array( $marked['found']['3'][0]['where'], $marked['found']['3'][0]['kind'], $marked['found']['3'][0]['region'], $marked['found']['3'][0]['content'] ) );
		$this->assertSame( array( 'paragraph', 1 ), array( $marked['found']['4'][0]['kind'], $marked['found']['4'][0]['content'] ) );
		$this->assertSame( 'button', $marked['found']['5'][0]['kind'] );
		$this->assertSame( array( 'alt', 'alt' ), array( $marked['found']['6'][0]['where'], $marked['found']['6'][0]['kind'] ) );
		$this->assertNotSame( -1, $marked['found']['3'][0]['link'], 'The link around it' );

		$control = $reader->control( str_replace( array( ' zqk1kqz', ' zqk2kqz', ' zqk3kqz', ' zqk4kqz', ' zqk5kqz', ' zqk6kqz' ), '', $html ) );
		$texts   = wp_list_pluck( $control['texts'], 't' );
		$this->assertContains( 'Kontakt', $texts );
		$this->assertContains( 'Slika', $texts );
		$this->assertContains( 'Podnožje', $texts );
		$this->assertNotContains( 'Hrvatski', $texts, 'Meant to stay as it is' );
		$this->assertSame( 'Naslov', $control['title'] );
		$this->assertNull( $reader->marked( '<html><body><p>No list</p></body></html>' ) );
	}
}
