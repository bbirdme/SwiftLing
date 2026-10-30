<?php
/**
 * Tutor LMS: what of a course SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * Tutor LMS. A course, a lesson and a quiz are posts visitors open, and their content, excerpt and
 * the course's lists (what students learn, the requirements, the audience, the materials) are
 * content and meta SwiftLing finds and translates as on any post. What it does not find by itself:
 *
 * - The learning area, where a student takes a course, prints the titles of the course, its topics,
 *   lessons and quizzes, the summary of a topic, the description of a quiz and the announcements
 *   straight from the posts, past the_title and the_content. Where the plugin's posts reach a page,
 *   through a query (the_posts) or the posts it keeps for its templates (see filterTemplateVariables()),
 *   they reach it as copies with those texts in the language of the page, see displayCopy(). The
 *   posts themselves stay as stored: a copy is never saved, see keepStoredTexts().
 * - A topic and an announcement are posts of types visitors never open, so they have no editor of
 *   their own: the editor of the course offers their texts, see listTexts().
 * - The questions of a quiz and their answers are rows of the plugin's own tables. They are texts
 *   of the quiz, "tutor/question/<ID>/title" and "tutor/answer/<ID>/title" in its map, offered in
 *   its editor and translated where the plugin hands a question to its templates.
 * - A blank of a fill in the blank question is a text of its own, and the sentence keeps a {dash}
 *   for each blank: a translation with another number of them shows the original, and machine
 *   translation leaves {dash} as it is, see keepBlanks().
 * - Fill in the blank and image answering grade what the student types. In a language the answer
 *   in that language is right, as the original is, see gradeTypedAnswer(); every other type is
 *   graded by the IDs of its answers, the same in every language.
 * - The review of an attempt reads the answers of each question from the plugin's tables inside its
 *   template, and the cart and the checkout of the plugin's own shop the courses in them: their
 *   texts are translated in what those templates print, see startTemplate().
 * - The plugin starts and submits a quiz by sending its form to the page itself, which is in its
 *   language already. What a student's browser sends to admin-ajax.php from a page, to give up a
 *   quiz or end it when its time is up, check an answer, filter the courses, enroll, and fill the
 *   cart or the checkout, is answered in the language of that page, see sendingActions(); the
 *   address of the cart it answers with too.
 * - The plugin names the course in the address of a lesson or a quiz: its slug there is the one of
 *   the language, see translateCourseSlug().
 * - A course, its lessons and quizzes get no separate copy: a copy would have no topics, no lessons
 *   and no students. They are translated in place, see Builder::noCopyTypes().
 *
 * Checked against the code of Tutor LMS 4.2.0 (the free plugin), in tests/TutorLmsTest.php with
 * posts and rows of the same shape, and by hand on a site with the plugin. Assignments and
 * certificates are Tutor Pro and were not checked; nor were the emails of the plugin's shop.
 */
class TutorLms extends Builder {
	/**
	 * Where the texts of a question and its answers are in the map of their quiz.
	 */
	const BASE = 'tutor';

	/**
	 * Source of a text of a question or an answer, for its rule key.
	 */
	const SOURCE = 'tutor:quiz';

	/**
	 * The texts a display copy changed, by post ID: field => the text as stored and as shown.
	 *
	 * @var array<int, array<string, array{0: string, 1: string}>>
	 */
	private $shown = array();

	/**
	 * Marks an answer typed in the language gained, by attempt and question, until the plugin adds
	 * them to the attempt's total, see addGainedMarks().
	 *
	 * @var array<string, float>
	 */
	private $gained = array();

	/**
	 * Buffers of templates open now, see startTemplate(): each its template and its level.
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private $buffers = array();

	/**
	 * Quiz of each question read in this request, by question ID.
	 *
	 * @var array<int, int>
	 */
	private $quizzes = array();

	/**
	 * Rows of the plugin's tables read in this request: questions by ID, the questions of each quiz
	 * and the answers of each question. The editor names every text of a quiz by the place of its
	 * question and answer, so a quiz is read once, not once for each of its texts.
	 *
	 * @var array{question: array<int, array|null>, questions: array<int, array>, answers: array<int, array>}
	 */
	private $rows = array(
		'question'  => array(),
		'questions' => array(),
		'answers'   => array(),
	);

	/**
	 * The plugin's post types, once read.
	 *
	 * @var array<string, string>|null
	 */
	private $types = null;

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'tutor';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'Tutor LMS';
	}

	/**
	 * Whether the plugin is on the site: it defines its version while its files load.
	 */
	public function isActive(): bool {
		return defined( 'TUTOR_VERSION' ) && function_exists( 'tutor' );
	}

	/**
	 * Whether a post is built with the plugin. A course is a post SwiftLing reads as any other.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isUsed( int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- A course needs nothing of a builder.
		return false;
	}

	/**
	 * Hooks where the plugin hands its templates their data, grades a quiz and prints the review of
	 * an attempt.
	 */
	public function register(): void {
		add_filter( 'get_tutor_load_template_variables', array( $this, 'filterTemplateVariables' ) );
		add_action( 'tutor_load_template_before', array( $this, 'startTemplate' ), 10, 2 );
		add_action( 'tutor_load_template_after', array( $this, 'endTemplate' ), 10, 2 );
		add_filter( 'tutor_filter_quiz_answer_data', array( $this, 'gradeTypedAnswer' ), 10, 5 );
		add_filter( 'tutor_filter_quiz_total_marks', array( $this, 'addGainedMarks' ), 10, 5 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepBlanks' ) );
		// After the plugin puts the course in the address of a lesson (1) and SwiftLing translates the
		// lesson's own slug (5).
		add_filter( 'post_type_link', array( $this, 'translateCourseSlug' ), 6, 2 );
	}

	/**
	 * Returns the address of a lesson, a quiz or an assignment with the slug of its course in the
	 * language of the link, as the course's own address has it: the plugin names the course in it,
	 * as "/courses/<course>/lessons/<lesson>/". A request puts every translated slug back before
	 * WordPress reads the address, see includes/slugs.php, so the address leads to the lesson.
	 *
	 * @param mixed $link Address.
	 * @param mixed $post Post.
	 * @return mixed
	 */
	public function translateCourseSlug( $link, $post ) {
		$locale = swiftling_link_locale();
		$post   = get_post( $post );
		$types  = $this->types();
		if ( '' === $locale || ! is_string( $link ) || ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( $types['lesson'], $types['quiz'], $types['assignment'] ), true ) || ! swiftling_has_slugs( $locale ) ) {
			return $link;
		}
		$course = get_post( $this->courseOf( $post ) );
		if ( ! $course instanceof \WP_Post || '' === $course->post_name || false === strpos( $link, '/' . $course->post_name . '/' ) ) {
			return $link;
		}
		return swiftling_replace_slugs( $link, swiftling_slug_pairs( array( 'post:' . $course->ID => $course->post_name ), $locale ) );
	}

	/**
	 * Returns the course a lesson, a quiz or an assignment belongs to: the parent of its topic.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function courseOf( \WP_Post $post ): int {
		$topic = $post->post_parent ? get_post( $post->post_parent ) : null;
		return $topic instanceof \WP_Post && $this->types()['topic'] === $topic->post_type ? (int) $topic->post_parent : 0;
	}

	/**
	 * Prepares a page check or a page in a language: the plugin's posts a query returns reach the
	 * page as display copies, and none of them is saved with the texts it shows.
	 *
	 * @param string $mode  probe, control or translate.
	 * @param bool   $probe Whether the request is a page check.
	 */
	public function start( string $mode, bool $probe ): void {
		unset( $probe );
		if ( 'control' === $mode ) {
			return;
		}
		// After swiftling_preload_posts(), which reads the translations of the posts at 10.
		add_filter( 'the_posts', array( $this, 'filterPosts' ), 20 );
		add_filter( 'wp_insert_post_data', array( $this, 'keepStoredTexts' ), 1, 2 );
	}

	/**
	 * Returns the actions of admin-ajax.php the student's browser sends from a page of a course:
	 * the quiz, given up or out of time, an answer checked at once, and the list of courses filtered.
	 * The plugin starts and submits a quiz by sending the form to the page itself, which is in its
	 * language already.
	 *
	 * @return string[]
	 */
	public function sendingActions(): array {
		return array(
			'tutor_quiz_abandon',
			'tutor_quiz_timeout',
			'tutor_quiz_check_answer',
			'tutor_course_filter_ajax',
			'tutor_course_enrollment',
			'tutor_add_course_to_cart',
			'tutor_delete_course_from_cart',
			'tutor_apply_coupon',
			'tutor_get_checkout_html',
		);
	}

	/**
	 * Returns the types of the plugin's posts that get no separate copy: a copy of a course has no
	 * topics, lessons, quizzes or students of its own, and a copy of a lesson or a quiz no course.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		$types = $this->types();
		return array( $types['course'], $types['lesson'], $types['quiz'], $types['assignment'] );
	}

	/**
	 * Returns the plugin's post types by what they are, as the plugin names them.
	 *
	 * @return array{course: string, lesson: string, topic: string, quiz: string, announcement: string, assignment: string}
	 */
	protected function types(): array {
		if ( null !== $this->types ) {
			return $this->types;
		}
		$config      = function_exists( 'tutor' ) ? tutor() : null;
		$read        = static function ( string $name, string $fallback ) use ( $config ): string {
			return is_object( $config ) && isset( $config->$name ) && is_string( $config->$name ) && '' !== $config->$name ? $config->$name : $fallback;
		};
		$this->types = array(
			'course'       => $read( 'course_post_type', 'courses' ),
			'lesson'       => $read( 'lesson_post_type', 'lesson' ),
			'topic'        => $read( 'topics_post_type', 'topics' ),
			'quiz'         => $read( 'quiz_post_type', 'tutor_quiz' ),
			'announcement' => $read( 'announcement_post_type', 'tutor_announcements' ),
			'assignment'   => $read( 'assignment_post_type', 'tutor_assignments' ),
		);
		return $this->types;
	}

	/**
	 * Whether a post is one of the plugin's.
	 *
	 * @param mixed $post Post.
	 */
	private function isTutorPost( $post ): bool {
		return $post instanceof \WP_Post && in_array( $post->post_type, $this->types(), true );
	}

	/**
	 * Gives the plugin's posts a query returns as display copies, see displayCopy().
	 *
	 * @param mixed $posts Posts of the query.
	 * @return mixed
	 */
	public function filterPosts( $posts ) {
		if ( ! is_array( $posts ) || ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $posts;
		}
		foreach ( $posts as $index => $post ) {
			if ( $this->isTutorPost( $post ) ) {
				$posts[ $index ] = $this->displayCopy( $post );
			}
		}
		return $posts;
	}

	/**
	 * Returns a post of the plugin with the texts it prints straight from the post in the mode of the
	 * moment: in a language their translation, in a page check with markers. The title of every one;
	 * the content of a topic (its summary), a quiz (its description) and an announcement, which the
	 * plugin prints as stored. A course and a lesson show their content through the_content, which
	 * translates it already, block by block.
	 *
	 * The post itself, the one WordPress keeps, stays as stored. A copy is made once: its texts are no
	 * longer the stored ones, so a copy given again comes back as it is.
	 *
	 * @param \WP_Post $post Post.
	 * @return \WP_Post The copy, or the post itself when nothing changes.
	 */
	public function displayCopy( \WP_Post $post ): \WP_Post {
		$types   = $this->types();
		$changes = array();

		$title = swiftling_filter_title( $post->post_title, $post->ID );
		if ( is_string( $title ) && $title !== $post->post_title ) {
			$changes['post_title'] = $title;
		}
		if ( in_array( $post->post_type, array( $types['topic'], $types['quiz'], $types['announcement'] ), true ) && '' !== $post->post_content && ! has_blocks( $post->post_content ) ) {
			$content = swiftling_translate_post_content( swiftling_post_owner( $post->ID ), $post->post_content );
			if ( is_string( $content ) && $content !== $post->post_content ) {
				$changes['post_content'] = $content;
			}
		}
		if ( ! $changes ) {
			return $post;
		}

		$copy = clone $post;
		foreach ( $changes as $field => $text ) {
			if ( 'translate' === swiftling_mode() ) {
				$this->shown[ (int) $post->ID ][ $field ] = array( (string) $post->$field, $text );
			}
			$copy->$field = $text;
		}
		return $copy;
	}

	/**
	 * Keeps the texts of a post as stored when a display copy is saved: a plugin that saves the post
	 * it was handed on a page in a language would otherwise store the translation as the original.
	 *
	 * @param mixed $data    The post's fields as they will be saved, slashed.
	 * @param mixed $postarr What was handed to wp_insert_post(), slashed.
	 * @return mixed
	 */
	public function keepStoredTexts( $data, $postarr = array() ) {
		$id = is_array( $postarr ) && isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( ! is_array( $data ) || ! $id || ! isset( $this->shown[ $id ] ) ) {
			return $data;
		}
		foreach ( $this->shown[ $id ] as $field => $texts ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) && wp_unslash( $data[ $field ] ) === $texts[1] ) {
				$data[ $field ] = wp_slash( $texts[0] );
			}
		}
		return $data;
	}

	/**
	 * Translates what the plugin hands one of its templates: the posts it keeps for the learning
	 * area ($tutor_course, $tutor_current_post), which it reads with get_post(), past any query; a
	 * post among the variables; and a question, or a list of them, with its answers.
	 *
	 * @param mixed $variables Variables of the template.
	 * @return mixed
	 */
	public function filterTemplateVariables( $variables ) {
		if ( ! in_array( swiftling_mode(), array( 'probe', 'translate' ), true ) ) {
			return $variables;
		}
		foreach ( array( 'tutor_course', 'tutor_current_post' ) as $global ) {
			if ( isset( $GLOBALS[ $global ] ) && $this->isTutorPost( $GLOBALS[ $global ] ) ) {
				$GLOBALS[ $global ] = $this->displayCopy( $GLOBALS[ $global ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The plugin's own globals, given as display copies; see displayCopy().
			}
		}
		if ( ! is_array( $variables ) ) {
			return $variables;
		}
		foreach ( $variables as $name => $value ) {
			if ( $this->isTutorPost( $value ) ) {
				$variables[ $name ] = $this->displayCopy( $value );
			} elseif ( $this->isQuestion( $value ) ) {
				$variables[ $name ] = $this->translateQuestion( $value );
			} elseif ( is_array( $value ) && $value && $this->isQuestion( reset( $value ) ) ) {
				foreach ( $value as $index => $question ) {
					$value[ $index ] = $this->isQuestion( $question ) ? $this->translateQuestion( $question ) : $question;
				}
				$variables[ $name ] = $value;
			}
		}
		return $variables;
	}

	/**
	 * Whether a value is a question of a quiz as the plugin reads it: a row of its table of
	 * questions, as an object or an array, or a row of the answers of an attempt, which has the same
	 * fields.
	 *
	 * @param mixed $value Value.
	 */
	private function isQuestion( $value ): bool {
		$row = is_object( $value ) ? get_object_vars( $value ) : $value;
		return is_array( $row ) && isset( $row['question_id'] ) && array_key_exists( 'question_title', $row );
	}

	/**
	 * Returns a question with its texts and the texts of its answers in the mode of the moment.
	 *
	 * @param object|array $question Question, as the plugin reads it.
	 * @return object|array The question, a copy of it where it is an object.
	 */
	public function translateQuestion( $question ) {
		$object = is_object( $question );
		$row    = $object ? get_object_vars( $question ) : $question;
		$id     = (int) $row['question_id'];
		$quiz   = isset( $row['quiz_id'] ) ? (int) $row['quiz_id'] : $this->quizOf( $id );
		if ( ! $id || ! $quiz ) {
			return $question;
		}
		$owner = swiftling_post_owner( $quiz );

		$texts = array(
			'question_title'       => 'title',
			'question_description' => 'description',
			'answer_explanation'   => 'explanation',
		);
		foreach ( $texts as $field => $name ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$row[ $field ] = $this->text( $owner, self::questionPath( $id, $name ), $row[ $field ] );
			}
		}
		foreach ( array( 'question_answers', 'question_randomized_answers' ) as $list ) {
			if ( isset( $row[ $list ] ) && is_array( $row[ $list ] ) ) {
				foreach ( $row[ $list ] as $index => $answer ) {
					$row[ $list ][ $index ] = $this->translateAnswer( $owner, $answer );
				}
			}
		}

		if ( ! $object ) {
			return $row;
		}
		$copy = clone $question;
		foreach ( $row as $field => $value ) {
			$copy->$field = $value;
		}
		return $copy;
	}

	/**
	 * Returns an answer with its texts in the mode of the moment: its title, and its match, the
	 * other half of a matching pair, or, for fill in the blank, the answers of its blanks, each a
	 * text of its own and joined again as the plugin keeps them.
	 *
	 * The title of fill in the blank is a sentence with a {dash} for each blank: a translation that
	 * has another number of them would ask for other blanks than the question has, and the sentence
	 * stays the original.
	 *
	 * @param string       $owner  Object key of the quiz.
	 * @param object|array $answer Answer, as the plugin reads it.
	 * @return object|array The answer, a copy of it where it is an object.
	 */
	public function translateAnswer( string $owner, $answer ) {
		$object = is_object( $answer );
		$row    = $object ? get_object_vars( $answer ) : $answer;
		$id     = is_array( $row ) && isset( $row['answer_id'] ) ? (int) $row['answer_id'] : 0;
		if ( ! $id ) {
			return $answer;
		}
		$blanks = isset( $row['belongs_question_type'] ) && 'fill_in_the_blank' === $row['belongs_question_type'];

		if ( isset( $row['answer_title'] ) && is_string( $row['answer_title'] ) && '' !== $row['answer_title'] ) {
			$title = $this->text( $owner, self::answerPath( $id, 'title' ), $row['answer_title'] );
			if ( ! $blanks || substr_count( $title, '{dash}' ) === substr_count( $row['answer_title'], '{dash}' ) ) {
				$row['answer_title'] = $title;
			}
		}
		if ( isset( $row['answer_two_gap_match'] ) && is_string( $row['answer_two_gap_match'] ) && '' !== $row['answer_two_gap_match'] ) {
			$row['answer_two_gap_match'] = $blanks
				? implode( '|', $this->blanks( $owner, $id, $row['answer_two_gap_match'] ) )
				: $this->text( $owner, self::answerPath( $id, 'match' ), $row['answer_two_gap_match'] );
		}

		if ( ! $object ) {
			return $row;
		}
		$copy = clone $answer;
		foreach ( $row as $field => $value ) {
			$copy->$field = $value;
		}
		return $copy;
	}

	/**
	 * Returns the answers of the blanks of a fill in the blank answer in the mode of the moment, each
	 * trimmed as the plugin compares them.
	 *
	 * @param string $owner  Object key of the quiz.
	 * @param int    $id     Answer ID.
	 * @param string $stored The answers as stored, joined with "|".
	 * @return string[]
	 */
	private function blanks( string $owner, int $id, string $stored ): array {
		$blanks = array();
		foreach ( explode( '|', $stored ) as $index => $blank ) {
			$blank    = trim( $blank );
			$blanks[] = '' === $blank ? $blank : trim( $this->text( $owner, self::answerPath( $id, 'blank/' . ( $index + 1 ) ), $blank ) );
		}
		return $blanks;
	}

	/**
	 * Runs a text of a quiz through the mode of the moment. A translation that holds "|" would split
	 * a blank in two, so a blank keeps its original then.
	 *
	 * @param string $owner Object key of the quiz.
	 * @param string $path  Path of the text in the quiz's map.
	 * @param string $value The text, as stored.
	 */
	private function text( string $owner, string $path, string $value ): string {
		$text = swiftling_transform_text( $owner, $path, self::SOURCE, $value );
		if ( ! is_string( $text ) || ( false !== strpos( $path, '/blank/' ) && false !== strpos( $text, '|' ) ) ) {
			return $value;
		}
		return $text;
	}

	/**
	 * Returns the path of a text of a question in its quiz's map, such as "tutor/question/12/title".
	 *
	 * @param int    $id   Question ID.
	 * @param string $name title, description or explanation.
	 */
	public static function questionPath( int $id, string $name ): string {
		return self::BASE . '/question/' . $id . '/' . $name;
	}

	/**
	 * Returns the path of a text of an answer in its quiz's map, such as "tutor/answer/31/title".
	 *
	 * @param int    $id   Answer ID.
	 * @param string $name title, match or blank/<number>.
	 */
	public static function answerPath( int $id, string $name ): string {
		return self::BASE . '/answer/' . $id . '/' . $name;
	}

	/**
	 * Returns the quiz a question belongs to.
	 *
	 * @param int $question_id Question ID.
	 */
	private function quizOf( int $question_id ): int {
		if ( ! isset( $this->quizzes[ $question_id ] ) ) {
			$row                           = $this->question( $question_id );
			$this->quizzes[ $question_id ] = $row ? (int) $row['quiz_id'] : 0;
		}
		return $this->quizzes[ $question_id ];
	}

	/**
	 * Returns a question as stored.
	 *
	 * @param int $question_id Question ID.
	 * @return array|null
	 */
	protected function question( int $question_id ): ?array {
		if ( ! array_key_exists( $question_id, $this->rows['question'] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it, once a request.
			$row                                    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE question_id = %d', $wpdb->prefix . 'tutor_quiz_questions', $question_id ), ARRAY_A );
			$this->rows['question'][ $question_id ] = is_array( $row ) ? $row : null;
		}
		return $this->rows['question'][ $question_id ];
	}

	/**
	 * Returns the questions of a quiz as stored, in their order.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array<int, array>
	 */
	protected function questions( int $quiz_id ): array {
		if ( ! isset( $this->rows['questions'][ $quiz_id ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it, once a request.
			$rows                                = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE quiz_id = %d ORDER BY question_order ASC, question_id ASC', $wpdb->prefix . 'tutor_quiz_questions', $quiz_id ), ARRAY_A );
			$this->rows['questions'][ $quiz_id ] = is_array( $rows ) ? $rows : array();
		}
		return $this->rows['questions'][ $quiz_id ];
	}

	/**
	 * Returns the answers of a question as stored, in their order: those of its type, as the plugin
	 * reads them.
	 *
	 * @param int $question_id Question ID.
	 * @return array<int, array>
	 */
	protected function answers( int $question_id ): array {
		$question = $this->question( $question_id );
		if ( ! $question ) {
			return array();
		}
		if ( ! isset( $this->rows['answers'][ $question_id ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it, once a request.
			$rows                                  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE belongs_question_id = %d AND belongs_question_type = %s ORDER BY answer_order ASC, answer_id ASC', $wpdb->prefix . 'tutor_quiz_question_answers', $question_id, $question['question_type'] ), ARRAY_A );
			$this->rows['answers'][ $question_id ] = is_array( $rows ) ? $rows : array();
		}
		return $this->rows['answers'][ $question_id ];
	}

	/**
	 * Grades an answer the student typed in a language: right also when it is the translation of the
	 * right answer. Fill in the blank compares each blank, ignoring case, as the plugin does with the
	 * original; image answering compares the name typed for each image. An answer the plugin found
	 * right stays right, and the original stays right in every language.
	 *
	 * @param mixed $data        The answer as the plugin will store it.
	 * @param mixed $question_id Question ID.
	 * @param mixed $type        Type of the question.
	 * @param mixed $user_id     Student.
	 * @param mixed $attempt_id  Attempt.
	 * @return mixed
	 */
	public function gradeTypedAnswer( $data, $question_id = 0, $type = '', $user_id = 0, $attempt_id = 0 ) {
		unset( $user_id );
		if ( 'translate' !== swiftling_mode() || ! is_array( $data ) || ! isset( $data['given_answer'], $data['is_correct'] ) || 1 === (int) $data['is_correct'] || ! in_array( $type, array( 'fill_in_the_blank', 'image_answering' ), true ) ) {
			return $data;
		}
		$question_id = (int) $question_id;
		$quiz        = $this->quizOf( $question_id );
		if ( ! $quiz || ! $this->typedRight( $quiz, $question_id, (string) $type, $data['given_answer'] ) ) {
			return $data;
		}
		$mark                  = isset( $data['question_mark'] ) ? (float) $data['question_mark'] : 0.0;
		$data['is_correct']    = 1;
		$data['achieved_mark'] = $mark;
		$this->gained[ (int) $attempt_id . ':' . $question_id ] = $mark;
		return $data;
	}

	/**
	 * Whether what the student typed is the right answer in the language of the page.
	 *
	 * @param int    $quiz        Quiz ID.
	 * @param int    $question_id Question ID.
	 * @param string $type        fill_in_the_blank or image_answering.
	 * @param mixed  $given       What the student typed, as the plugin stores it: serialized.
	 */
	private function typedRight( int $quiz, int $question_id, string $type, $given ): bool {
		$owner   = swiftling_post_owner( $quiz );
		$typed   = maybe_unserialize( is_string( $given ) ? $given : '' );
		$answers = $this->answers( $question_id );
		if ( ! is_array( $typed ) || ! $answers ) {
			return false;
		}
		$same = static function ( $typed, string $right ): bool {
			return '' !== $right && is_string( $typed ) && mb_strtolower( trim( wp_unslash( $typed ) ) ) === mb_strtolower( trim( wp_unslash( $right ) ) );
		};

		if ( 'fill_in_the_blank' === $type ) {
			$answer = $answers[0];
			$right  = $this->blanks( $owner, (int) $answer['answer_id'], (string) $answer['answer_two_gap_match'] );
			$typed  = array_values( $typed );
			if ( count( $typed ) !== count( $right ) ) {
				return false;
			}
			foreach ( $right as $index => $blank ) {
				if ( ! $same( $typed[ $index ], $blank ) ) {
					return false;
				}
			}
			return true;
		}

		foreach ( $answers as $answer ) {
			$id       = (int) $answer['answer_id'];
			$original = (string) $answer['answer_title'];
			$answered = isset( $typed[ $id ] ) ? $typed[ $id ] : ( isset( $typed[ (string) $id ] ) ? $typed[ (string) $id ] : '' );
			if ( ! $same( $answered, $original ) && ! $same( $answered, $this->text( $owner, self::answerPath( $id, 'title' ), $original ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Adds the marks of an answer gradeTypedAnswer() found right to the attempt's total, which the
	 * plugin sums before it hands over the answer.
	 *
	 * @param mixed $total       Total so far.
	 * @param mixed $question_id Question ID.
	 * @param mixed $type        Type of the question.
	 * @param mixed $user_id     Student.
	 * @param mixed $attempt_id  Attempt.
	 * @return mixed
	 */
	public function addGainedMarks( $total, $question_id = 0, $type = '', $user_id = 0, $attempt_id = 0 ) {
		unset( $type, $user_id );
		$key = (int) $attempt_id . ':' . (int) $question_id;
		if ( ! isset( $this->gained[ $key ] ) || ! is_numeric( $total ) ) {
			return $total;
		}
		$mark = $this->gained[ $key ];
		unset( $this->gained[ $key ] );
		return $total + $mark;
	}

	/**
	 * Keeps {dash} as it is when a text with blanks goes to machine translation, see
	 * translateAnswer().
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepBlanks( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) && false !== strpos( $item['text'], '{dash}' ) ) {
				$keep                  = isset( $item['keep'] ) && is_array( $item['keep'] ) ? $item['keep'] : array();
				$items[ $key ]['keep'] = array_values( array_unique( array_merge( $keep, array( '{dash}' ) ) ) );
			}
		}
		return $items;
	}

	/**
	 * Starts to catch what one of the plugin's templates prints when the template reads texts from
	 * the plugin's tables itself, past any variable: a question of the review of an attempt, which
	 * reads its answers, and the cart and the checkout, which read the courses in them; the page of
	 * the checkout includes its details as a file, the checkout's Ajax loads them as a template. What the
	 * template prints goes through templateTranslation() when the buffer ends, see endTemplate().
	 *
	 * @param mixed $template  Template name, such as "ecommerce.cart".
	 * @param mixed $variables Its variables.
	 */
	public function startTemplate( $template, $variables = array() ): void {
		if ( 'translate' !== swiftling_mode() || ! is_string( $template ) ) {
			return;
		}
		$translate = $this->templateTranslation( str_replace( '/', '.', $template ), $variables );
		if ( ! $translate ) {
			return;
		}
		ob_start(
			static function ( $html ) use ( $translate ) {
				return is_string( $html ) ? $translate( $html ) : $html;
			}
		);
		$this->buffers[] = array( $template, ob_get_level() );
	}

	/**
	 * Ends the buffer startTemplate() started, once the template is done: what it printed goes out
	 * with its texts in the language of the page. A buffer the template left open is not this one's
	 * to end; it goes out, translated, when PHP ends it.
	 *
	 * @param mixed $template  Template name.
	 * @param mixed $variables Its variables.
	 */
	public function endTemplate( $template, $variables = array() ): void {
		unset( $variables );
		$last = end( $this->buffers );
		if ( ! $last || $last[0] !== $template ) {
			return;
		}
		array_pop( $this->buffers );
		if ( ob_get_level() === $last[1] ) {
			ob_end_flush();
		}
	}

	/**
	 * Returns how the output of a template is translated, or null when it is not caught.
	 *
	 * Only texts the plugin read itself change, and only where each is a whole text or the value of
	 * an attribute: they are known, and the plugin printed them escaped, as the translations are.
	 *
	 * @param string $name      Template name, with dots.
	 * @param mixed  $variables Its variables.
	 * @return callable|null Takes the output and returns it translated.
	 */
	private function templateTranslation( string $name, $variables ): ?callable {
		if ( 0 === strpos( $name, 'shared.components.quiz.attempt-details.questions.' ) ) {
			$question = is_array( $variables ) && isset( $variables['question'] ) && is_object( $variables['question'] ) ? $variables['question'] : null;
			return $question
				? function ( string $html ) use ( $question ): string {
					return $this->translateReview( $html, $question );
				}
				: null;
		}
		if ( in_array( $name, array( 'ecommerce.cart', 'ecommerce.checkout', 'ecommerce.checkout-details' ), true ) ) {
			return function ( string $html ): string {
				return $this->translateCourseNames( $html );
			};
		}
		return null;
	}

	/**
	 * Returns what the cart or the checkout printed with the names of the courses in it in the
	 * language of the page: the plugin reads them from its tables of carts and orders, as stored.
	 *
	 * @param string $html What the template printed.
	 */
	public function translateCourseNames( string $html ): string {
		foreach ( $this->shopCourses() as $course ) {
			$original = (string) get_post_field( 'post_title', $course, 'raw' );
			$shown    = swiftling_filter_title( $original, $course );
			if ( '' !== trim( $original ) && is_string( $shown ) && $shown !== $original ) {
				$html = self::replaceText( $html, $original, $shown );
			}
		}
		return $html;
	}

	/**
	 * Returns the courses the cart or the checkout of the current student shows: those in the cart,
	 * and those of the order a student pays for later, which the checkout names by its ID.
	 *
	 * @return int[] Course IDs.
	 */
	protected function shopCourses(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables, read as the plugin reads them.
		$courses = $wpdb->get_col( $wpdb->prepare( 'SELECT item.course_id FROM %i AS item INNER JOIN %i AS cart ON cart.id = item.cart_id WHERE cart.user_id = %d', $wpdb->prefix . 'tutor_cart_items', $wpdb->prefix . 'tutor_carts', get_current_user_id() ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only which order the page names; the plugin checks it.
		$order = isset( $_REQUEST['order_id'] ) ? absint( wp_unslash( $_REQUEST['order_id'] ) ) : 0;
		if ( $order ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it.
			$courses = array_merge( (array) $courses, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT item_id FROM %i WHERE order_id = %d', $wpdb->prefix . 'tutor_order_items', $order ) ) );
		}
		return array_values( array_unique( array_map( 'intval', (array) $courses ) ) );
	}

	/**
	 * Returns what the review printed for a question, with the texts of its answers translated. The
	 * blanks of fill in the blank show in the translated sentence, and an answer the student typed in
	 * the language shows as right, as it was graded.
	 *
	 * @param string $html     What the template printed.
	 * @param object $question The question, a row of the attempt's answers.
	 */
	public function translateReview( string $html, $question ): string {
		$question_id = isset( $question->question_id ) ? (int) $question->question_id : 0;
		$quiz        = isset( $question->quiz_id ) ? (int) $question->quiz_id : $this->quizOf( $question_id );
		if ( ! $question_id || ! $quiz ) {
			return $html;
		}
		$owner = swiftling_post_owner( $quiz );
		$pairs = array();
		foreach ( $this->answers( $question_id ) as $answer ) {
			$shown = $this->translateAnswer( $owner, $answer );
			if ( 'fill_in_the_blank' === $answer['belongs_question_type'] ) {
				$html = $this->translateBlanksReview( $html, $answer, $shown );
				continue;
			}
			foreach ( array( 'answer_title', 'answer_two_gap_match' ) as $field ) {
				$original = wp_strip_all_tags( wp_unslash( (string) $answer[ $field ] ) );
				$text     = wp_strip_all_tags( wp_unslash( (string) $shown[ $field ] ) );
				if ( '' !== trim( $original ) && $original !== $text ) {
					$pairs[ $original ] = $text;
				}
			}
		}
		foreach ( $pairs as $original => $text ) {
			$html = self::replaceText( $html, $original, $text );
		}
		return $html;
	}

	/**
	 * Translates what the review printed for a fill in the blank answer: the parts of the sentence
	 * between its blanks, the right answers, and the state of each answer the student typed, which
	 * the plugin judged against the original only.
	 *
	 * @param string $html   What the template printed.
	 * @param array  $answer The answer as stored.
	 * @param array  $shown  The answer in the language.
	 */
	private function translateBlanksReview( string $html, array $answer, array $shown ): string {
		$original = wp_strip_all_tags( wp_unslash( (string) $answer['answer_title'] ) );
		$sentence = wp_strip_all_tags( wp_unslash( (string) $shown['answer_title'] ) );
		$parts    = explode( '{dash}', $original );
		$into     = explode( '{dash}', $sentence );
		if ( $original !== $sentence && count( $parts ) === count( $into ) ) {
			foreach ( $parts as $index => $part ) {
				if ( '' !== trim( $part ) && $part !== $into[ $index ] ) {
					$html = str_replace( esc_html( $part ), esc_html( $into[ $index ] ), $html );
				}
			}
		}

		$stored = array_map( 'trim', explode( '|', (string) $answer['answer_two_gap_match'] ) );
		$right  = explode( '|', (string) $shown['answer_two_gap_match'] );
		$normal = static function ( string $text ): string {
			return mb_strtolower( trim( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) ) );
		};
		$index  = 0;
		$blanks = count( $stored );
		// The plugin prints the student's answers first, then the right ones, each blank a span, its
		// quotes as wp_kses() writes them.
		return (string) preg_replace_callback(
			'~<span class=(["\'])tutor-quiz-question-input\1 data-option=(["\'])(correct|incorrect)\2>([^<]*)</span>~',
			static function ( array $found ) use ( &$index, $blanks, $stored, $right, $normal ): string {
				$blank = $index % max( 1, $blanks );
				$given = $index < $blanks;
				++$index;
				if ( ! isset( $right[ $blank ] ) ) {
					return $found[0];
				}
				$state = $found[3];
				$text  = $found[4];
				if ( ! $given ) {
					$text = $normal( $text ) === $normal( $stored[ $blank ] ) ? esc_html( $right[ $blank ] ) : $text;
				} elseif ( '' !== trim( $text ) && $normal( $text ) === $normal( $right[ $blank ] ) ) {
					$state = 'correct';
				}
				return '<span class="tutor-quiz-question-input" data-option="' . $state . '">' . $text . '</span>';
			},
			$html
		);
	}

	/**
	 * Replaces a text where it is a whole text between tags or the whole value of an attribute.
	 *
	 * @param string $html     HTML.
	 * @param string $original The text, unescaped.
	 * @param string $text     Its translation, unescaped.
	 */
	private static function replaceText( string $html, string $original, string $text ): string {
		$html = (string) preg_replace_callback(
			'~>(\s*)' . preg_quote( esc_html( $original ), '~' ) . '(\s*)<~u',
			static function ( array $found ) use ( $text ): string {
				return '>' . $found[1] . esc_html( $text ) . $found[2] . '<';
			},
			$html
		);
		return (string) preg_replace_callback(
			'~(\s(?:alt|title|aria-label)=")' . preg_quote( esc_attr( $original ), '~' ) . '"~u',
			static function ( array $found ) use ( $text ): string {
				return $found[1] . esc_attr( $text ) . '"';
			},
			$html
		);
	}

	/**
	 * Returns the texts a course or a quiz keeps outside its page, for its editor: of a course, the
	 * titles and summaries of its topics and its announcements, posts no visitor opens; of a quiz, its
	 * description and its questions with their answers, which the quiz shows only once a student
	 * starts it. Each text is read as a page in a language reads it, so its object and path are the
	 * ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$post = 'post' === $subject['kind'] && isset( $subject['post'] ) && $subject['post'] instanceof \WP_Post ? $subject['post'] : null;
		if ( ! $post ) {
			return array();
		}
		$types = $this->types();
		if ( $types['course'] === $post->post_type ) {
			$children = get_posts(
				array(
					'post_type'      => array( $types['topic'], $types['announcement'] ),
					'post_parent'    => $post->ID,
					'post_status'    => array( 'publish', 'private', 'future', 'draft', 'pending' ),
					'posts_per_page' => -1,
					'orderby'        => array(
						'post_type'  => 'DESC',
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
					),
				)
			);
			return $this->collect(
				function () use ( $children ): void {
					foreach ( $children as $child ) {
						$this->displayCopy( $child );
					}
				}
			);
		}
		if ( $types['quiz'] === $post->post_type ) {
			$quiz = (int) $post->ID;
			return $this->collect(
				function () use ( $post, $quiz ): void {
					$this->displayCopy( $post );
					foreach ( $this->questions( $quiz ) as $question ) {
						$question['question_answers'] = $this->answers( (int) $question['question_id'] );
						$this->translateQuestion( $question );
					}
				}
			);
		}
		return array();
	}

	/**
	 * Reads texts as a page check would, see swiftling_collect(), and returns them for the editor.
	 *
	 * @param callable $read Reads the texts.
	 * @return array<int, array{o: string, p: string, v: string, r: string, kind: string, place: string}>
	 */
	private function collect( callable $read ): array {
		$texts = array();
		foreach ( swiftling_collect( $read ) as $leaf ) {
			$title   = 'field/post_title' === $leaf['p'] || ( '/title' === substr( $leaf['p'], -6 ) && 0 === strpos( $leaf['p'], self::BASE . '/question/' ) );
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => $title ? 'heading' : 'paragraph',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Declares the texts of a quiz's questions and answers as text: one word, as an answer "Plum",
	 * is one.
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for everything else.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $path, self::BASE . '/' ) || 0 !== strpos( $owner, 'post:' ) ) {
			return null;
		}
		$quiz = $this->types()['quiz'];
		return get_post_type( (int) substr( $owner, 5 ) ) === $quiz ? true : null;
	}

	/**
	 * Returns what the editor calls a text of a quiz, a topic or an announcement, such as
	 * "Question 3 › Answer 2" or "Topic: Choosing your trees › Summary".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is none of these.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, 'post:' ) ) {
			return '';
		}
		$post  = get_post( (int) substr( $owner, 5 ) );
		$types = $this->types();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		if ( in_array( $post->post_type, array( $types['topic'], $types['announcement'] ), true ) && 0 === strpos( $path, 'field/' ) ) {
			$whole = $types['topic'] === $post->post_type
				/* translators: %s: title of a topic of a course. */
				? __( 'Topic: %s', 'swiftling' )
				/* translators: %s: title of an announcement of a course. */
				: __( 'Announcement: %s', 'swiftling' );
			$part = 'field/post_title' === $path ? __( 'Title', 'swiftling' ) : ( $types['topic'] === $post->post_type ? __( 'Summary', 'swiftling' ) : __( 'Text', 'swiftling' ) );
			return sprintf( $whole, $post->post_title ) . ' › ' . $part;
		}
		if ( $types['quiz'] !== $post->post_type ) {
			return '';
		}
		if ( 0 === strpos( $path, 'field/post_content' ) ) {
			return __( 'Quiz description', 'swiftling' );
		}
		if ( 1 === preg_match( '~^' . self::BASE . '/question/(\d+)/(title|description|explanation)$~', $path, $found ) ) {
			$names = array(
				'title'       => __( 'Question', 'swiftling' ),
				'description' => __( 'Description', 'swiftling' ),
				'explanation' => __( 'Answer explanation', 'swiftling' ),
			);
			return $this->questionName( (int) $post->ID, (int) $found[1] ) . ' › ' . $names[ $found[2] ];
		}
		if ( 1 === preg_match( '~^' . self::BASE . '/answer/(\d+)/(title|match|blank/(\d+))$~', $path, $found ) ) {
			list( $question, $number, $blanks ) = $this->answerPlace( (int) $post->ID, (int) $found[1] );
			if ( 'title' === $found[2] ) {
				/* translators: %d: number of an answer of a question, counted from one. */
				$what = $blanks ? __( 'Sentence, with {dash} for each blank', 'swiftling' ) : sprintf( __( 'Answer %d', 'swiftling' ), $number );
			} elseif ( 'match' === $found[2] ) {
				/* translators: %d: number of an answer of a question, counted from one. */
				$what = sprintf( __( 'Match of answer %d', 'swiftling' ), $number );
			} else {
				/* translators: %d: number of a blank in a sentence, counted from one. */
				$what = sprintf( __( 'Right answer of blank %d', 'swiftling' ), (int) $found[3] );
			}
			return $question . ' › ' . $what;
		}
		return '';
	}

	/**
	 * Returns what the editor calls a question of a quiz, by its place in the quiz.
	 *
	 * @param int $quiz_id     Quiz ID.
	 * @param int $question_id Question ID.
	 */
	private function questionName( int $quiz_id, int $question_id ): string {
		$number = 0;
		foreach ( $this->questions( $quiz_id ) as $index => $question ) {
			if ( (int) $question['question_id'] === $question_id ) {
				$number = $index + 1;
			}
		}
		/* translators: %d: number of a question of a quiz, counted from one. */
		return $number ? sprintf( __( 'Question %d', 'swiftling' ), $number ) : __( 'Question', 'swiftling' );
	}

	/**
	 * Returns the question an answer belongs to, as the editor names it, the answer's number in it
	 * and whether it is the sentence of fill in the blank.
	 *
	 * @param int $quiz_id   Quiz ID.
	 * @param int $answer_id Answer ID.
	 * @return array{0: string, 1: int, 2: bool}
	 */
	private function answerPlace( int $quiz_id, int $answer_id ): array {
		foreach ( $this->questions( $quiz_id ) as $question ) {
			foreach ( $this->answers( (int) $question['question_id'] ) as $index => $answer ) {
				if ( (int) $answer['answer_id'] === $answer_id ) {
					return array( $this->questionName( $quiz_id, (int) $question['question_id'] ), $index + 1, 'fill_in_the_blank' === $question['question_type'] );
				}
			}
		}
		return array( __( 'Question', 'swiftling' ), 0, false );
	}
}
