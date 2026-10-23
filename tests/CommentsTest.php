<?php
/**
 * Tests of comments and reviews.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Admin\Menu;
use WP_REST_Request;

/**
 * Comments and reviews visitors read under a page: each is translated on its own, on the Comments
 * screen, and shows translated in a language once it has a translation there.
 */
class CommentsTest extends TestCase {

	/**
	 * A published post comments are written on.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Makes the post.
	 */
	public function set_up() {
		parent::set_up();
		$this->post = self::factory()->post->create_and_get( array( 'post_title' => 'Otvorena sezona' ) );
	}

	/**
	 * Makes an approved comment.
	 *
	 * @param string $content Text of the comment.
	 * @param array  $more    Other fields of the comment.
	 * @return int Comment ID.
	 */
	private function comment( $content, $more = array() ) {
		return self::factory()->comment->create(
			array_merge(
				array(
					'comment_post_ID'  => $this->post->ID,
					'comment_content'  => $content,
					'comment_author'   => 'Ivana',
					'comment_approved' => '1',
					'comment_type'     => 'comment',
				),
				$more
			)
		);
	}

	/**
	 * Renders a screen of the menu.
	 *
	 * @param string $view View.
	 * @param array  $get  Query of the screen.
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
	 * The comment's text as a theme prints it.
	 *
	 * @param int $id Comment ID.
	 * @return string
	 */
	private function shown( $id ) {
		return get_comment_text( get_comment( $id ) );
	}

	/**
	 * Translated are the comments visitors read: approved, on a published page of a kind of content
	 * SwiftLing translates. Pingbacks, trackbacks and notes are none, nor are comments waiting or
	 * marked as spam.
	 */
	public function test_comments_that_are_translated() {
		$this->comment( 'Jedva čekamo!' );
		$this->comment( 'Čeka odobrenje.', array( 'comment_approved' => '0' ) );
		$this->comment( 'Kupi jeftino.', array( 'comment_approved' => 'spam' ) );
		$this->comment( 'Spominje vas.', array( 'comment_type' => 'pingback' ) );
		$this->comment( 'Bilješka urednika.', array( 'comment_type' => 'note' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->comment( 'Na skici.', array( 'comment_post_ID' => $draft ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->comment( 'Na stranici.', array( 'comment_post_ID' => $page ) );
		$this->comment( 'Starog oblika.', array( 'comment_type' => '' ) );

		$this->assertSame(
			array(
				'post' => 2,
				'page' => 1,
			),
			swiftling_comment_kinds()
		);

		update_option( 'swiftling_settings', array( 'never' => array( 'page' ) ) );
		$this->assertSame( array( 'post' => 2 ), swiftling_comment_kinds(), 'A kind set to Never is left out' );
		$this->assertNull( swiftling_subject( 'comments:page' ) );
		$this->assertNotNull( swiftling_subject( 'comments:post' ) );
		$this->assertNull( swiftling_subject( 'comments:nothing' ) );
	}

	/**
	 * The list counts a comment as translated in a language when the translation of its text as it
	 * is now is saved, counted by the database: the same key as the translation is saved under, also
	 * for letters outside ASCII.
	 */
	public function test_counted_as_saved() {
		$first  = $this->comment( 'Jedva čekamo! Šećer, ćevapi i 🎉' );
		$second = $this->comment( 'Super.' );
		$this->translate( 'comment:' . $first, 'field/comment_content', 'Jedva čekamo! Šećer, ćevapi i 🎉', 'de', 'Wir freuen uns!' );

		$this->assertSame(
			array(
				'de' => array(
					'translated' => 1,
					'machine'    => 0,
					'total'      => 2,
				),
				'fr' => array(
					'translated' => 0,
					'machine'    => 0,
					'total'      => 2,
				),
			),
			swiftling_comment_progress( 'post' )
		);

		// Machine translated and not reviewed yet: the list says so, as the editor of a page does.
		$this->translate( 'comment:' . $second, 'field/comment_content', 'Super.', 'de', 'Super.', SWIFTLING_MACHINE );
		$this->assertSame( 1, swiftling_comment_progress( 'post' )['de']['machine'] );
		$html = $this->screen(
			'editor',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
			)
		);
		$this->assertMatchesRegularExpression( '~data-lang="de">\s*<span class="swiftling-status is-machine"~', $html );

		// Changed since: its translation is of the text before.
		wp_update_comment(
			array(
				'comment_ID'      => $first,
				'comment_content' => 'Jedva čekamo! Promijenjeno.',
			)
		);
		$this->assertSame( 1, swiftling_comment_progress( 'post' )['de']['translated'] );
	}

	/**
	 * The editor lists one page of comments at a time, those without a translation first, the newest
	 * first; it can show all, search their words and those of their authors, and show the comments of
	 * one page alone.
	 */
	public function test_pages_of_the_list() {
		$ids = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$ids[ $i ] = $this->comment( sprintf( 'Komentar broj %02d', $i ), array( 'comment_date' => sprintf( '2026-10-%02d 10:00:00', $i ) ) );
		}
		$this->translate( 'comment:' . $ids[25], 'field/comment_content', 'Komentar broj 25', 'de', 'Kommentar Nummer 25' );

		$list = swiftling_comment_list( 'post', 'de', array() );
		$this->assertSame( 'missing', $list['show'] );
		$this->assertSame( 24, $list['shown'] );
		$this->assertSame( 2, $list['pages'] );
		$this->assertSame(
			array(
				'missing' => 24,
				'all'     => 25,
			),
			$list['counts']
		);
		$this->assertCount( SWIFTLING_COMMENTS_PER_PAGE, $list['comments'] );
		$this->assertSame( $ids[24], (int) $list['comments'][0]->comment_ID, 'The newest without a translation first' );

		$list = swiftling_comment_list( 'post', 'de', array( 'page' => 2 ) );
		$this->assertCount( 4, $list['comments'] );

		$list = swiftling_comment_list( 'post', 'de', array( 'show' => 'all' ) );
		$this->assertSame( $ids[25], (int) $list['comments'][0]->comment_ID );

		$list = swiftling_comment_list(
			'post',
			'de',
			array(
				'show'   => 'all',
				'search' => 'broj 07',
			)
		);
		$this->assertSame( array( $ids[7] ), array_map( 'intval', wp_list_pluck( $list['comments'], 'comment_ID' ) ) );

		$marko = $this->comment( 'Lijepo.', array( 'comment_author' => 'Marko' ) );
		$list  = swiftling_comment_list( 'post', 'de', array( 'search' => 'marko' ) );
		$this->assertSame( array( $marko ), array_map( 'intval', wp_list_pluck( $list['comments'], 'comment_ID' ) ) );

		$other = self::factory()->post->create();
		$there = $this->comment( 'Na drugom.', array( 'comment_post_ID' => $other ) );
		$list  = swiftling_comment_list( 'post', 'de', array( 'for' => $other ) );
		$this->assertSame( array( $there ), array_map( 'intval', wp_list_pluck( $list['comments'], 'comment_ID' ) ) );
		$this->assertSame( 1, swiftling_comment_progress( 'post', $other )['de']['total'] );

		// Everything translated: all show, nothing is left to filter by.
		foreach ( $ids as $i => $id ) {
			$this->translate( 'comment:' . $id, 'field/comment_content', sprintf( 'Komentar broj %02d', $i ), 'de', 'Kommentar' );
		}
		$this->translate( 'comment:' . $marko, 'field/comment_content', 'Lijepo.', 'de', 'Schön.' );
		$this->translate( 'comment:' . $there, 'field/comment_content', 'Na drugom.', 'de', 'Auf dem anderen.' );
		$this->assertSame( 'all', swiftling_comment_list( 'post', 'de', array() )['show'] );
	}

	/**
	 * Each row names the author, what the comment answers, where it was written and when; the
	 * author's name is no text to translate.
	 */
	public function test_editor_rows() {
		$first = $this->comment( 'Jedva čekamo!', array( 'comment_date' => '2026-10-05 10:00:00' ) );
		$this->comment(
			'Hvala!',
			array(
				'comment_author' => 'Apartmani Marija',
				'comment_parent' => $first,
				'comment_date'   => '2026-10-06 10:00:00',
			)
		);

		$html   = $this->screen(
			'editor',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
			)
		);
		$config = $this->editor_config( $html );
		$rows   = $config['rows']['rows'];
		$this->assertTrue( $config['paged'], 'Its languages count the whole list' );
		$this->assertStringContainsString( 'Jedva čekamo!', $rows );
		$this->assertMatchesRegularExpression( '~Apartmani Marija\s*<span class="swiftling-muted swiftling-live-place">Reply to Ivana · on Otvorena sezona · October 6, 2026</span>~', $rows );
		$this->assertMatchesRegularExpression( '~Ivana\s*<span class="swiftling-muted swiftling-live-place">on Otvorena sezona · October 5, 2026</span>~', $rows );
		$this->assertStringContainsString( 'Search comments', $html );
		$this->assertStringContainsString( 'Without a translation', $html );
		$this->assertSame( 2, $config['comments']['missing'] );

		// The comments of one page: said above the list, with the way back to all.
		$html = $this->screen(
			'editor',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
				'for'     => (string) $this->post->ID,
			)
		);
		$this->assertStringContainsString( 'Only the comments on “Otvorena sezona”', $html );
		$this->assertSame( $this->post->ID, $this->editor_config( $html )['comments']['for'] );
	}

	/**
	 * A translation saved in the editor shows to visitors in that language; the original elsewhere,
	 * and again once the comment changes. A comment on a kind of content set to Never stays as written.
	 */
	public function test_translation_shows_in_the_language() {
		$id = $this->comment( 'Jedva čekamo!' );
		$this->screen(
			'editor',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
			)
		);
		$place    = swiftling_place_key( 'comment:' . $id, swiftling_path_key( 'field/comment_content' ), swiftling_hash( 'Jedva čekamo!' ) );
		$response = $this->call(
			'/translations',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
				'status'  => 'reviewed',
				'items'   => array(
					array(
						'key'  => $place,
						'text' => 'Wir können es kaum erwarten!',
					),
				),
			)
		);
		$this->assertSame( array( $place => 'reviewed' ), $response->get_data()['states'] );

		$this->show_in( 'de' );
		$this->assertSame( 'Wir können es kaum erwarten!', $this->shown( $id ) );
		$this->assertSame( 'Ivana', get_comment_author( $id ), 'The author keeps the name' );
		$this->show_in( 'fr' );
		$this->assertSame( 'Jedva čekamo!', $this->shown( $id ) );
		$this->reset_state();
		$this->assertSame( 'Jedva čekamo!', $this->shown( $id ), 'The original' );

		update_option( 'swiftling_settings', array( 'never' => array( 'post' ) ) );
		$this->show_in( 'de' );
		$this->assertSame( 'Jedva čekamo!', $this->shown( $id ), 'Never translated' );
		delete_option( 'swiftling_settings' );

		wp_update_comment(
			array(
				'comment_ID'      => $id,
				'comment_content' => 'Jedva čekamo ljeto!',
			)
		);
		$this->show_in( 'de' );
		$this->assertSame( 'Jedva čekamo ljeto!', $this->shown( $id ), 'Changed since its translation' );
	}

	/**
	 * Only who may edit a comment may translate it; the list is for who moderates comments.
	 */
	public function test_who_may_translate() {
		$id = $this->comment( 'Jedva čekamo!' );
		$this->become( 'administrator' );
		$this->assertTrue( swiftling_can_translate_object( 'comment:' . $id ) );
		$this->assertTrue( swiftling_can_translate_subject( swiftling_subject( 'comments:post' ) ) );
		$this->become( 'author' );
		$this->assertFalse( swiftling_can_translate_object( 'comment:' . $id ) );
		$this->assertFalse( swiftling_can_translate_subject( swiftling_subject( 'comments:post' ) ) );
	}

	/**
	 * The translations of the comments under a page are read with the comments, however many there
	 * are: a page with many reviews does not ask the database once for each.
	 */
	public function test_comments_of_a_page_read_at_once() {
		$ids = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$ids[] = $this->comment( 'Komentar ' . $i );
		}
		$this->screen(
			'editor',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
			)
		);
		$this->translate( 'comment:' . $ids[0], 'field/comment_content', 'Komentar 1', 'de', 'Kommentar 1' );
		wp_cache_flush();

		global $wpdb;
		$this->show_in( 'de' );
		$comments = get_comments(
			array(
				'post_id' => $this->post->ID,
				'order'   => 'ASC',
				'orderby' => 'comment_ID',
			)
		);
		// The first reads what every request reads once, such as SwiftLing's settings.
		$texts  = array( get_comment_text( $comments[0] ) );
		$before = $wpdb->num_queries;
		foreach ( array_slice( $comments, 1 ) as $comment ) {
			$texts[] = get_comment_text( $comment );
		}
		$this->assertSame( 0, $wpdb->num_queries - $before, 'Read when the comments were' );
		$this->assertSame( 'Kommentar 1', $texts[0] );
		$this->assertSame( 'Komentar 2', $texts[1] );
	}

	/**
	 * Translating every comment without a translation goes in batches, the newest first, each saved
	 * for the editor's routes so the batch's texts can be translated and saved; the batch says how
	 * many are left after it.
	 */
	public function test_batches_of_the_whole_list() {
		$ids = array();
		for ( $i = 1; $i <= 3; $i++ ) {
			$ids[ $i ] = $this->comment( 'Komentar ' . $i, array( 'comment_date' => sprintf( '2026-10-%02d 10:00:00', $i ) ) );
		}
		$this->translate( 'comment:' . $ids[2], 'field/comment_content', 'Komentar 2', 'de', 'Kommentar 2' );
		$this->become( 'administrator' );

		$batch = swiftling_comment_batch( swiftling_subject( 'comments:post' ), 'de', array( 'size' => 1 ) );
		$this->assertSame( array( 'Komentar 3' ), wp_list_pluck( $batch['items'], 'text' ) );
		$this->assertSame( 1, $batch['left'] );

		$response = $this->call(
			'/translations',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
				'status'  => 'machine',
				'items'   => array(
					array(
						'key'  => $batch['items'][0]['key'],
						'text' => 'Kommentar 3',
					),
				),
			)
		);
		$this->assertSame( 'machine', current( $response->get_data()['states'] ), 'The batch can be saved' );

		$batch = swiftling_comment_batch(
			swiftling_subject( 'comments:post' ),
			'de',
			array(
				'size'  => 1,
				'after' => $batch['after'],
			)
		);
		$this->assertSame( array( 'Komentar 1' ), wp_list_pluck( $batch['items'], 'text' ) );
		$this->assertSame( 0, $batch['left'] );

		$response = $this->call(
			'/comment-batch',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
				'after'   => $batch['after'],
			)
		);
		$this->assertSame( array(), $response->get_data()['items'], 'Nothing older' );

		$this->become( 'author' );
		$response = $this->call(
			'/comment-batch',
			array(
				'subject' => 'comments:post',
				'lang'    => 'de',
			)
		);
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A saved translation of a comment empties the cached page it is written on, in that language.
	 */
	public function test_saved_translation_empties_its_page() {
		$id    = $this->comment( 'Jedva čekamo!' );
		$pages = swiftling_pages_showing( 'comment:' . $id );
		$this->assertSame( array( $this->post->ID ), wp_list_pluck( $pages, 'ID' ) );
	}

	/**
	 * The Comments screen lists the kinds of content with comments, each with its state in every
	 * language and the way to translate them; the editor of a page says how many comments it has.
	 */
	public function test_screen_and_the_page_editor() {
		$this->comment( 'Jedva čekamo!' );
		$this->comment( 'Super.' );

		$html = $this->screen( 'comments' );
		$this->assertStringContainsString( 'Posts', $html );
		$this->assertStringContainsString( esc_url( swiftling_editor_url( 'comments:post' ) ), $html );
		$this->assertStringContainsString( '0/2', $html );

		$this->assertArrayHasKey( 'swiftling-comments', Menu::screens() );

		$html = $this->screen(
			'editor',
			array(
				'post' => (string) $this->post->ID,
				'lang' => 'de',
			)
		);
		$this->assertStringContainsString( '2 comments', $html );
		$this->assertStringContainsString( '2 not translated in Deutsch', $html );
		$this->assertStringContainsString( esc_url( add_query_arg( 'for', $this->post->ID, swiftling_editor_url( 'comments:post', 'de' ) ) ), $html );

		$quiet = self::factory()->post->create();
		$html  = $this->screen(
			'editor',
			array(
				'post' => (string) $quiet,
				'lang' => 'de',
			)
		);
		$this->assertStringNotContainsString( 'swiftling-comments-card', $html, 'No comments, nothing said' );
	}
}
