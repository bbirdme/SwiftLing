<?php
/**
 * Tests of separate copies and duplicates.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use WP_Error;

/**
 * A page with its own copy for one language, and the three ways a language can show a post.
 */
class CopiesTest extends TestCase {

	/**
	 * The original of the tests: a checked post with a German translation of its title, a meta
	 * text and its content, in a category.
	 *
	 * @var \WP_Post
	 */
	private $original;

	/**
	 * Category of the original.
	 *
	 * @var int
	 */
	private $category;

	/**
	 * Makes the original as an administrator.
	 */
	public function set_up() {
		parent::set_up();
		$this->become( 'administrator' );
		$this->category = self::factory()->category->create( array( 'name' => 'Vijesti' ) );
		$this->original = self::factory()->post->create_and_get(
			array(
				'post_title'    => 'Kontakt',
				'post_name'     => 'kontakt',
				'post_excerpt'  => '',
				'post_content'  => "<!-- wp:paragraph -->\n<p>Pišite nam.</p>\n<!-- /wp:paragraph -->",
				'post_category' => array( $this->category ),
			)
		);
		add_post_meta( $this->original->ID, 'subtitle', 'Javite nam se' );

		$original = $this->original;
		$result   = $this->check(
			'post:' . $original->ID,
			static function () use ( $original ) {
				get_the_title( $original );
				get_post_meta( $original->ID, 'subtitle', true );
				$GLOBALS['post'] = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
				apply_filters( 'the_content', $original->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
			}
		);
		$owner    = 'post:' . $original->ID;
		$this->translate( $owner, 'field/post_title', 'Kontakt', 'de', 'Kontaktieren' );
		$this->translate( $owner, 'meta/subtitle', 'Javite nam se', 'de', 'Melden Sie sich' );
		$paragraph = $this->found( $result, 'Pišite nam.' );
		$this->translate( $owner, $paragraph['p'], $paragraph['v'], 'de', str_replace( 'Pišite nam.', 'Schreiben Sie uns.', $paragraph['v'] ) );
	}

	/**
	 * Posts and pages may have copies; the page of posts, media and copies may not.
	 */
	public function test_which_posts_may_have_copies() {
		$page  = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );
		$posts = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );
		update_option( 'page_for_posts', $posts->ID );
		$image = get_post( self::factory()->attachment->create_object( array( 'file' => 'a.jpg' ) ) );

		$this->assertTrue( swiftling_can_have_copies( $this->original ) );
		$this->assertTrue( swiftling_can_have_copies( $page ) );
		$this->assertFalse( swiftling_can_have_copies( $posts ) );
		$this->assertFalse( swiftling_can_have_copies( $image ) );
		$this->assertFalse( swiftling_can_have_copies( null ) );

		$copy = get_post( swiftling_make_copy( $this->original, 'de' ) );
		$this->assertFalse( swiftling_can_have_copies( $copy ) );
	}

	/**
	 * A copy starts as a draft with the translations made so far, linked to its original.
	 */
	public function test_make_copy() {
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		$this->assertIsInt( $copy_id );
		$copy = get_post( $copy_id );

		$this->assertSame( 'draft', $copy->post_status );
		$this->assertSame( 'Kontaktieren', $copy->post_title );
		$this->assertSame( 'kontakt-de', $copy->post_name );
		$this->assertStringContainsString( 'Schreiben Sie uns.', $copy->post_content );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $copy->post_content, 'Blocks stay blocks' );
		$this->assertSame( 'Melden Sie sich', get_post_meta( $copy_id, 'subtitle', true ) );
		$this->assertSame( (string) $this->original->ID, get_post_meta( $copy_id, SWIFTLING_COPY_OF, true ) );
		$this->assertSame( 'de_DE', get_post_meta( $copy_id, SWIFTLING_COPY_LANG, true ) );
		$this->assertSame( $this->original->ID, swiftling_copy_original_id( $copy_id ) );
		$this->assertSame( $copy_id, swiftling_copy_for( $this->original->ID, 'de_DE' )->ID );
		$this->assertSame( array( 'de_DE' ), array_keys( swiftling_copies_of( $this->original->ID ) ) );
	}

	/**
	 * A language has one copy at most, and only a language of the site gets one.
	 */
	public function test_one_copy_per_language() {
		swiftling_make_copy( $this->original, 'de' );
		$again = swiftling_make_copy( $this->original, 'de' );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'swiftling_copy_exists', $again->get_error_code() );
		$this->assertSame( 'swiftling_copy_refused', swiftling_make_copy( $this->original, 'it' )->get_error_code() );
	}

	/**
	 * A copy has no terms of its own: it shows its original's, also when something gives it others.
	 */
	public function test_copy_shows_the_originals_terms() {
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		$this->assertSame( array( $this->category ), wp_list_pluck( get_the_terms( $copy_id, 'category' ), 'term_id' ) );

		$other = self::factory()->category->create();
		wp_set_object_terms( $copy_id, array( $other ), 'category' );
		$this->assertSame( array( $this->category ), wp_list_pluck( get_the_terms( $copy_id, 'category' ), 'term_id' ) );
	}

	/**
	 * The three ways a language shows a post, and moving between them.
	 */
	public function test_language_modes() {
		$id = $this->original->ID;
		$this->assertSame( 'translation', swiftling_language_mode( $id, 'de_DE' ) );

		$this->assertSame( 'duplicate', swiftling_set_language_mode( $this->original, 'de', 'duplicate' ) );
		$this->assertSame( 'duplicate', swiftling_language_mode( $id, 'de_DE' ) );
		$this->assertSame( array( 'de_DE' ), swiftling_duplicate_locales( $id ) );

		$this->assertSame( 'copy_made', swiftling_set_language_mode( $this->original, 'de', 'manual' ) );
		$this->assertSame( 'manual', swiftling_language_mode( $id, 'de_DE' ) );
		$this->assertSame( array(), swiftling_duplicate_locales( $id ), 'A copy replaces the duplicate' );
		$this->assertSame( 'copy_kept', swiftling_set_language_mode( $this->original, 'de', 'manual' ) );

		$copy = swiftling_copy_for( $id, 'de_DE' );
		$this->assertSame( 'translation', swiftling_set_language_mode( $this->original, 'de', 'translation' ) );
		$this->assertSame( 'trash', get_post_status( $copy->ID ), 'The copy waits in the trash' );
		$this->assertSame( 'translation', swiftling_language_mode( $id, 'de_DE' ) );

		$this->assertSame( 'swiftling_mode_refused', swiftling_set_language_mode( $this->original, 'it', 'duplicate' )->get_error_code() );
		$this->assertSame( 'swiftling_mode_refused', swiftling_set_language_mode( $this->original, 'de', 'other' )->get_error_code() );
	}

	/**
	 * Only people who manage the site and may edit the post choose how a language shows it.
	 */
	public function test_choosing_a_mode_needs_rights() {
		$this->become( 'editor' );
		$this->assertFalse( swiftling_can_choose_mode( $this->original ) );
		$this->assertInstanceOf( WP_Error::class, swiftling_set_language_mode( $this->original, 'de', 'duplicate' ) );
		$this->assertFalse( swiftling_can_make_copy( $this->original ) );
	}

	/**
	 * A request for the original in a language with a published copy is answered with the copy.
	 */
	public function test_request_for_the_original_gets_the_copy() {
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		wp_publish_post( $copy_id );
		swiftling_published_copies( 'de_DE', true );

		$vars = array(
			'name' => 'kontakt',
			'page' => '2',
		);
		$this->assertSame( $vars, swiftling_request_copy( $vars ), 'The original language' );
		$this->show_in( 'de' );
		$this->assertSame(
			array(
				'page'      => '2',
				'p'         => $copy_id,
				'post_type' => 'post',
			),
			swiftling_request_copy( $vars )
		);
		$this->assertSame( $copy_id, swiftling_request_copy( array( 'p' => $this->original->ID ) )['p'] );
		$this->assertSame( array( 'name' => 'other' ), swiftling_request_copy( array( 'name' => 'other' ) ) );
		$this->show_in( 'fr' );
		$this->assertSame( $vars, swiftling_request_copy( $vars ), 'No French copy' );
	}

	/**
	 * A copy at its original's address with a slug in another script is not sent to itself when the
	 * request spells it in upper case (#83); the copy's own slug still leads there.
	 */
	public function test_copy_with_a_non_ascii_slug_is_not_redirected_to_itself() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		swiftling_save_slug( 'post:' . $this->original->ID, 'de_DE', 'о-нас' );
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		wp_publish_post( $copy_id );
		swiftling_published_copies( 'de_DE', true );
		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );

		$this->go_to( 'http://example.org/de/%D0%BE-%D0%BD%D0%B0%D1%81/' );
		$this->assertSame( $copy_id, get_queried_object_id() );
		$this->assert_no_redirect( 'swiftling_redirect_copy' );

		$this->go_to( 'http://example.org/de/?p=' . $copy_id );
		$this->assertSame( 'http://example.org/de/%d0%be-%d0%bd%d0%b0%d1%81/', $this->redirect_of( 'swiftling_redirect_copy' ) );
	}

	/**
	 * A page's copy answers at its original's translated address, and so does the copy of a child
	 * page whose parent has a translated slug too: WordPress reads the request with the original
	 * slugs, so the original's path is compared with them (#260).
	 */
	public function test_request_for_a_page_with_a_translated_slug_gets_the_copy() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$parent = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kako do nas',
				'post_name'  => 'kako-do-nas',
			)
		);
		$child  = self::factory()->post->create_and_get(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Parking',
				'post_name'   => 'parking',
				'post_parent' => $parent->ID,
			)
		);
		swiftling_save_slug( 'post:' . $parent->ID, 'de_DE', 'anfahrt' );
		swiftling_save_slug( 'post:' . $child->ID, 'de_DE', 'parkplatz' );
		$copies = array();
		foreach ( array( $parent, $child ) as $page ) {
			$copies[ $page->ID ] = swiftling_make_copy( $page, 'de' );
			wp_publish_post( $copies[ $page->ID ] );
		}
		swiftling_published_copies( 'de_DE', true );
		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );

		$this->go_to( 'http://example.org/de/anfahrt/' );
		$this->assertSame( $copies[ $parent->ID ], get_queried_object_id() );
		$this->go_to( 'http://example.org/de/anfahrt/parkplatz/' );
		$this->assertSame( $copies[ $child->ID ], get_queried_object_id() );
	}

	/**
	 * A published copy's address is its original's in the copy's language; a draft keeps its own, for previews.
	 */
	public function test_copy_addresses() {
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		$this->assertStringNotContainsString( 'lang=de', get_permalink( $copy_id ) );
		$this->assertStringContainsString( 'lang=de', get_preview_post_link( $copy_id ) );

		wp_publish_post( $copy_id );
		$this->assertSame( add_query_arg( 'lang', 'de', get_permalink( $this->original ) ), get_permalink( $copy_id ) );
	}

	/**
	 * Visitors never find a copy in lists of posts.
	 */
	public function test_copies_stay_out_of_lists() {
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		wp_publish_post( $copy_id );
		$query = new \WP_Query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		);
		$this->assertSame( array( $this->original->ID ), array_map( 'intval', $query->posts ) );
		$this->assertSame( array(), swiftling_filter_copy_pages( array( get_post( $copy_id ) ) ) );
	}

	/**
	 * A published copy translates its original into its language; one taken back no longer does.
	 */
	public function test_published_copy_counts_as_translation() {
		$copy_id = swiftling_make_copy( $this->original, 'fr' );
		$this->assertNotContains( 'fr_FR', swiftling_translated_locales( $this->original->ID ) );
		wp_publish_post( $copy_id );
		$this->assertContains( 'fr_FR', swiftling_translated_locales( $this->original->ID ) );
		$this->assertSame( 'reviewed', swiftling_language_states( $this->original->ID )['fr'] );

		wp_update_post(
			array(
				'ID'          => $copy_id,
				'post_status' => 'draft',
			)
		);
		$this->assertNotContains( 'fr_FR', swiftling_translated_locales( $this->original->ID ) );
	}

	/**
	 * Copies go to the trash with their original and are deleted with it.
	 */
	public function test_copies_follow_their_original() {
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		wp_trash_post( $this->original->ID );
		$this->assertSame( 'trash', get_post_status( $copy_id ) );

		wp_delete_post( $this->original->ID, true );
		$this->assertNull( get_post( $copy_id ) );
	}

	/**
	 * The front page of a language is its copy.
	 */
	public function test_front_page_copy() {
		$front = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'Početna',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front->ID );
		$copy_id = swiftling_make_copy( $front, 'de' );
		wp_publish_post( $copy_id );
		swiftling_published_copies( 'de_DE', true );

		$this->assertSame( $front->ID, (int) get_option( 'page_on_front' ) );
		$this->show_in( 'de' );
		$this->assertSame( $copy_id, (int) get_option( 'page_on_front' ) );
		$this->assertSame( $front->ID, swiftling_real_front_page() );
	}

	/**
	 * The admin's list of pages names a copy and its original.
	 */
	public function test_post_state() {
		$copy_id = swiftling_make_copy( $this->original, 'de' );
		$states  = swiftling_copy_post_state( array(), get_post( $copy_id ) );
		$this->assertSame( 'Deutsch copy of “Kontakt”', $states['swiftling_copy'] );
		$this->assertSame( array(), swiftling_copy_post_state( array(), $this->original ) );
	}

	/**
	 * The meta a builder writes again by itself, and SwiftLing's own, stay out of a copy.
	 */
	public function test_meta_left_out_of_copies() {
		$this->assertTrue( swiftling_copy_leaves_out_meta( '_edit_lock' ) );
		$this->assertTrue( swiftling_copy_leaves_out_meta( '_swiftling_duplicate' ) );
		$this->assertFalse( swiftling_copy_leaves_out_meta( 'subtitle' ) );
		$this->assertFalse( swiftling_copy_leaves_out_meta( '_thumbnail_id' ), 'The copy shows the same image' );
	}
}
