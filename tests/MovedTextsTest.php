<?php
/**
 * Tests of texts that move on their page.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A block's text is kept by its place on the page and its wording, and a place in the block editor
 * is a position: a block added above others, or two blocks swapped, puts texts that did not change
 * at other positions. They keep their translations, for visitors as soon as the page is saved and
 * in the translation editor once the page is checked again.
 */
class MovedTextsTest extends TestCase {

	/**
	 * The page.
	 *
	 * @var \WP_Post
	 */
	private $post;

	/**
	 * Subject key of the page.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Makes a page and answers the server's checks of it, and signs in as an administrator.
	 */
	public function set_up() {
		parent::set_up();
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Ture',
				'post_excerpt' => '',
				'post_content' => $this->blocks( array( 'Polazimo ujutro.', 'Grupe su male.', 'Ponesite vodu.' ) ),
			)
		);
		$this->key  = 'post:' . $this->post->ID;
		$this->serve_page_check(
			$this->key,
			function () {
				$this->render();
			}
		);
		$this->become( 'administrator' );
	}

	/**
	 * Returns the content of paragraphs, as the block editor saves them.
	 *
	 * @param string[] $texts Text of each paragraph.
	 * @return string
	 */
	private function blocks( $texts ) {
		$blocks = array();
		foreach ( $texts as $text ) {
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Saves the page with other paragraphs, as the block editor does.
	 *
	 * @param string[] $texts Text of each paragraph.
	 */
	private function save( $texts ) {
		wp_update_post(
			array(
				'ID'           => $this->post->ID,
				'post_content' => $this->blocks( $texts ),
			)
		);
		$this->post = get_post( $this->post->ID );
	}

	/**
	 * Renders the page's content as a theme does and returns the text of each paragraph.
	 *
	 * @return string[]
	 */
	private function render() {
		$post            = get_post( $this->post->ID );
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The loop of a theme sets it.
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, as a theme runs it.
		wp_reset_postdata();
		preg_match_all( '~<p[^>]*>(.*?)</p>~s', $html, $paragraphs );
		return $paragraphs[1];
	}

	/**
	 * Returns the paragraphs a visitor reads in German.
	 *
	 * @return string[]
	 */
	private function german_page() {
		wp_cache_flush();
		$this->show_in( 'de' );
		$paragraphs = $this->render();
		$this->reset_state();
		return $paragraphs;
	}

	/**
	 * Translates the page's paragraphs into German, as the translation editor saves them.
	 *
	 * @param array<string, string> $words Original => German.
	 * @param string                $state reviewed or machine.
	 */
	private function translate_paragraphs( $words, $state = 'reviewed' ) {
		$items = array();
		foreach ( $this->paragraph_texts() as $original => $text ) {
			if ( isset( $words[ $original ] ) ) {
				$items[] = array(
					'key'  => $text['key'],
					'text' => str_replace( $original, $words[ $original ], $text['original'] ),
				);
			}
		}
		$this->assertCount( count( $words ), $items, 'Every paragraph to translate is listed' );
		swiftling_save_texts( $this->key, 'de', $items, $state );
	}

	/**
	 * Returns the paragraphs the translation editor lists for German, after a check of the page,
	 * each with its state, its translation and the translation of its original before it changed.
	 *
	 * @return array<string, array{key: string, original: string, state: string, translation: string, previous: string}> By the paragraph's text.
	 */
	private function paragraph_texts() {
		wp_cache_flush();
		$texts = swiftling_subject_texts( $this->key, 'de' );
		$this->assertIsArray( $texts, is_wp_error( $texts ) ? $texts->get_error_message() : '' );
		$listed = array();
		foreach ( $texts['texts'] as $text ) {
			if ( $this->key === $text['object'] && 0 === strpos( $text['path'], 'b/' ) ) {
				$listed[ trim( wp_strip_all_tags( $text['original'] ) ) ] = array(
					'key'         => $text['key'],
					'original'    => $text['original'],
					'state'       => $text['state'],
					'translation' => trim( wp_strip_all_tags( $text['translation'] ) ),
					'previous'    => trim( wp_strip_all_tags( $text['previous'] ) ),
				);
			}
		}
		return $listed;
	}

	/**
	 * Returns the state and translation of each paragraph in the translation editor.
	 *
	 * @return array<string, string> Paragraph => "state: translation", with "< previous" while outdated.
	 */
	private function editor_rows() {
		$rows = array();
		foreach ( $this->paragraph_texts() as $original => $text ) {
			$rows[ $original ] = $text['state'] . ': ' . $text['translation'] . ( '' !== $text['previous'] ? ' < ' . $text['previous'] : '' );
		}
		return $rows;
	}

	/**
	 * Translates the three paragraphs into German.
	 */
	private function translate_page() {
		$this->translate_paragraphs(
			array(
				'Polazimo ujutro.' => 'Wir starten morgens.',
				'Grupe su male.'   => 'Die Gruppen sind klein.',
				'Ponesite vodu.'   => 'Bringen Sie Wasser mit.',
			)
		);
		$this->assertSame( array( 'Wir starten morgens.', 'Die Gruppen sind klein.', 'Bringen Sie Wasser mit.' ), $this->german_page() );
	}

	/**
	 * A paragraph added above the others: visitors read the others translated as soon as the page is
	 * saved, before anyone opens the translation editor, and only the new one in the original.
	 */
	public function test_paragraph_added_above_shows_the_others_translated_right_away() {
		$this->translate_page();

		$this->save( array( 'Novo: rezervacije od svibnja.', 'Polazimo ujutro.', 'Grupe su male.', 'Ponesite vodu.' ) );

		$this->assertSame( array( 'Novo: rezervacije od svibnja.', 'Wir starten morgens.', 'Die Gruppen sind klein.', 'Bringen Sie Wasser mit.' ), $this->german_page() );
	}

	/**
	 * Once the page is checked again, the translation editor lists the paragraphs that only moved as
	 * translated, and the new one as the only text to translate, without an earlier translation of
	 * another paragraph.
	 */
	public function test_paragraph_added_above_keeps_the_others_translated_in_the_editor() {
		$this->translate_page();

		$this->save( array( 'Novo: rezervacije od svibnja.', 'Polazimo ujutro.', 'Grupe su male.', 'Ponesite vodu.' ) );

		$this->assertSame(
			array(
				'Novo: rezervacije od svibnja.' => 'missing: ',
				'Polazimo ujutro.'              => 'reviewed: Wir starten morgens.',
				'Grupe su male.'                => 'reviewed: Die Gruppen sind klein.',
				'Ponesite vodu.'                => 'reviewed: Bringen Sie Wasser mit.',
			),
			$this->editor_rows()
		);
		$this->assertSame( 'partial', swiftling_language_states( $this->post->ID )['de'], 'Only the new paragraph waits' );
		$this->assertSame( array( 'Novo: rezervacije od svibnja.', 'Wir starten morgens.', 'Die Gruppen sind klein.', 'Bringen Sie Wasser mit.' ), $this->german_page() );
	}

	/**
	 * Two paragraphs swapped keep their translations, and a machine translation stays one.
	 */
	public function test_swapped_paragraphs_keep_their_translations() {
		$this->translate_paragraphs(
			array(
				'Polazimo ujutro.' => 'Wir starten morgens.',
				'Grupe su male.'   => 'Die Gruppen sind klein.',
			),
			'machine'
		);
		$this->translate_paragraphs( array( 'Ponesite vodu.' => 'Bringen Sie Wasser mit.' ) );

		$this->save( array( 'Grupe su male.', 'Polazimo ujutro.', 'Ponesite vodu.' ) );
		$this->assertSame( array( 'Die Gruppen sind klein.', 'Wir starten morgens.', 'Bringen Sie Wasser mit.' ), $this->german_page(), 'Right after saving' );

		$this->assertSame(
			array(
				'Grupe su male.'   => 'machine: Die Gruppen sind klein.',
				'Polazimo ujutro.' => 'machine: Wir starten morgens.',
				'Ponesite vodu.'   => 'reviewed: Bringen Sie Wasser mit.',
			),
			$this->editor_rows()
		);
		$this->assertSame( array( 'Die Gruppen sind klein.', 'Wir starten morgens.', 'Bringen Sie Wasser mit.' ), $this->german_page(), 'After the check' );
	}

	/**
	 * A paragraph whose original changed after it was translated, and then moved, still waits for its
	 * new translation, with its own earlier translation beside it.
	 */
	public function test_changed_paragraph_that_moves_stays_outdated() {
		$this->translate_page();
		$this->save( array( 'Polazimo u zoru.', 'Grupe su male.', 'Ponesite vodu.' ) );
		$this->assertSame( 'outdated:  < Wir starten morgens.', $this->editor_rows()['Polazimo u zoru.'] );

		$this->save( array( 'Novo: rezervacije od svibnja.', 'Polazimo u zoru.', 'Grupe su male.', 'Ponesite vodu.' ) );

		$this->assertSame(
			array(
				'Novo: rezervacije od svibnja.' => 'missing: ',
				'Polazimo u zoru.'              => 'outdated:  < Wir starten morgens.',
				'Grupe su male.'                => 'reviewed: Die Gruppen sind klein.',
				'Ponesite vodu.'                => 'reviewed: Bringen Sie Wasser mit.',
			),
			$this->editor_rows()
		);
	}

	/**
	 * The same words in two places can have two translations. When both move, SwiftLing cannot tell
	 * which translation belongs where, so it shows neither rather than guessing; when one stays, the
	 * other takes the translation of the place it left.
	 */
	public function test_same_words_translated_twice_are_never_guessed() {
		$this->translate_twice();
		$this->assertSame( array( 'Ja.', 'Grupe su male.', 'Jawohl.' ), $this->german_page() );

		$this->save( array( 'Novo.', 'Da.', 'Grupe su male.', 'Da.' ) );
		$this->assertSame( array( 'Novo.', 'Da.', 'Grupe su male.', 'Da.' ), $this->german_page(), 'Right after saving' );

		$texts = $this->texts_by_path();
		$this->assertSame( array( 'missing', 'missing' ), array( $texts['b/2/c0']['state'], $texts['b/6/c0']['state'] ), 'After the check' );
		$this->assertSame( array( 'Novo.', 'Da.', 'Grupe su male.', 'Da.' ), $this->german_page(), 'After the check' );
	}

	/**
	 * Saves the page with the same words twice and translates them differently: Ja. and Jawohl.
	 */
	private function translate_twice() {
		$this->save( array( 'Da.', 'Grupe su male.', 'Da.' ) );
		$texts = $this->texts_by_path();
		swiftling_save_texts(
			$this->key,
			'de',
			array(
				array(
					'key'  => $texts['b/0/c0']['key'],
					'text' => str_replace( 'Da.', 'Ja.', $texts['b/0/c0']['original'] ),
				),
				array(
					'key'  => $texts['b/4/c0']['key'],
					'text' => str_replace( 'Da.', 'Jawohl.', $texts['b/4/c0']['original'] ),
				),
			),
			'reviewed'
		);
	}

	/**
	 * When one of two equal texts stays and the other moves, the one that moved takes the
	 * translation of the place it left, not the one of the text that stayed, once the page is
	 * checked again.
	 */
	public function test_same_words_one_stays_one_moves() {
		$this->translate_twice();

		$this->save( array( 'Da.', 'Grupe su male.', 'Novo.', 'Da.' ) );
		$texts = $this->texts_by_path();
		$this->assertSame( 'Ja.', trim( wp_strip_all_tags( $texts['b/0/c0']['translation'] ) ), 'The one that stayed' );
		$this->assertSame( 'Jawohl.', trim( wp_strip_all_tags( $texts['b/6/c0']['translation'] ) ), 'The one that moved' );
		$this->assertSame( array( 'Ja.', 'Grupe su male.', 'Novo.', 'Jawohl.' ), $this->german_page() );
	}

	/**
	 * A paragraph copied to another place of the page, the original staying where it was, shows the
	 * original's translation there too, right after saving and once the page is checked again.
	 */
	public function test_copied_paragraph_takes_the_translation() {
		$this->translate_page();

		$this->save( array( 'Polazimo ujutro.', 'Grupe su male.', 'Ponesite vodu.', 'Polazimo ujutro.' ) );
		$this->assertSame( array( 'Wir starten morgens.', 'Die Gruppen sind klein.', 'Bringen Sie Wasser mit.', 'Wir starten morgens.' ), $this->german_page(), 'Right after saving' );

		$texts = $this->texts_by_path();
		$this->assertSame( 'reviewed', $texts['b/6/c0']['state'] );
		$this->assertSame( 'Wir starten morgens.', trim( wp_strip_all_tags( $texts['b/6/c0']['translation'] ) ) );
	}

	/**
	 * Returns the page's texts in the translation editor, after a check of the page, by path.
	 *
	 * @return array<string, array> Path => text, see swiftling_subject_texts().
	 */
	private function texts_by_path() {
		wp_cache_flush();
		$texts = swiftling_subject_texts( $this->key, 'de' );
		$this->assertIsArray( $texts );
		$by_path = array();
		foreach ( $texts['texts'] as $text ) {
			if ( $this->key === $text['object'] ) {
				$by_path[ $text['path'] ] = $text;
			}
		}
		return $by_path;
	}

	/**
	 * Only the text of a block follows its wording to another place. A setting that reads the same as
	 * a translated text, such as a block's class named like the page's title, stays as it is.
	 */
	public function test_a_setting_that_reads_like_a_text_is_left_alone() {
		$this->paragraph_texts();
		$title = null;
		foreach ( swiftling_subject_texts( $this->key, 'de' )['texts'] as $text ) {
			if ( 'field/post_title' === $text['path'] ) {
				$title = $text;
			}
		}
		$this->assertNotNull( $title );
		swiftling_save_texts(
			$this->key,
			'de',
			array(
				array(
					'key'  => $title['key'],
					'text' => 'Touren',
				),
			),
			'reviewed'
		);

		wp_cache_flush();
		$this->show_in( 'de' );
		$this->assertSame( 'Touren', get_the_title( $this->post->ID ) );
		$this->assertSame(
			array( 'className' => 'Ture' ),
			swiftling_transform( $this->key, 'b/0/a', 'block:core/paragraph', array( 'className' => 'Ture' ) )
		);
	}
}
