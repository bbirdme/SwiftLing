<?php
/**
 * Tests of the Tutor LMS class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\Registry;
use SwiftLing\Builders\TutorLms;
use WP_Query;

require_once __DIR__ . '/stubs/FakeTutorLms.php';

/**
 * What SwiftLing translates of a course of Tutor LMS beyond its posts: the titles and texts the
 * learning area prints straight from the posts, the topics and announcements, the questions and
 * answers of a quiz, kept in the plugin's tables, and what the student types in a quiz.
 */
class TutorLmsTest extends TestCase {

	/**
	 * The class, with the plugin's post types and tables.
	 *
	 * @var FakeTutorLms
	 */
	private $tutor;

	/**
	 * Puts the fake in the registry and hooks it, as on a site with the plugin.
	 */
	public function set_up() {
		parent::set_up();
		FakeTutorLms::install();
		$this->tutor = new FakeTutorLms();
		$builders    = array();
		foreach ( Registry::all() as $id => $builder ) {
			$builders[ $id ] = $builder instanceof TutorLms ? $this->tutor : $builder;
		}
		self::set_static( Registry::class, 'builders', $builders );
		self::reset_static( Registry::class, 'active' );
		$this->tutor->register();
	}

	/**
	 * Gives the registry back its own builders, which the fake replaced.
	 */
	public function tear_down() {
		unset( $GLOBALS['tutor_course'], $GLOBALS['tutor_current_post'] );
		self::reset_static( Registry::class, 'builders' );
		self::reset_static( Registry::class, 'active' );
		parent::tear_down();
	}

	/**
	 * Makes a course with a topic, a lesson, a quiz and an announcement, as the plugin keeps them: a
	 * topic's parent is the course, a lesson's and a quiz's the topic.
	 *
	 * @return array<string, int> Post IDs.
	 */
	private function course() {
		$ids                 = array();
		$ids['course']       = self::factory()->post->create(
			array(
				'post_type'  => 'courses',
				'post_title' => 'Voćnjak',
				'post_name'  => 'vocnjak',
			)
		);
		$ids['topic']        = self::factory()->post->create(
			array(
				'post_type'    => 'topics',
				'post_title'   => 'Odabir stabala',
				'post_content' => 'Koje sorte rastu u vašem kraju.',
				'post_parent'  => $ids['course'],
			)
		);
		$ids['lesson']       = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_title'  => 'Sorte jabuka',
				'post_name'   => 'sorte-jabuka',
				'post_parent' => $ids['topic'],
			)
		);
		$ids['quiz']         = self::factory()->post->create(
			array(
				'post_type'    => 'tutor_quiz',
				'post_title'   => 'Kviz o voćkama',
				'post_content' => 'Deset pitanja o voćnjaku.',
				'post_parent'  => $ids['topic'],
			)
		);
		$ids['announcement'] = self::factory()->post->create(
			array(
				'post_type'    => 'tutor_announcements',
				'post_title'   => 'Novi video',
				'post_content' => 'Pogledajte zimsku rezidbu.',
				'post_parent'  => $ids['course'],
			)
		);
		return $ids;
	}

	/**
	 * Makes a question of a quiz with its answers, as rows of the plugin's tables.
	 *
	 * @param int    $quiz    Quiz ID.
	 * @param string $type    Type of the question.
	 * @param string $title   The question.
	 * @param array  $answers Answers: title, and match for a matching pair or the blanks.
	 * @param array  $more    Other fields of the question, such as its explanation.
	 * @return array{id: int, answers: int[]}
	 */
	private function question( $quiz, $type, $title, $answers = array(), $more = array() ) {
		global $wpdb;
		static $order = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's tables, filled for a test.
		$wpdb->insert(
			$wpdb->prefix . 'tutor_quiz_questions',
			array_merge(
				array(
					'quiz_id'        => $quiz,
					'question_title' => $title,
					'question_type'  => $type,
					'question_mark'  => 1,
					'question_order' => ++$order,
				),
				$more
			)
		);
		$id  = (int) $wpdb->insert_id;
		$ids = array();
		foreach ( $answers as $index => $answer ) {
			$wpdb->insert(
				$wpdb->prefix . 'tutor_quiz_question_answers',
				array(
					'belongs_question_id'   => $id,
					'belongs_question_type' => $type,
					'answer_title'          => isset( $answer['title'] ) ? $answer['title'] : '',
					'answer_two_gap_match'  => isset( $answer['match'] ) ? $answer['match'] : '',
					'is_correct'            => empty( $answer['correct'] ) ? 0 : 1,
					'answer_order'          => $index + 1,
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		// phpcs:enable
		return array(
			'id'      => $id,
			'answers' => $ids,
		);
	}

	/**
	 * Returns a question as the plugin hands it to the template of a quiz: its row with its answers.
	 *
	 * @param int $id Question ID.
	 * @return array
	 */
	private function as_template_gets( $id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the plugin's tables as it does.
		$question                     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE question_id = %d', $wpdb->prefix . 'tutor_quiz_questions', $id ), ARRAY_A );
		$question['question_answers'] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE belongs_question_id = %d ORDER BY answer_order', $wpdb->prefix . 'tutor_quiz_question_answers', $id ), ARRAY_A );
		// phpcs:enable
		return $question;
	}

	/**
	 * Returns a text the editor offers with a post's fields, see swiftling_unshown_field_texts().
	 *
	 * @param array  $result What check() returned.
	 * @param string $needle Words in the value.
	 * @return array The text.
	 */
	private function offered( $result, $needle ) {
		foreach ( $result['sections']['fields'] as $unit ) {
			if ( false !== strpos( $unit['v'], $needle ) ) {
				return $unit;
			}
		}
		$this->fail( 'The editor offers no text with "' . $needle . '".' );
		return array();
	}

	/**
	 * Does what the learning area does: keeps the course and the post shown in its globals, read with
	 * get_post(), and hands its templates their variables.
	 *
	 * @param int   $course    Course ID.
	 * @param int   $current   Post shown.
	 * @param array $variables Variables of the template.
	 * @return array The variables as the template gets them.
	 */
	private function learning_area( $course, $current, $variables = array() ) {
		$GLOBALS['tutor_course']       = get_post( $course ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The plugin's own globals, as the learning area sets them.
		$GLOBALS['tutor_current_post'] = get_post( $current ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The plugin's own globals, as the learning area sets them.
		return apply_filters( 'get_tutor_load_template_variables', $variables ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
	}

	/**
	 * The learning area prints the titles of the course and of what is shown straight from the posts,
	 * and its sidebar the titles of the lessons a query returns, past the_title: on a page in a
	 * language they are translated, the description of a quiz too. The posts WordPress keeps stay as
	 * stored, and a page in the original shows the original.
	 */
	public function test_learning_area_shows_titles_in_the_language() {
		$ids    = $this->course();
		$course = $this->check( 'post:' . $ids['course'], '__return_null' );
		$this->check( 'post:' . $ids['lesson'], '__return_null' );
		$quiz        = $this->check(
			'post:' . $ids['quiz'],
			function () use ( $ids ) {
				$this->learning_area( $ids['course'], $ids['quiz'] );
			}
		);
		$description = $this->found( $quiz, 'Deset pitanja' );
		$this->translate( 'post:' . $ids['course'], 'field/post_title', 'Voćnjak', 'de', 'Obstgarten' );
		$this->translate( 'post:' . $ids['lesson'], 'field/post_title', 'Sorte jabuka', 'de', 'Apfelsorten' );
		$this->translate( 'post:' . $ids['quiz'], 'field/post_title', 'Kviz o voćkama', 'de', 'Obstbaum-Quiz' );
		$this->translate( 'post:' . $ids['quiz'], $description['p'], 'Deset pitanja o voćnjaku.', 'de', 'Zehn Fragen zum Obstgarten.' );
		$topic_title = $this->offered( $course, 'Odabir stabala' );
		$this->translate( $topic_title['o'], $topic_title['p'], 'Odabir stabala', 'de', 'Bäume wählen' );
		$summary = $this->offered( $course, 'Koje sorte' );
		$this->translate( $summary['o'], $summary['p'], 'Koje sorte rastu u vašem kraju.', 'de', 'Welche Sorten bei Ihnen wachsen.' );

		$this->show_in( 'de' );
		$this->learning_area( $ids['course'], $ids['quiz'] );
		$this->assertSame( 'Obstgarten', $GLOBALS['tutor_course']->post_title, 'The header and the sidebar name the course' );
		$this->assertSame( 'Obstbaum-Quiz', $GLOBALS['tutor_current_post']->post_title );
		$this->assertSame( 'Zehn Fragen zum Obstgarten.', $GLOBALS['tutor_current_post']->post_content, 'The quiz prints its description from the post' );

		$lessons = new WP_Query(
			array(
				'post_type'   => 'lesson',
				'post_parent' => $ids['topic'],
			)
		);
		$this->assertSame( 'Apfelsorten', $lessons->posts[0]->post_title, 'The sidebar lists the lessons a query returns' );
		$topics = new WP_Query(
			array(
				'post_type'   => 'topics',
				'post_parent' => $ids['course'],
			)
		);
		$this->assertSame( 'Bäume wählen', $topics->posts[0]->post_title );
		$this->assertSame( 'Welche Sorten bei Ihnen wachsen.', $topics->posts[0]->post_content, 'The summary of a topic, printed with get_the_content()' );
		$this->assertSame( 'Voćnjak', get_post( $ids['course'] )->post_title, 'The post WordPress keeps stays as stored' );

		$this->reset_state();
		$this->learning_area( $ids['course'], $ids['quiz'] );
		$this->assertSame( 'Voćnjak', $GLOBALS['tutor_course']->post_title, 'The original shows the original' );
		$lessons = new WP_Query(
			array(
				'post_type'   => 'lesson',
				'post_parent' => $ids['topic'],
			)
		);
		$this->assertSame( 'Sorte jabuka', $lessons->posts[0]->post_title );
	}

	/**
	 * A topic and an announcement are posts no visitor opens: the editor of their course offers their
	 * texts, named by what they are.
	 */
	public function test_course_editor_offers_topics_and_announcements() {
		$ids    = $this->course();
		$result = $this->check( 'post:' . $ids['course'], '__return_null' );

		$offered = array();
		foreach ( $result['sections']['fields'] as $unit ) {
			$offered[ $unit['o'] . '|' . $unit['p'] ] = $unit['place'];
		}
		$this->assertSame( 'Topic: Odabir stabala › Title', $offered[ 'post:' . $ids['topic'] . '|field/post_title' ] );
		$this->assertContains( 'Topic: Odabir stabala › Summary', $offered );
		$this->assertSame( 'Announcement: Novi video › Title', $offered[ 'post:' . $ids['announcement'] . '|field/post_title' ] );
		$this->assertContains( 'Announcement: Novi video › Text', $offered );
		$this->assertArrayNotHasKey( 'post:' . $ids['lesson'] . '|field/post_title', $offered, 'A lesson has an editor of its own' );
	}

	/**
	 * The questions of a quiz and their answers are rows of the plugin's tables: the editor of the
	 * quiz offers them, and a quiz in a language shows them translated where the plugin hands a
	 * question to its template. A sentence with blanks keeps the original when its translation has
	 * another number of blanks, and a blank whose translation holds "|" keeps its original.
	 */
	public function test_questions_of_a_quiz_offered_and_translated() {
		$ids    = $this->course();
		$choice = $this->question(
			$ids['quiz'],
			'single_choice',
			'Kada se reže jabuka?',
			array(
				array(
					'title'   => 'Kasno zimi',
					'correct' => true,
				),
				array( 'title' => 'Usred ljeta' ),
			),
			array( 'answer_explanation' => 'Zimska rezidba oblikuje krošnju.' )
		);
		$blanks = $this->question(
			$ids['quiz'],
			'fill_in_the_blank',
			'Dovršite rečenicu.',
			array(
				array(
					'title' => 'Pčele nose {dash} među {dash}.',
					'match' => 'pelud|cvjetovima',
				),
			)
		);
		$pairs  = $this->question(
			$ids['quiz'],
			'matching',
			'Spojite stablo i plod.',
			array(
				array(
					'title' => 'Malus',
					'match' => 'daje jabuke',
				),
			)
		);

		$result  = $this->check( 'post:' . $ids['quiz'], '__return_null' );
		$offered = array();
		foreach ( $result['sections']['fields'] as $unit ) {
			$offered[ $unit['p'] ] = $unit;
		}
		$this->assertSame( 'Question 1 › Question', $offered[ TutorLms::questionPath( $choice['id'], 'title' ) ]['place'] );
		$this->assertSame( 'Question 1 › Answer explanation', $offered[ TutorLms::questionPath( $choice['id'], 'explanation' ) ]['place'] );
		$this->assertSame( 'Question 1 › Answer 2', $offered[ TutorLms::answerPath( $choice['answers'][1], 'title' ) ]['place'] );
		$this->assertSame( 'Question 2 › Sentence, with {dash} for each blank', $offered[ TutorLms::answerPath( $blanks['answers'][0], 'title' ) ]['place'] );
		$this->assertSame( 'Question 2 › Right answer of blank 2', $offered[ TutorLms::answerPath( $blanks['answers'][0], 'blank/2' ) ]['place'] );
		$this->assertSame( 'cvjetovima', $offered[ TutorLms::answerPath( $blanks['answers'][0], 'blank/2' ) ]['v'] );
		$this->assertSame( 'Question 3 › Match of answer 1', $offered[ TutorLms::answerPath( $pairs['answers'][0], 'match' ) ]['place'] );
		$this->assertSame( 'post:' . $ids['quiz'], $offered[ TutorLms::answerPath( $pairs['answers'][0], 'match' ) ]['o'], 'Every text belongs to the quiz' );

		$quiz = 'post:' . $ids['quiz'];
		$this->translate( $quiz, TutorLms::questionPath( $choice['id'], 'title' ), 'Kada se reže jabuka?', 'de', 'Wann schneidet man Äpfel?' );
		$this->translate( $quiz, TutorLms::answerPath( $choice['answers'][0], 'title' ), 'Kasno zimi', 'de', 'Im Spätwinter' );
		$this->translate( $quiz, TutorLms::questionPath( $choice['id'], 'explanation' ), 'Zimska rezidba oblikuje krošnju.', 'de', 'Der Winterschnitt formt die Krone.' );
		$this->translate( $quiz, TutorLms::answerPath( $blanks['answers'][0], 'title' ), 'Pčele nose {dash} među {dash}.', 'de', 'Bienen tragen {dash} zwischen Blüten.' );
		$this->translate( $quiz, TutorLms::answerPath( $blanks['answers'][0], 'blank/1' ), 'pelud', 'de', 'Pollen' );
		$this->translate( $quiz, TutorLms::answerPath( $blanks['answers'][0], 'blank/2' ), 'cvjetovima', 'de', 'Blüten|Blumen' );
		$this->translate( $quiz, TutorLms::answerPath( $pairs['answers'][0], 'match' ), 'daje jabuke', 'de', 'gibt Äpfel' );

		$this->show_in( 'de' );
		$variables = $this->learning_area(
			$ids['course'],
			$ids['quiz'],
			array(
				'question' => $this->as_template_gets( $choice['id'] ),
				'other'    => (object) $this->as_template_gets( $blanks['id'] ),
				'pairs'    => array( (object) $this->as_template_gets( $pairs['id'] ) ),
			)
		);
		$this->assertSame( 'Wann schneidet man Äpfel?', $variables['question']['question_title'] );
		$this->assertSame( 'Der Winterschnitt formt die Krone.', $variables['question']['answer_explanation'] );
		$this->assertSame( 'Im Spätwinter', $variables['question']['question_answers'][0]['answer_title'] );
		$this->assertSame( 'Usred ljeta', $variables['question']['question_answers'][1]['answer_title'], 'An answer without a translation stays' );
		$this->assertSame( 'Pčele nose {dash} među {dash}.', $variables['other']->question_answers[0]['answer_title'], 'A sentence with one blank less would ask for one answer less' );
		$this->assertSame( 'Pollen|cvjetovima', $variables['other']->question_answers[0]['answer_two_gap_match'], 'A blank holding "|" would be two' );
		$this->assertSame( 'gibt Äpfel', $variables['pairs'][0]->question_answers[0]['answer_two_gap_match'], 'A list of questions, as the review of an attempt gets it' );

		$this->reset_state();
		$variables = $this->learning_area( $ids['course'], $ids['quiz'], array( 'question' => $this->as_template_gets( $choice['id'] ) ) );
		$this->assertSame( 'Kada se reže jabuka?', $variables['question']['question_title'], 'The original shows the original' );
	}

	/**
	 * Fill in the blank and image answering grade what the student types. In a quiz in a language
	 * the answer in that language is right, the original stays right, and a wrong answer stays
	 * wrong. The marks gained count once in the attempt's total. In the original the answer in the
	 * language is wrong, as the plugin grades it.
	 */
	public function test_typed_answers_are_right_in_the_language() {
		$ids    = $this->course();
		$blanks = $this->question(
			$ids['quiz'],
			'fill_in_the_blank',
			'Dovršite rečenicu.',
			array(
				array(
					'title' => 'Pčele nose {dash} među {dash}.',
					'match' => 'pelud|cvjetovima',
				),
			)
		);
		$image  = $this->question( $ids['quiz'], 'image_answering', 'Koje je ovo voće?', array( array( 'title' => 'jabuka' ) ) );
		$this->check( 'post:' . $ids['quiz'], '__return_null' );
		$quiz = 'post:' . $ids['quiz'];
		$this->translate( $quiz, TutorLms::answerPath( $blanks['answers'][0], 'blank/1' ), 'pelud', 'de', 'Pollen' );
		$this->translate( $quiz, TutorLms::answerPath( $blanks['answers'][0], 'blank/2' ), 'cvjetovima', 'de', 'Blüten' );
		$this->translate( $quiz, TutorLms::answerPath( $image['answers'][0], 'title' ), 'jabuka', 'de', 'Apfel' );

		$grade = function ( $question, $type, $given, $correct = 0 ) {
			return apply_filters(
				'tutor_filter_quiz_answer_data', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
				array(
					'question_id'   => $question,
					'given_answer'  => maybe_serialize( $given ),
					'question_mark' => '2.00',
					'achieved_mark' => $correct ? '2.00' : 0,
					'is_correct'    => $correct,
				),
				$question,
				$type,
				1,
				77
			);
		};

		$this->show_in( 'de' );
		$right = $grade( $blanks['id'], 'fill_in_the_blank', array( 'pollen', ' BLÜTEN ' ) );
		$this->assertSame( 1, $right['is_correct'], 'Each blank as the plugin compares it: trimmed, any case' );
		$this->assertSame( 2.0, $right['achieved_mark'] );
		$this->assertSame( 5.0, apply_filters( 'tutor_filter_quiz_total_marks', 3.0, $blanks['id'], 'fill_in_the_blank', 1, 77, $right ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
		$this->assertSame( 3.0, apply_filters( 'tutor_filter_quiz_total_marks', 3.0, $blanks['id'], 'fill_in_the_blank', 1, 77, $right ), 'Counted once' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
		$this->assertSame( 0, $grade( $blanks['id'], 'fill_in_the_blank', array( 'Pollen', 'Bäume' ) )['is_correct'], 'A wrong blank stays wrong' );
		$this->assertSame( 0, $grade( $blanks['id'], 'fill_in_the_blank', array( 'Pollen' ) )['is_correct'], 'Every blank is answered' );
		$this->assertSame( 1, $grade( $blanks['id'], 'fill_in_the_blank', array( 'pelud', 'cvjetovima' ), 1 )['is_correct'], 'The original stays right' );

		$this->assertSame( 1, $grade( $image['id'], 'image_answering', array( $image['answers'][0] => 'apfel' ) )['is_correct'] );
		$this->assertSame( 1, $grade( $image['id'], 'image_answering', array( $image['answers'][0] => 'Jabuka' ) )['is_correct'], 'The original name too' );
		$this->assertSame( 0, $grade( $image['id'], 'image_answering', array( $image['answers'][0] => 'Birne' ) )['is_correct'] );
		$this->assertSame( 0, $grade( $blanks['id'], 'single_choice', array( 'Pollen', 'Blüten' ) )['is_correct'], 'Other types are graded by IDs' );

		$this->reset_state();
		$this->assertSame( 0, $grade( $blanks['id'], 'fill_in_the_blank', array( 'Pollen', 'Blüten' ) )['is_correct'], 'In the original the answer in German is wrong' );
	}

	/**
	 * A plugin that saves a post it was handed on a page in a language would store its translation as
	 * the original: the texts a display copy shows are put back before it is saved.
	 */
	public function test_display_copy_is_never_saved() {
		$ids = $this->course();
		$this->check( 'post:' . $ids['lesson'], '__return_null' );
		$this->translate( 'post:' . $ids['lesson'], 'field/post_title', 'Sorte jabuka', 'de', 'Apfelsorten' );

		$this->show_in( 'de' );
		$lessons = new WP_Query(
			array(
				'post_type'   => 'lesson',
				'post_parent' => $ids['topic'],
			)
		);
		$copy    = $lessons->posts[0];
		$this->assertSame( 'Apfelsorten', $copy->post_title );
		wp_update_post( $copy );
		$this->assertSame( 'Sorte jabuka', get_post_field( 'post_title', $ids['lesson'], 'raw' ) );

		wp_update_post(
			array(
				'ID'         => $ids['lesson'],
				'post_title' => 'Sorte jabuka i krušaka',
			)
		);
		$this->assertSame( 'Sorte jabuka i krušaka', get_post_field( 'post_title', $ids['lesson'], 'raw' ), 'A new title someone writes is saved' );
	}

	/**
	 * The review of an attempt reads the answers of each question inside its template: what it prints
	 * shows their texts in the language, only where a text is whole, and a blank the student typed
	 * in the language shows as right, as it was graded.
	 */
	public function test_review_of_an_attempt_in_the_language() {
		$ids    = $this->course();
		$choice = $this->question( $ids['quiz'], 'multiple_choice', 'Što je koštičavo?', array( array( 'title' => 'Šljiva' ), array( 'title' => 'Dunja' ) ) );
		$blanks = $this->question(
			$ids['quiz'],
			'fill_in_the_blank',
			'Dovršite rečenicu.',
			array(
				array(
					'title' => 'Pčele nose {dash} cvijeću.',
					'match' => 'pelud',
				),
			)
		);
		$this->check( 'post:' . $ids['quiz'], '__return_null' );
		$quiz = 'post:' . $ids['quiz'];
		$this->translate( $quiz, TutorLms::answerPath( $choice['answers'][0], 'title' ), 'Šljiva', 'de', 'Pflaume' );
		$this->translate( $quiz, TutorLms::answerPath( $choice['answers'][1], 'title' ), 'Dunja', 'de', 'Quitte' );
		$this->translate( $quiz, TutorLms::answerPath( $blanks['answers'][0], 'title' ), 'Pčele nose {dash} cvijeću.', 'de', 'Bienen tragen {dash} zu den Blumen.' );
		$this->translate( $quiz, TutorLms::answerPath( $blanks['answers'][0], 'blank/1' ), 'pelud', 'de', 'Pollen' );

		$review  = function ( $question, $type, $html ) use ( $ids ) {
			$template  = 'shared.components.quiz.attempt-details.questions.' . $type;
			$variables = array(
				'question' => (object) array(
					'question_id' => $question,
					'quiz_id'     => $ids['quiz'],
				),
			);
			ob_start();
			do_action( 'tutor_load_template_before', $template, $variables ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- What the plugin's template prints.
			do_action( 'tutor_load_template_after', $template, $variables ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
			return ob_get_clean();
		};
		$choices = '<label><span>Šljiva</span><img alt="Dunja" src="x.png"></label><p>Šljivarica</p>';
		$printed = '<p>Pčele nose <span class="tutor-quiz-question-input" data-option="incorrect">pollen</span> cvijeću.</p>'
			. '<p>Pčele nose <span class="tutor-quiz-question-input" data-option="correct">pelud</span> cvijeću.</p>';

		$this->show_in( 'de' );
		$this->assertSame( '<label><span>Pflaume</span><img alt="Quitte" src="x.png"></label><p>Šljivarica</p>', $review( $choice['id'], 'multiple-choice', $choices ), 'Only a whole text changes' );
		$this->assertSame(
			'<p>Bienen tragen <span class="tutor-quiz-question-input" data-option="correct">pollen</span> zu den Blumen.</p>'
			. '<p>Bienen tragen <span class="tutor-quiz-question-input" data-option="correct">Pollen</span> zu den Blumen.</p>',
			$review( $blanks['id'], 'fill-in-the-blank', $printed ),
			'The sentence and the right answer in German; the answer typed in German shows as right'
		);

		$this->reset_state();
		$this->assertSame( $choices, $review( $choice['id'], 'multiple-choice', $choices ), 'The original prints as the plugin printed it' );
	}

	/**
	 * The cart and the checkout of the plugin's shop read the courses in them from its tables: what
	 * they print names the courses in the cart, and of an order paid for later, in the language. A
	 * course in another student's cart is not looked at, and the original prints as stored.
	 */
	public function test_cart_and_checkout_name_courses_in_the_language() {
		global $wpdb;
		$student = $this->become( 'subscriber' );
		$other   = self::factory()->user->create();
		$ids     = $this->course();
		$second  = self::factory()->post->create(
			array(
				'post_type'  => 'courses',
				'post_title' => 'Rakija',
			)
		);
		$ordered = self::factory()->post->create(
			array(
				'post_type'  => 'courses',
				'post_title' => 'Ocat',
			)
		);
		foreach ( array( $ids['course'], $second, $ordered ) as $course ) {
			$this->check( 'post:' . $course, '__return_null' );
		}
		$this->translate( 'post:' . $ids['course'], 'field/post_title', 'Voćnjak', 'de', 'Obstgarten' );
		$this->translate( 'post:' . $second, 'field/post_title', 'Rakija', 'de', 'Obstbrand' );
		$this->translate( 'post:' . $ordered, 'field/post_title', 'Ocat', 'de', 'Essig' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's tables, filled for a test.
		$wpdb->insert( $wpdb->prefix . 'tutor_carts', array( 'user_id' => $student ) );
		$wpdb->insert(
			$wpdb->prefix . 'tutor_cart_items',
			array(
				'cart_id'   => $wpdb->insert_id,
				'course_id' => $ids['course'],
			)
		);
		$wpdb->insert( $wpdb->prefix . 'tutor_carts', array( 'user_id' => $other ) );
		$wpdb->insert(
			$wpdb->prefix . 'tutor_cart_items',
			array(
				'cart_id'   => $wpdb->insert_id,
				'course_id' => $second,
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'tutor_order_items',
			array(
				'order_id' => 41,
				'item_id'  => $ordered,
			)
		);
		// phpcs:enable

		$shop = function ( $template ) {
			ob_start();
			do_action( 'tutor_load_template_before', $template, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
			echo '<a href="#">Voćnjak</a><img alt="Voćnjak" src="x.png"><a href="#">Rakija</a><a href="#">Ocat</a>';
			do_action( 'tutor_load_template_after', $template, array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own hook, as it runs it.
			return ob_get_clean();
		};

		$this->show_in( 'de' );
		$this->assertSame( '<a href="#">Obstgarten</a><img alt="Obstgarten" src="x.png"><a href="#">Rakija</a><a href="#">Ocat</a>', $shop( 'ecommerce.cart' ) );
		$_REQUEST['order_id'] = '41';
		$this->assertSame( '<a href="#">Obstgarten</a><img alt="Obstgarten" src="x.png"><a href="#">Rakija</a><a href="#">Essig</a>', $shop( 'ecommerce/checkout-details' ), 'The checkout\'s Ajax names the template with a slash' );
		$this->assertSame( '<a href="#">Voćnjak</a><img alt="Voćnjak" src="x.png"><a href="#">Rakija</a><a href="#">Ocat</a>', $shop( 'ecommerce.billing' ), 'Another template prints as it is' );

		$this->reset_state();
		$this->assertSame( '<a href="#">Voćnjak</a><img alt="Voćnjak" src="x.png"><a href="#">Rakija</a><a href="#">Ocat</a>', $shop( 'ecommerce.cart' ), 'The original prints as stored' );
	}

	/**
	 * A course, a lesson and a quiz get no separate copy: a copy would have no topics, lessons or
	 * students. Other posts still do.
	 */
	public function test_courses_get_no_separate_copy() {
		$ids  = $this->course();
		$post = self::factory()->post->create();
		$this->assertFalse( swiftling_can_have_copies( get_post( $ids['course'] ) ) );
		$this->assertFalse( swiftling_can_have_copies( get_post( $ids['lesson'] ) ) );
		$this->assertFalse( swiftling_can_have_copies( get_post( $ids['quiz'] ) ) );
		$this->assertTrue( swiftling_can_have_copies( get_post( $post ) ) );
	}

	/**
	 * The plugin names the course in the address of a lesson: the course's slug there is the one of
	 * the language, as on the course's own address.
	 */
	public function test_lesson_address_names_the_course_in_the_language() {
		$this->use_pretty_permalinks();
		$this->set_languages( array( 'de_DE' => 'de' ), 'directory' );
		$ids = $this->course();
		swiftling_save_slug( 'post:' . $ids['course'], 'de_DE', 'obstgarten' );
		$site = untrailingslashit( get_option( 'home' ) );
		$link = $site . '/de/courses/vocnjak/lessons/sorte-jabuka/';

		$this->show_in( 'de' );
		swiftling_keep_folder( 'de' );
		$this->assertSame( $site . '/de/courses/obstgarten/lessons/sorte-jabuka/', $this->tutor->translateCourseSlug( $link, $ids['lesson'] ) );
		$this->assertSame( $site . '/de/courses/vocnjak/', $this->tutor->translateCourseSlug( $site . '/de/courses/vocnjak/', $ids['course'] ), 'The course\'s own address is SwiftLing\'s' );

		$this->reset_state();
		swiftling_keep_folder( '' );
		$original = $site . '/courses/vocnjak/lessons/sorte-jabuka/';
		$this->assertSame( $original, $this->tutor->translateCourseSlug( $original, $ids['lesson'] ) );
	}

	/**
	 * The requests a student's browser sends from a page of a course are answered in the language of
	 * that page; those of the plugin's course builder stay in the original, where courses are edited.
	 */
	public function test_requests_from_a_course_page_in_its_language() {
		foreach ( array( 'tutor_quiz_abandon', 'tutor_quiz_timeout', 'tutor_quiz_check_answer', 'tutor_course_filter_ajax', 'tutor_course_enrollment', 'tutor_add_course_to_cart', 'tutor_delete_course_from_cart', 'tutor_apply_coupon', 'tutor_get_checkout_html' ) as $action ) {
			$this->assertTrue( Registry::sendsForm( $action ), $action );
		}
		$this->assertFalse( Registry::sendsForm( 'tutor_quiz_builder_save' ) );
	}

	/**
	 * Machine translation leaves the {dash} of a sentence with blanks as it is, so the sentence keeps
	 * its blanks.
	 */
	public function test_machine_translation_keeps_blanks() {
		$items = apply_filters(
			'swiftling_machine_items',
			array(
				'a' => array(
					'text' => 'Pčele nose {dash} cvijeću.',
					'html' => false,
					'keep' => array( 'Pčele' ),
				),
				'b' => array(
					'text' => 'Bez praznina.',
					'html' => false,
				),
			),
			null,
			array()
		);
		$this->assertSame( array( 'Pčele', '{dash}' ), $items['a']['keep'] );
		$this->assertArrayNotHasKey( 'keep', $items['b'] );
	}
}
