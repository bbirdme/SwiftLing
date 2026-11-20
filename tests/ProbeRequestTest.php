<?php
/**
 * Tests of the page check request.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WPDieException;

/**
 * Who may start a page check, and what it prints.
 *
 * A started page check opens a database transaction for the rest of the request, which would end
 * the transaction each test runs in; these tests stop before that or call its parts one by one.
 */
class ProbeRequestTest extends TestCase {

	/**
	 * The address of a page check names the variant and the subject, with a nonce for both.
	 */
	public function test_probe_url() {
		$this->become( 'administrator' );
		$url = swiftling_probe_url(
			array(
				'key' => 'post:5',
				'url' => 'http://example.org/?p=5',
			),
			'marked'
		);
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'marked', $query['swiftling_probe'] );
		$this->assertSame( 'post:5', $query['swiftling_subject'] );
		$this->assertSame( 1, wp_verify_nonce( $query['swiftling_nonce'], 'swiftling_probe_post:5' ) );
		$this->assertFalse( wp_verify_nonce( $query['swiftling_nonce'], 'swiftling_probe_post:6' ) );
	}

	/**
	 * A request that asks for no page check, or for a variant there is not, stays an ordinary request.
	 */
	public function test_ordinary_requests() {
		swiftling_maybe_start_probe();
		$_GET['swiftling_probe'] = 'everything';
		swiftling_maybe_start_probe();
		$this->assertSame( '', swiftling_mode() );
		$this->assertFalse( swiftling_is_probe() );
	}

	/**
	 * A page check with a nonce of another user or subject is refused.
	 */
	public function test_nonce_is_required() {
		$post = self::factory()->post->create();
		$this->become( 'administrator' );
		$_GET = array(
			'swiftling_probe'   => 'marked',
			'swiftling_subject' => 'post:' . $post,
			'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_post:1' ),
		);
		$this->expectException( WPDieException::class );
		swiftling_maybe_start_probe();
	}

	/**
	 * A page check is refused to someone who may not translate the page, even with a valid nonce.
	 */
	public function test_rights_are_required() {
		$post = self::factory()->post->create();
		$this->become( 'editor' );
		$_GET = array(
			'swiftling_probe'   => 'control',
			'swiftling_subject' => 'post:' . $post,
			'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_post:' . $post ),
		);
		try {
			swiftling_maybe_start_probe();
			$this->fail( 'An editor started a page check.' );
		} catch ( WPDieException $refused ) {
			$this->assertFalse( swiftling_is_probe() );
		}
	}

	/**
	 * The signature depends on the list and on the site's secret.
	 */
	public function test_signature() {
		$this->assertSame( swiftling_probe_signature( '{"a":1}' ), swiftling_probe_signature( '{"a":1}' ) );
		$this->assertNotSame( swiftling_probe_signature( '{"a":1}' ), swiftling_probe_signature( '{"a":2}' ) );
		$this->assertSame( hash_hmac( 'sha256', 'x', wp_salt( 'auth' ) . 'swiftling-probe' ), swiftling_probe_signature( 'x' ) );
	}

	/**
	 * The printed list cannot end its script element, whatever the page's texts say, and is signed.
	 */
	public function test_printed_list_is_safe_and_signed() {
		$state             = &swiftling_state();
		$state['subject']  = 'post:1';
		$state['registry'] = array(
			1 => array(
				'o' => 'post:1',
				'p' => 'field/post_title',
				'v' => '</script><script>alert(1)</script> & more',
				'r' => 'field:post_title||',
			),
		);
		ob_start();
		swiftling_print_probe_registry();
		$html = ob_get_clean();

		$this->assertSame( 1, substr_count( $html, '</script>' ), 'Only the element\'s own end' );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertMatchesRegularExpression( '~^<script type="application/json" id="swiftling-probe" data-signature="([0-9a-f]{64})">(.*)</script>$~s', $html );
		preg_match( '~data-signature="([0-9a-f]{64})">(.*)</script>$~s', $html, $parts );
		$this->assertSame( swiftling_probe_signature( $parts[2] ), $parts[1] );
		$this->assertSame( '</script><script>alert(1)</script> & more', json_decode( $parts[2], true )['leaves'][1]['v'] );
	}

	/**
	 * While a page checks, meta and option writes change nothing.
	 */
	public function test_writes_are_refused() {
		$this->assertFalse( swiftling_refuse_write() );
		$this->assertSame( 'old', swiftling_keep_option( 'new', 'blogname', 'old' ) );
	}

	/**
	 * The content of the checked post is marked once, around the outer run only.
	 */
	public function test_content_area() {
		$post            = self::factory()->post->create_and_get();
		$state           = &swiftling_state();
		$state['probe']  = $post->ID;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop sets it.
		setup_postdata( $post );
		$this->assertTrue( swiftling_is_probe() );
		$this->assertSame( '<!--swiftling:content-->x<!--/swiftling:content-->', swiftling_mark_content_area( 'x' ) );

		$state['probe'] = $post->ID + 1;
		$this->assertSame( 'x', swiftling_mark_content_area( 'x' ), 'Another post listed on the page' );
	}

	/**
	 * Starts a page check as the request does, without the transaction a started check opens,
	 * which would end the transaction the test runs in.
	 */
	private function start_without_transaction() {
		$keep = static function ( $query ) {
			return in_array( $query, array( 'START TRANSACTION', 'ROLLBACK' ), true ) ? 'SELECT 1' : $query;
		};
		add_filter( 'query', $keep );
		try {
			swiftling_maybe_start_probe();
		} finally {
			remove_filter( 'query', $keep );
			remove_action( 'shutdown', 'swiftling_end_probe', PHP_INT_MAX );
		}
	}

	/**
	 * A post of a type a plugin registers after the check starts, as WooCommerce registers products
	 * on init at priority 5, is checked: its subject is looked up again once every type is there.
	 */
	public function test_post_of_a_type_registered_later() {
		$this->become( 'administrator' );
		$product = self::factory()->post->create(
			array(
				'post_type'  => 'swl_product',
				'post_title' => 'Sandala',
			)
		);
		$this->assertFalse( post_type_exists( 'swl_product' ), 'Not registered yet, as at init 0' );
		$_GET = array(
			'swiftling_probe'   => 'marked',
			'swiftling_subject' => 'post:' . $product,
			'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_post:' . $product ),
		);
		$this->start_without_transaction();
		$this->assertSame( 'probe', swiftling_mode() );
		$this->assertTrue( swiftling_is_probe() );

		register_post_type( 'swl_product', array( 'public' => true ) );
		swiftling_check_late_probe();
		$state = &swiftling_state();
		$this->assertSame( $product, $state['probe'] );
		$this->assertSame( 'post:' . $product, $state['subject'] );
		unregister_post_type( 'swl_product' );
	}

	/**
	 * A kind of page of a type a plugin registers after the check starts, as WooCommerce's single
	 * product, is checked too: the kinds of pages are listed again once every type is there.
	 */
	public function test_kind_of_page_of_a_type_registered_later() {
		$this->become( 'administrator' );
		self::factory()->post->create(
			array(
				'post_type'  => 'swl_product',
				'post_title' => 'Sandala',
			)
		);
		$_GET = array(
			'swiftling_probe'   => 'control',
			'swiftling_subject' => 'view:single-swl_product',
			'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_view:single-swl_product' ),
		);
		$this->start_without_transaction();
		$this->assertNull( swiftling_subject( 'view:single-swl_product' ), 'Not a kind of page yet, as at init 0' );

		register_post_type( 'swl_product', array( 'public' => true ) );
		try {
			swiftling_check_late_probe();
			$state = &swiftling_state();
			$this->assertSame( array( -1, 'view:single-swl_product' ), array( $state['probe'], $state['subject'] ) );
		} finally {
			unregister_post_type( 'swl_product' );
		}
	}

	/**
	 * Runs a test with a block theme active and a template of a type a plugin registers later, as
	 * "Single Product" is for a plugin's products, and puts the theme back.
	 *
	 * @param callable $test Gets the key of the template.
	 */
	private function with_template_of_a_later_type( $test ) {
		$theme = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		swiftling_forget_memo();
		$name = 'swiftling-tests//single-swl_product';
		register_block_template(
			$name,
			array(
				'title'   => 'Single Product',
				'content' => '<!-- wp:post-title /-->',
			)
		);
		try {
			// Listed under the theme's name, as a template of the theme itself.
			$test( 'template:twentytwentyfive//single-swl_product' );
		} finally {
			unregister_post_type( 'swl_product' );
			unregister_block_template( $name );
			switch_theme( $theme );
			swiftling_forget_memo();
		}
	}

	/**
	 * A template of a block theme for a type a plugin registers after the check starts, as a plugin's
	 * single page of its own posts, is checked: the template is known at once, but without an example
	 * page, which exists only once the type does, so it is looked up again once every type is there
	 * (issue #63). It was refused at once, with the 403 of a page check that has expired.
	 */
	public function test_template_of_a_type_registered_later() {
		$this->become( 'administrator' );
		$this->with_template_of_a_later_type(
			function ( $key ) {
				self::factory()->post->create( array( 'post_type' => 'swl_product' ) );
				$this->assertFalse( post_type_exists( 'swl_product' ), 'Not registered yet, as at init 0' );
				$this->assertFalse( swiftling_subject( $key )['probe'], 'Known, but without an example page yet' );
				$_GET = array(
					'swiftling_probe'   => 'marked',
					'swiftling_subject' => $key,
					'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_' . $key ),
				);
				$this->start_without_transaction();
				$this->assertSame( 'probe', swiftling_mode() );
				$this->assertNotFalse( has_action( 'wp_loaded', 'swiftling_check_late_probe' ), 'Looked up again later' );

				register_post_type( 'swl_product', array( 'public' => true ) );
				swiftling_check_late_probe();
				$state = &swiftling_state();
				$this->assertSame( array( -1, $key ), array( $state['probe'], $state['subject'] ) );
			}
		);
	}

	/**
	 * A template that still has no example page once every type is registered is refused then, before
	 * the page renders.
	 */
	public function test_template_without_an_example_refused_later() {
		$this->become( 'administrator' );
		$this->with_template_of_a_later_type(
			function ( $key ) {
				// No post of the type, so no page shows the template, also once the type exists.
				$_GET = array(
					'swiftling_probe'   => 'control',
					'swiftling_subject' => $key,
					'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_' . $key ),
				);
				$this->start_without_transaction();
				$this->assertNotFalse( has_action( 'wp_loaded', 'swiftling_check_late_probe' ) );
				register_post_type( 'swl_product', array( 'public' => true ) );
				try {
					swiftling_check_late_probe();
					$this->fail( 'A template no page shows was checked.' );
				} catch ( WPDieException $refused ) {
					$this->assertStringContainsString( 'expired', $refused->getMessage() );
				}
			}
		);
	}

	/**
	 * A template with an example page is checked at once, as before: nothing is looked up again.
	 */
	public function test_template_with_an_example_is_checked_at_once() {
		$this->become( 'administrator' );
		$this->with_template_of_a_later_type(
			function () {
				$post = self::factory()->post->create();
				$key  = 'template:twentytwentyfive//single';
				$this->assertTrue( swiftling_subject( $key )['probe'] );
				$_GET = array(
					'swiftling_probe'   => 'marked',
					'swiftling_subject' => $key,
					'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_' . $key ),
				);
				$this->start_without_transaction();
				$state = &swiftling_state();
				$this->assertSame( array( -1, $key ), array( $state['probe'], $state['subject'] ) );
				$this->assertFalse( has_action( 'wp_loaded', 'swiftling_check_late_probe' ) );
				$this->assertSame( get_permalink( $post ), swiftling_subject( $key )['url'] );
			}
		);
	}

	/**
	 * Only a template can get its example page later. A list of terms with no page to check, as of a
	 * taxonomy without terms, is refused at once, as before.
	 */
	public function test_subject_without_a_page_refused_at_once() {
		$this->become( 'administrator' );
		$this->assertFalse( swiftling_subject( 'terms:post_tag' )['probe'], 'No tag, so no page of one' );
		$_GET = array(
			'swiftling_probe'   => 'control',
			'swiftling_subject' => 'terms:post_tag',
			'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_terms:post_tag' ),
		);
		$this->expectException( WPDieException::class );
		$this->start_without_transaction();
	}

	/**
	 * A subject that is still unknown once every type is registered is refused then, before the
	 * page renders.
	 */
	public function test_unknown_subject_refused_later() {
		$this->become( 'administrator' );
		$_GET = array(
			'swiftling_probe'   => 'control',
			'swiftling_subject' => 'post:999999',
			'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_post:999999' ),
		);
		$this->start_without_transaction();
		$this->expectException( WPDieException::class );
		swiftling_check_late_probe();
	}

	/**
	 * Once every type is registered, the rights are checked as for any page check.
	 */
	public function test_rights_checked_later() {
		$this->become( 'administrator' );
		$product = self::factory()->post->create( array( 'post_type' => 'swl_product' ) );
		$_GET    = array(
			'swiftling_probe'   => 'marked',
			'swiftling_subject' => 'post:' . $product,
			'swiftling_nonce'   => wp_create_nonce( 'swiftling_probe_post:' . $product ),
		);
		$this->start_without_transaction();
		register_post_type( 'swl_product', array( 'public' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			swiftling_check_late_probe();
			$this->fail( 'An editor\'s page check went on.' );
		} catch ( WPDieException $refused ) {
			$this->assertStringContainsString( 'expired', $refused->getMessage() );
		} finally {
			unregister_post_type( 'swl_product' );
		}
	}

	/**
	 * A page check forgets that the editor in the admin opened it, as no visitor comes from there; a
	 * page of the site the browser names stays.
	 */
	public function test_check_forgets_an_admin_referer() {
		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=swiftling-content&swiftling_subject=post:5' );
		swiftling_forget_admin_referer();
		$this->assertArrayNotHasKey( 'HTTP_REFERER', $_SERVER );

		$_SERVER['HTTP_REFERER'] = home_url( '/imenik/' );
		swiftling_forget_admin_referer();
		$this->assertSame( home_url( '/imenik/' ), isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '' );
		unset( $_SERVER['HTTP_REFERER'] );
	}
}
