<?php
/**
 * Tests of the LearnPress class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\LearnPress;
use SwiftLing\Builders\Registry;

require_once __DIR__ . '/stubs/FakeLearnPress.php';

/**
 * What SwiftLing translates of a course of LearnPress beyond its posts: the fields the plugin reads
 * from its own copy of the course, the sections, the answers of a question, the blanks of a sentence
 * and what the student types in them.
 */
class LearnPressTest extends TestCase {

	/**
	 * The class, with the plugin's post types and tables.
	 *
	 * @var FakeLearnPress
	 */
	private $lp;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeLearnPress::install();
		$this->lp = new FakeLearnPress();
		$builders = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof LearnPress ? $this->lp : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->lp->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Makes a course with its fields, as the plugin keeps them, and two sections.
	 *
	 * @return array{course: int, sections: int[]}
	 */
	private function course() {
		global $wpdb;
		$course = self::factory()->post->create(
			array(
				'post_type'  => 'lp_course',
				'post_title' => 'Začinsko bilje',
				'post_name'  => 'zacinsko-bilje',
			)
		);
		update_post_meta( $course, '_lp_requirements', array( 'Sunčana prozorska daska', 'Tri lončića' ) );
		update_post_meta( $course, '_lp_key_features', array( 'Plan zalijevanja' ) );
		update_post_meta( $course, '_lp_target_audiences', array( 'Kuhari' ) );
		update_post_meta( $course, '_lp_faqs', array( array( 'Treba li gnojivo?', 'Malo, jednom mjesečno.' ) ) );
		update_post_meta( $course, '_lp_price_prefix', 'Samo' );
		$sections = array();
		foreach ( array( array( 'Osnove bilja', 'Što bilju treba.' ), array( 'Provjera znanja', 'Kratki test.' ) ) as $order => $section ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's table, filled for a test.
			$wpdb->insert(
				$wpdb->prefix . 'learnpress_sections',
				array(
					'section_name'        => $section[0],
					'section_description' => $section[1],
					'section_course_id'   => $course,
					'section_order'       => $order + 1,
				)
			);
			$sections[] = (int) $wpdb->insert_id;
		}
		return array(
			'course'   => $course,
			'sections' => $sections,
		);
	}

	/**
	 * Makes a question of a quiz with its answers, as rows of the plugin's tables.
	 *
	 * @param int    $quiz    Quiz ID.
	 * @param string $title   The question.
	 * @param array  $answers Answers: title, and blanks for fill in the blanks.
	 * @return array{id: int, answers: int[]}
	 */
	private function question( $quiz, $title, $answers ) {
		global $wpdb;
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'lp_question',
				'post_title'   => $title,
				'post_content' => 'Odaberite.',
			)
		);
		update_post_meta( $id, '_lp_hint', 'Razmislite o suncu.' );
		update_post_meta( $id, '_lp_explanation', 'Bosiljak voli toplinu.' );
		$ids = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's tables, filled for a test.
		foreach ( $answers as $index => $answer ) {
			$wpdb->insert(
				$wpdb->prefix . 'learnpress_question_answers',
				array(
					'question_id' => $id,
					'title'       => $answer['title'],
					'value'       => md5( $answer['title'] ),
					'order'       => $index + 1,
				)
			);
			$ids[] = (int) $wpdb->insert_id;
			if ( isset( $answer['blanks'] ) ) {
				$wpdb->insert(
					$wpdb->prefix . 'learnpress_question_answermeta',
					array(
						'learnpress_question_answer_id' => $wpdb->insert_id,
						'meta_key'                      => '_blanks', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The plugin's own meta.
						'meta_value'                    => maybe_serialize( $answer['blanks'] ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The plugin's own meta.
					)
				);
			}
		}
		$wpdb->insert(
			$wpdb->prefix . 'learnpress_quiz_questions',
			array(
				'quiz_id'     => $quiz,
				'question_id' => $id,
			)
		);
		// phpcs:enable
		return array(
			'id'      => $id,
			'answers' => $ids,
		);
	}

	/**
	 * Returns a course as the plugin hands it to its filters: a model that knows its ID.
	 *
	 * @param int $id Course ID.
	 * @return object
	 */
	private static function model( $id ) {
		return new class( $id ) {
			/**
			 * Course ID.
			 *
			 * @var int
			 */
			private $id;

			/**
			 * Keeps the ID.
			 *
			 * @param int $id Course ID.
			 */
			public function __construct( $id ) {
				$this->id = $id;
			}

			/**
			 * Returns the ID.
			 */
			public function get_id() {
				return $this->id;
			}
		};
	}

	/**
	 * Returns a text the editor offers with a post's fields or on its page.
	 *
	 * @param array  $result What check() returned.
	 * @param string $needle Words in the value.
	 * @return array The text.
	 */
	private function offered( $result, $needle ) {
		foreach ( array_merge( $result['sections']['page'], $result['sections']['fields'] ) as $unit ) {
			if ( false !== strpos( $unit['v'], $needle ) ) {
				return $unit;
			}
		}
		$this->fail( 'The editor offers no text with "' . $needle . '".' );
		return array();
	}

	/**
	 * Applies a filter of the plugin, as it does.
	 *
	 * @param string $hook The filter.
	 * @param mixed  ...$args Its arguments.
	 * @return mixed
	 */
	private static function lp( $hook, ...$args ) {
		return apply_filters( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin's own filters, as it runs them.
	}

	/**
	 * The plugin reads the fields of a course from its own copy of the course, past get_post_meta():
	 * the editor of the course offers them and its sections, named, and a page in a language shows
	 * them translated where the plugin prints them. The original prints as stored.
	 */
	public function test_course_fields_and_sections() {
		$ids    = $this->course();
		$result = $this->check( 'post:' . $ids['course'], '__return_null' );
		$req    = $this->offered( $result, 'Tri lončića' );
		$faq    = $this->offered( $result, 'Treba li gnojivo' );
		$answer = $this->offered( $result, 'jednom mjesečno' );
		$name   = $this->offered( $result, 'Osnove bilja' );
		$desc   = $this->offered( $result, 'Što bilju treba' );
		$prefix = $this->offered( $result, 'Samo' );
		$this->assertSame( 'Requirements', $req['place'] );
		$this->assertSame( 'Section: Osnove bilja › Name', $name['place'] );
		$this->assertSame( LearnPress::sectionPath( $ids['sections'][0], 'name' ), $name['p'] );

		$owner = 'post:' . $ids['course'];
		$this->translate( $owner, $req['p'], 'Tri lončića', 'de', 'Drei Töpfe' );
		$this->translate( $owner, $faq['p'], 'Treba li gnojivo?', 'de', 'Braucht es Dünger?' );
		$this->translate( $owner, $answer['p'], 'Malo, jednom mjesečno.', 'de', 'Wenig, einmal im Monat.' );
		$this->translate( $owner, $name['p'], 'Osnove bilja', 'de', 'Kräutergrundlagen' );
		$this->translate( $owner, $desc['p'], 'Što bilju treba.', 'de', 'Was Kräuter brauchen.' );
		$this->translate( $owner, $prefix['p'], 'Samo', 'de', 'Nur' );

		$model    = self::model( $ids['course'] );
		$list     = array( 'content' => '<ul><li>Sunčana prozorska daska</li><li>Tri lončića</li></ul>' );
		$faqs     = array( 'content' => '<label class="course-faqs-box__title" for="x">Treba li gnojivo?</label><div class="course-faqs-box__content-inner">Malo, jednom mjesečno.</div>' );
		$header   = array( 'header' => '<div class="course-section__title">Osnove bilja</div><div class="course-section__description">Što bilju treba.</div>' );
		$section  = (object) array(
			'section_id'          => $ids['sections'][0],
			'section_name'        => 'Osnove bilja',
			'section_description' => 'Što bilju treba.',
		);
		$price    = '<span class="course-price-prefix">Samo</span><span class="price">20 €</span>';
		$rendered = function () use ( $model, $list, $faqs, $header, $section, $price ) {
			return array(
				self::lp( 'learn-press/course/html-requirements', $list, $model )['content'],
				self::lp( 'learn-press/course/html-faqs', $faqs, $model )['content'],
				self::lp( 'learn-press/course/html-section-item', $header, $model, null, $section )['header'],
				self::lp( 'learn-press/course/html-price', $price, $model ),
			);
		};

		$this->show_in( 'de' );
		$this->assertSame(
			array(
				'<ul><li>Sunčana prozorska daska</li><li>Drei Töpfe</li></ul>',
				'<label class="course-faqs-box__title" for="x">Braucht es Dünger?</label><div class="course-faqs-box__content-inner">Wenig, einmal im Monat.</div>',
				'<div class="course-section__title">Kräutergrundlagen</div><div class="course-section__description">Was Kräuter brauchen.</div>',
				'<span class="course-price-prefix">Nur</span><span class="price">20 €</span>',
			),
			$rendered(),
			'Only the item with a translation changes'
		);

		$this->reset_state();
		$this->assertSame( array( $list['content'], $faqs['content'], $header['header'], $price ), $rendered(), 'The original prints as stored' );
	}

	/**
	 * The answers of a question are rows of the plugin's table: the editor of the question and of its
	 * quiz offer them with its hint and explanation, and the quiz in a language shows them translated.
	 * The values the plugin grades a choice by stay as stored.
	 */
	public function test_answers_offered_and_translated() {
		$quiz   = self::factory()->post->create( array( 'post_type' => 'lp_quiz' ) );
		$choice = $this->question( $quiz, 'Što je trajnica?', array( array( 'title' => 'Majčina dušica' ), array( 'title' => 'Bosiljak' ) ) );

		$question = $this->check( 'post:' . $choice['id'], '__return_null' );
		$first    = $this->offered( $question, 'Majčina dušica' );
		$this->assertSame( 'Question: Što je trajnica? › Answer 1', $first['place'] );
		$this->assertSame( 'post:' . $choice['id'], $first['o'], 'An answer is a text of its question' );
		$this->assertSame( 'Question: Što je trajnica? › Hint', $this->offered( $question, 'Razmislite' )['place'] );

		$quiz_texts = $this->check( 'post:' . $quiz, '__return_null' );
		$this->assertSame( $first['p'], $this->offered( $quiz_texts, 'Majčina dušica' )['p'], 'The quiz offers the texts of its questions' );
		$this->offered( $quiz_texts, 'Što je trajnica' );
		$this->offered( $quiz_texts, 'Bosiljak voli toplinu' );

		$this->translate( 'post:' . $choice['id'], $first['p'], 'Majčina dušica', 'de', 'Thymian' );
		$options = array(
			array(
				'title' => 'Majčina dušica',
				'value' => md5( 'Majčina dušica' ),
				'uid'   => $choice['answers'][0],
			),
			array(
				'title' => 'Bosiljak',
				'value' => md5( 'Bosiljak' ),
				'uid'   => $choice['answers'][1],
			),
		);

		$this->show_in( 'de' );
		$shown = self::lp( 'learn-press/question/answer-options', $options, $choice['id'] );
		$this->assertSame( 'Thymian', $shown[0]['title'] );
		$this->assertSame( md5( 'Majčina dušica' ), $shown[0]['value'], 'The value grades the answer' );
		$this->assertSame( 'Bosiljak', $shown[1]['title'], 'An answer without a translation stays' );

		$this->reset_state();
		$this->assertSame( $options, self::lp( 'learn-press/question/answer-options', $options, $choice['id'] ) );
	}

	/**
	 * A sentence with blanks is offered with {{FIB_<id>}} for each blank, and each blank's right answer
	 * as a text of its own. A translation with other blanks shows the original. In a language the
	 * right answer in that language is right, as the original is; the plugin's own verdict and a range
	 * of numbers are left alone, and in the original the answer in the language is wrong.
	 */
	public function test_blanks_translated_and_graded() {
		$quiz   = self::factory()->post->create( array( 'post_type' => 'lp_quiz' ) );
		$blanks = array(
			'b1' => array(
				'id'         => 'b1',
				'fill'       => 'zemlja',
				'comparison' => '',
				'match_case' => 0,
			),
			'b2' => array(
				'id'         => 'b2',
				'fill'       => 'prst, dlan',
				'comparison' => 'any',
				'match_case' => 0,
			),
			'b3' => array(
				'id'         => 'b3',
				'fill'       => '2,5',
				'comparison' => 'range',
			),
		);
		$fib    = $this->question(
			$quiz,
			'Dovršite rečenicu.',
			array(
				array(
					'title'  => 'Zalijte kad je [fib fill="zemlja" id="b1"] suha pod [fib fill="prst" id="b2"] za [fib id="b3"] dana.',
					'blanks' => $blanks,
				),
			)
		);
		$answer = $fib['answers'][0];
		$result = $this->check( 'post:' . $fib['id'], '__return_null' );
		$text   = $this->offered( $result, 'Zalijte' );
		$this->assertSame( 'Zalijte kad je {{FIB_b1}} suha pod {{FIB_b2}} za {{FIB_b3}} dana.', $text['v'] );
		$this->assertSame( 'Question: Dovršite rečenicu. › Right answer of blank b2', $this->offered( $result, 'prst, dlan' )['place'] );
		foreach ( $result['units'] as $unit ) {
			$this->assertNotSame( '2,5', $unit['v'], 'A range of numbers is no text' );
		}

		$owner = 'post:' . $fib['id'];
		$this->translate( $owner, $text['p'], $text['v'], 'de', 'Gießen, wenn {{FIB_b1}} unter {{FIB_b2}} nach {{FIB_b3}} Tagen trocken ist.' );
		$this->translate( $owner, LearnPress::answerPath( $answer, 'blank/b1' ), 'zemlja', 'de', 'Erde' );
		$this->translate( $owner, LearnPress::answerPath( $answer, 'blank/b2' ), 'prst, dlan', 'de', 'Finger, Hand' );
		$stored = 'Zalijte kad je [fib fill="zemlja" id="b1"] suha pod [fib fill="prst" id="b2"] za [fib id="b3"] dana.';
		$grade  = function ( $blank, $typed, $verdict = false ) use ( $blanks ) {
			return self::lp(
				'learn-press/question/fill-in-blank/check_answer',
				$verdict,
				array(
					'blank'     => $blanks[ $blank ],
					'user_fill' => $typed,
				)
			);
		};

		$this->show_in( 'de' );
		$this->assertSame( 'Gießen, wenn [fib fill="zemlja" id="b1"] unter [fib fill="prst" id="b2"] nach [fib id="b3"] Tagen trocken ist.', self::lp( 'learn-press/question/fib/regex-content', $stored, $answer ), 'Each placeholder goes back as its shortcode' );
		$review = self::lp(
			'learn-press/question/answer-options',
			array(
				array(
					'uid'       => $answer,
					'title'     => 'shown by the plugin',
					'title_api' => 'shown by the plugin',
					'answers'   => array( 'b1' => array( 'correct' => 'zemlja' ) ),
				),
			),
			$fib['id']
		);
		$this->assertSame( 'Erde', $review[0]['answers']['b1']['correct'], 'The review shows the right answer in German' );
		$this->assertStringStartsWith( 'Gießen, wenn  {{FIB_b1}}', $review[0]['title_api'] );
		$this->assertTrue( $grade( 'b1', ' erde ' ), 'Compared as the plugin compares: trimmed, any case' );
		$this->assertTrue( $grade( 'b1', 'zemlja', true ), 'The original stays right' );
		$this->assertFalse( $grade( 'b1', 'Sand' ) );
		$this->assertTrue( $grade( 'b2', 'hand' ), 'One of a list of right answers' );
		$this->assertFalse( $grade( 'b3', 'drei' ), 'A range of numbers is graded as stored' );

		$this->translate( $owner, $text['p'], $text['v'], 'de', 'Gießen, wenn {{FIB_b1}} trocken ist.' );
		$this->reset_state();
		$this->show_in( 'de' );
		$lp = new FakeLearnPress();
		$this->assertSame( $stored, $lp->translateSentence( $stored, $answer ), 'A sentence that lost blanks shows the original' );

		$this->reset_state();
		$this->assertFalse( $grade( 'b1', 'Erde' ), 'In the original the answer in German is wrong' );
	}

	/**
	 * The plugin builds the address of a lesson from its course's address and the lesson's own slug:
	 * that slug is the one of the language.
	 */
	public function test_item_address_has_its_slug_in_the_language() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$lesson = self::factory()->post->create(
			array(
				'post_type' => 'lp_lesson',
				'post_name' => 'sjetva-bosiljka',
			)
		);
		swiftling_save_slug( 'post:' . $lesson, 'de_DE', 'basilikum-saeen' );
		$site = untrailingslashit( get_option( 'home' ) );

		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		$this->assertSame( $site . '/de/courses/kraeuter/lessons/basilikum-saeen/', self::lp( 'learn-press/course/item-link', $site . '/de/courses/kraeuter/lessons/sjetva-bosiljka/', $lesson ) );

		$this->reset_state();
		swiftling_keep_folder( '' );
		$this->assertSame( $site . '/courses/zacinsko-bilje/lessons/sjetva-bosiljka/', self::lp( 'learn-press/course/item-link', $site . '/courses/zacinsko-bilje/lessons/sjetva-bosiljka/', $lesson ) );
	}

	/**
	 * The quiz, the enrolment and the curriculum ask the plugin's REST API from a page: in the
	 * language of that page. Its routes for editing a course stay in the original.
	 */
	public function test_routes_from_a_course_page_in_its_language() {
		foreach ( array( '/lp/v1/users/start-quiz', '/lp/v1/users/submit-quiz', '/lp/v1/users/check-answer', '/lp/v1/courses/enroll-course', '/lp/v1/lazy-load/course-curriculum', '/lp/v1/courses/archive-course' ) as $route ) {
			$this->assertTrue( Registry::sendsFormTo( $route ), $route );
		}
		$this->assertFalse( Registry::sendsFormTo( '/lp/v1/courses/edit-archive-block' ) );
	}

	/**
	 * A course, its lessons, quizzes and questions get no separate copy.
	 */
	public function test_courses_get_no_separate_copy() {
		foreach ( array( 'lp_course', 'lp_lesson', 'lp_quiz', 'lp_question' ) as $type ) {
			$this->assertFalse( swiftling_can_have_copies( get_post( self::factory()->post->create( array( 'post_type' => $type ) ) ) ), $type );
		}
		$this->assertTrue( swiftling_can_have_copies( get_post( self::factory()->post->create() ) ) );
	}

	/**
	 * Machine translation leaves the blanks of a sentence as they are.
	 */
	public function test_machine_translation_keeps_blanks() {
		$items = apply_filters(
			'swiftling_machine_items',
			array(
				'a' => array(
					'text' => 'Zalijte kad je {{FIB_b1}} suha pod {{FIB_b2}}.',
					'html' => false,
				),
				'b' => array(
					'text' => 'Bez praznina.',
					'html' => false,
				),
			),
			null,
			array()
		);
		$this->assertSame( array( '{{FIB_b1}}', '{{FIB_b2}}' ), $items['a']['keep'] );
		$this->assertArrayNotHasKey( 'keep', $items['b'] );
	}
}
