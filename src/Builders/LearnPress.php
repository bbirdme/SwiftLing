<?php
/**
 * LearnPress: what of a course SwiftLing translates beyond what it finds by itself.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Builders;

defined( 'ABSPATH' ) || exit;

/**
 * LearnPress. A course, a lesson, a quiz and a question are posts, and their titles and content
 * reach the page through get_the_title() and the_content, where SwiftLing translates them as on any
 * post. What it does not find by itself:
 *
 * - LearnPress keeps a copy of each course in its own table and reads the course's fields from it,
 *   past get_post_meta(): what students learn, the requirements, the audience, the FAQs and the
 *   words around the price. They are translated where the plugin prints them, in the HTML it hands to
 *   its filters with the course, see translateLists(), as meta of the course, so the editor offers
 *   them as fields of the course.
 * - The sections of a course, their names and descriptions, are rows of the plugin's own table: texts
 *   of the course, "learnpress/section/<ID>/name", offered in its editor, translated where the plugin
 *   prints a section, see filterSectionItem().
 * - The answers of a question are rows of another table: texts of the question, offered in its editor
 *   and in the quiz's, translated where the plugin hands them to its quiz, see filterAnswerOptions().
 *   The hint and the explanation of a question are its meta, read through get_post_meta().
 * - A fill in the blanks answer is a sentence with a [fib] shortcode for each blank. Its editor shows
 *   the sentence with {{FIB_<id>}} for each blank, and each blank's right answer as a text of its own.
 *   A translation with other blanks shows the original sentence, and machine translation leaves the
 *   blanks as they are, see keepBlanks().
 * - A blank is graded against what the student typed. In a language the right answer in that
 *   language is right, as the original is, see gradeBlank().
 * - The plugin builds the address of a lesson or a quiz from its course's address and the item's own
 *   slug, which is the one of the language there, see translateItemLink().
 * - The quiz, the enrolment, the curriculum and the course list ask the plugin's REST API from the
 *   page: in the language of that page, see sendingRoutes().
 * - A course, its lessons, quizzes and questions get no separate copy: a copy would have no sections,
 *   items or students. They are translated in place, see Builder::noCopyTypes().
 *
 * Not reached: the address of an offline course, which the plugin builds and passes to a filter whose
 * answer it does not use.
 *
 * Checked against the code of LearnPress 4.4.12 (the free plugin), in tests/LearnPressTest.php with
 * rows of the same shape, and by hand on a site with the plugin.
 */
class LearnPress extends Builder {
	/**
	 * Where the texts of sections and answers are in the maps of their course and question.
	 */
	const BASE = 'learnpress';

	/**
	 * Source of a text of a section or an answer, for its rule key.
	 */
	const SOURCE = 'learnpress:row';

	/**
	 * The course's fields kept as meta that the plugin reads from its copy of the course: lists of
	 * texts, the FAQs as pairs, and single texts. Each with the filter that hands over its HTML.
	 */
	const COURSE_LISTS = array(
		'_lp_requirements'     => 'learn-press/course/html-requirements',
		'_lp_key_features'     => 'learn-press/course/html-features',
		'_lp_target_audiences' => 'learn-press/course/html-target',
	);

	/**
	 * The words before and after the price of a course, by meta key, with the class of their span.
	 */
	const PRICE_WORDS = array(
		'_lp_price_prefix' => 'course-price-prefix',
		'_lp_price_suffix' => 'course-price-suffix',
	);

	/**
	 * Answers read in this request, by question ID, and the question of each answer, by answer ID.
	 *
	 * @var array{answers: array<int, array>, question: array<int, int>, blanks: array<string, array{0: int, 1: int}>}
	 */
	private $rows = array(
		'answers'  => array(),
		'question' => array(),
		'blanks'   => array(),
	);

	/**
	 * Returns the class's ID.
	 */
	public function id(): string {
		return 'learnpress';
	}

	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string {
		return 'LearnPress';
	}

	/**
	 * Whether the plugin is on the site: it names its main file while its files load.
	 */
	public function isActive(): bool {
		return defined( 'LP_PLUGIN_FILE' );
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
	 * Hooks where the plugin prints the fields and sections of a course, hands a question's answers to
	 * its quiz, grades a blank and builds the address of an item.
	 */
	public function register(): void {
		foreach ( self::COURSE_LISTS as $filter ) {
			add_filter( $filter, array( $this, 'translateLists' ), 10, 2 );
		}
		add_filter( 'learn-press/course/html-faqs', array( $this, 'translateFaqs' ), 10, 2 );
		add_filter( 'learn-press/course/html-price', array( $this, 'translatePriceWords' ), 10, 2 );
		add_filter( 'learn-press/course/html-section-item', array( $this, 'filterSectionItem' ), 10, 4 );
		// Before the plugin turns the sentence's shortcodes into placeholders (10).
		add_filter( 'learn-press/question/fib/regex-content', array( $this, 'translateSentence' ), 5, 2 );
		add_filter( 'learn-press/question/answer-options', array( $this, 'filterAnswerOptions' ), 10, 2 );
		add_filter( 'learn-press/question/fill-in-blank/check_answer', array( $this, 'gradeBlank' ), 10, 2 );
		add_filter( 'learn-press/course/item-link', array( $this, 'translateItemLink' ), 10, 2 );
		add_filter( 'swiftling_machine_items', array( $this, 'keepBlanks' ) );
	}

	/**
	 * Returns the routes of the plugin's REST API the page of a course asks: the quiz, the enrolment
	 * and the purchase, the curriculum and the progress loaded later, and the course list filtered.
	 * Answered in the language of that page, so the questions, the curriculum and the address the
	 * plugin sends the student to are in it.
	 *
	 * @return string[]
	 */
	public function sendingRoutes(): array {
		return array(
			'#^/lp/v1/users/(start-quiz|submit-quiz|check-answer)/?$#',
			'#^/lp/v1/courses/(enroll-course|purchase-course|retake-course|continue-course|archive-course|courses-widget-by-page)/?$#',
			'#^/lp/v1/lazy-load/#',
			'#^/lp/v1/load_content_via_ajax/?$#',
		);
	}

	/**
	 * Returns the types of the plugin's posts that get no separate copy: a copy of a course has no
	 * sections, items or students, and a copy of an item no course.
	 *
	 * @return string[]
	 */
	public function noCopyTypes(): array {
		return array( 'lp_course', 'lp_lesson', 'lp_quiz', 'lp_question' );
	}

	/**
	 * Whether SwiftLing changes the request: a page check or a page in a language.
	 */
	private static function inMode(): bool {
		return in_array( swiftling_mode(), array( 'probe', 'translate' ), true );
	}

	/**
	 * Returns the ID of a course the plugin passes, a model of it.
	 *
	 * @param mixed $course Course model.
	 */
	private static function courseId( $course ): int {
		if ( is_object( $course ) && method_exists( $course, 'get_id' ) ) {
			return (int) $course->get_id();
		}
		return is_object( $course ) && isset( $course->ID ) ? (int) $course->ID : 0;
	}

	/**
	 * Returns a meta value of a course as stored, past SwiftLing.
	 *
	 * @param int    $course_id Course ID.
	 * @param string $key       Meta key.
	 * @return mixed
	 */
	private static function storedMeta( int $course_id, string $key ) {
		$state          = &swiftling_state();
		$guard          = $state['guard'];
		$state['guard'] = true;
		try {
			return maybe_unserialize( get_post_meta( $course_id, $key, true ) );
		} finally {
			$state['guard'] = $guard;
		}
	}

	/**
	 * Returns a meta value of a course in the mode of the moment, under the path SwiftLing gives the
	 * value where get_post_meta() reads it, so a translation made either way shows both ways.
	 *
	 * @param int    $course_id Course ID.
	 * @param string $key       Meta key.
	 * @param mixed  $value     The value, as stored.
	 * @return mixed
	 */
	private static function metaShown( int $course_id, string $key, $value ) {
		return swiftling_transform( swiftling_post_owner( $course_id ), swiftling_meta_path( $key, 0 ), 'meta:' . $key, $value );
	}

	/**
	 * Translates a list of the course the plugin prints one item to a line, such as its requirements:
	 * each item where it is the whole of a line.
	 *
	 * @param mixed $section The parts of the HTML, as the plugin hands them over.
	 * @param mixed $course  Course model.
	 * @return mixed
	 */
	public function translateLists( $section, $course = null ) {
		$course_id = self::courseId( $course );
		if ( ! is_array( $section ) || ! isset( $section['content'] ) || ! is_string( $section['content'] ) || ! $course_id || ! self::inMode() ) {
			return $section;
		}
		$filter = current_filter();
		$key    = (string) array_search( $filter, self::COURSE_LISTS, true );
		$stored = '' !== $key ? self::storedMeta( $course_id, $key ) : null;
		if ( ! is_array( $stored ) ) {
			return $section;
		}
		$shown = self::metaShown( $course_id, $key, $stored );
		foreach ( $stored as $index => $item ) {
			if ( is_string( $item ) && isset( $shown[ $index ] ) && is_string( $shown[ $index ] ) && $shown[ $index ] !== $item ) {
				$section['content'] = self::replaceOnce( $section['content'], '<li>' . $item . '</li>', '<li>' . $shown[ $index ] . '</li>' );
			}
		}
		return $section;
	}

	/**
	 * Translates the FAQs of a course: each question in its label and each answer as the plugin prints
	 * it, cleaned with wp_kses_post().
	 *
	 * @param mixed $section The parts of the HTML.
	 * @param mixed $course  Course model.
	 * @return mixed
	 */
	public function translateFaqs( $section, $course = null ) {
		$course_id = self::courseId( $course );
		if ( ! is_array( $section ) || ! isset( $section['content'] ) || ! is_string( $section['content'] ) || ! $course_id || ! self::inMode() ) {
			return $section;
		}
		$stored = self::storedMeta( $course_id, '_lp_faqs' );
		if ( ! is_array( $stored ) ) {
			return $section;
		}
		$shown = self::metaShown( $course_id, '_lp_faqs', $stored );
		foreach ( $stored as $index => $faq ) {
			if ( ! is_array( $faq ) || ! isset( $faq[0], $faq[1], $shown[ $index ][0], $shown[ $index ][1] ) ) {
				continue;
			}
			if ( $shown[ $index ][0] !== $faq[0] ) {
				$section['content'] = self::replaceOnce( $section['content'], '">' . $faq[0] . '</label>', '">' . $shown[ $index ][0] . '</label>' );
			}
			if ( $shown[ $index ][1] !== $faq[1] ) {
				$section['content'] = self::replaceOnce( $section['content'], '-inner">' . wp_kses_post( (string) $faq[1] ), '-inner">' . wp_kses_post( (string) $shown[ $index ][1] ) );
			}
		}
		return $section;
	}

	/**
	 * Translates the words the plugin prints before and after the price of a course.
	 *
	 * @param mixed $html   The price, as HTML.
	 * @param mixed $course Course model.
	 * @return mixed
	 */
	public function translatePriceWords( $html, $course = null ) {
		$course_id = self::courseId( $course );
		if ( ! is_string( $html ) || ! $course_id || ! self::inMode() ) {
			return $html;
		}
		foreach ( self::PRICE_WORDS as $key => $class ) {
			$stored = self::storedMeta( $course_id, $key );
			if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
				continue;
			}
			$shown = self::metaShown( $course_id, $key, $stored );
			if ( is_string( $shown ) && $shown !== $stored ) {
				$html = self::replaceOnce( $html, '<span class="' . $class . '">' . $stored . '</span>', '<span class="' . $class . '">' . $shown . '</span>' );
			}
		}
		return $html;
	}

	/**
	 * Translates the name and the description of a section where the plugin prints it, in the course's
	 * curriculum and beside a lesson or a quiz.
	 *
	 * @param mixed $parts        The parts of the section's HTML.
	 * @param mixed $course       Course model.
	 * @param mixed $user         The student.
	 * @param mixed $section_item The section: its ID, name and description.
	 * @return mixed
	 */
	public function filterSectionItem( $parts, $course = null, $user = null, $section_item = null ) {
		unset( $user );
		$course_id = self::courseId( $course );
		if ( ! is_array( $parts ) || ! isset( $parts['header'] ) || ! is_string( $parts['header'] ) || ! is_object( $section_item ) || ! $course_id || ! self::inMode() ) {
			return $parts;
		}
		$section_id = isset( $section_item->section_id ) ? (int) $section_item->section_id : 0;
		$owner      = swiftling_post_owner( $course_id );
		$places     = array(
			'name'        => array( 'section_name', '<div class="course-section__title">', '</div>' ),
			'description' => array( 'section_description', '<div class="course-section__description">', '</div>' ),
		);
		foreach ( $places as $name => $place ) {
			$text = isset( $section_item->{$place[0]} ) ? (string) $section_item->{$place[0]} : '';
			if ( '' === trim( $text ) || ! $section_id ) {
				continue;
			}
			$shown = swiftling_transform_text( $owner, self::sectionPath( $section_id, $name ), self::SOURCE, $text );
			if ( $shown !== $text ) {
				$parts['header'] = self::replaceOnce( $parts['header'], $place[1] . wp_kses_post( $text ) . $place[2], $place[1] . wp_kses_post( $shown ) . $place[2] );
			}
		}
		return $parts;
	}

	/**
	 * Translates the answers of a question where the plugin hands them to its quiz: the text of each
	 * choice, and of fill in the blanks the sentence the plugin made for its API and the right answer
	 * of each blank it shows in the review. The values of the answers, by which the plugin grades a
	 * choice, stay as stored.
	 *
	 * @param mixed $options     The answers.
	 * @param mixed $question_id Question ID.
	 * @return mixed
	 */
	public function filterAnswerOptions( $options, $question_id = 0 ) {
		$question_id = (int) $question_id;
		if ( ! is_array( $options ) || ! $question_id || ! self::inMode() ) {
			return $options;
		}
		$owner = swiftling_post_owner( $question_id );
		foreach ( $options as $index => $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$answer_id = (int) ( $option['question_answer_id'] ?? ( $option['uid'] ?? 0 ) );
			$row       = $answer_id ? $this->answer( $question_id, $answer_id ) : null;
			if ( ! $row ) {
				continue;
			}
			if ( false !== strpos( (string) $row['title'], '[fib' ) ) {
				$sentence = $this->sentence( $owner, $answer_id, (string) $row['title'] );
				if ( isset( $option['title_api'] ) && $sentence !== $row['title'] ) {
					$option['title_api'] = wp_strip_all_tags( self::placeholders( $sentence ) );
				}
				foreach ( isset( $option['answers'] ) && is_array( $option['answers'] ) ? $option['answers'] : array() as $blank_id => $blank ) {
					if ( is_array( $blank ) && isset( $blank['correct'] ) && is_string( $blank['correct'] ) ) {
						$option['answers'][ $blank_id ]['correct'] = $this->blank( $owner, $answer_id, (string) $blank_id, $blank['correct'] );
					}
				}
			} elseif ( isset( $option['title'] ) && is_string( $option['title'] ) && '' !== $option['title'] ) {
				$shown = swiftling_transform_text( $owner, self::answerPath( $answer_id, 'title' ), self::SOURCE, (string) $row['title'] );
				if ( $shown !== $row['title'] ) {
					$option['title'] = do_shortcode( $shown );
				}
			}
			$options[ $index ] = $option;
		}
		return $options;
	}

	/**
	 * Returns the sentence of a fill in the blanks answer in the language of the page, before the
	 * plugin turns its shortcodes into the fields of the quiz.
	 *
	 * @param mixed $content   The sentence, with a [fib] shortcode for each blank.
	 * @param mixed $answer_id Answer ID.
	 * @return mixed
	 */
	public function translateSentence( $content, $answer_id = 0 ) {
		$answer_id = (int) $answer_id;
		if ( ! is_string( $content ) || ! $answer_id || ! self::inMode() || false === strpos( $content, '[fib' ) ) {
			return $content;
		}
		$question_id = $this->questionOf( $answer_id );
		return $question_id ? $this->sentence( swiftling_post_owner( $question_id ), $answer_id, $content ) : $content;
	}

	/**
	 * Returns a sentence with blanks in the mode of the moment. The text translated is the sentence with
	 * {{FIB_<id>}} for each blank, as the quiz shows it; each placeholder goes back as the shortcode it
	 * stands for. A translation with other blanks than the original asks for answers the question has
	 * not, and the sentence stays the original.
	 *
	 * @param string $owner     Object key of the question.
	 * @param int    $answer_id Answer ID.
	 * @param string $stored    The sentence as stored, with its shortcodes.
	 */
	private function sentence( string $owner, int $answer_id, string $stored ): string {
		$codes = self::shortcodes( $stored );
		if ( ! $codes ) {
			return $stored;
		}
		$text  = strtr( $stored, array_flip( $codes ) );
		$shown = swiftling_transform_text( $owner, self::answerPath( $answer_id, 'title' ), self::SOURCE, $text );
		if ( ! is_string( $shown ) || $shown === $text ) {
			return $stored;
		}
		preg_match_all( '/\{\{FIB_[^}]+\}\}/', $shown, $found );
		$tokens = $found[0];
		sort( $tokens );
		$expected = array_keys( $codes );
		sort( $expected );
		if ( $tokens !== $expected ) {
			return $stored;
		}
		return strtr( $shown, $codes );
	}

	/**
	 * Returns the [fib] shortcodes of a sentence by the placeholder that stands for each.
	 *
	 * @param string $sentence Sentence, as stored.
	 * @return array<string, string> {{FIB_<id>}} => shortcode.
	 */
	private static function shortcodes( string $sentence ): array {
		$codes = array();
		if ( preg_match_all( '/\[fib\b[^\]]*\]/', $sentence, $found ) ) {
			foreach ( $found[0] as $code ) {
				$id = preg_match( '/\bid=["\']?([^"\'\s\]]+)/', $code, $match ) ? $match[1] : '';
				if ( '' !== $id ) {
					$codes[ '{{FIB_' . $id . '}}' ] = $code;
				}
			}
		}
		return $codes;
	}

	/**
	 * Returns a sentence with each [fib] shortcode as the placeholder the quiz shows, as the plugin
	 * makes it for its API.
	 *
	 * @param string $sentence Sentence with shortcodes.
	 */
	private static function placeholders( string $sentence ): string {
		$codes = self::shortcodes( $sentence );
		return strtr(
			$sentence,
			array_map(
				static function ( string $token ): string {
					return ' ' . $token . ' ';
				},
				array_flip( $codes )
			)
		);
	}

	/**
	 * Returns the right answer of a blank in the mode of the moment. A blank that takes a range of
	 * numbers is no text.
	 *
	 * @param string $owner     Object key of the question.
	 * @param int    $answer_id Answer ID.
	 * @param string $blank_id  ID of the blank in the sentence.
	 * @param string $fill      The right answer, as stored.
	 */
	private function blank( string $owner, int $answer_id, string $blank_id, string $fill ): string {
		if ( '' === trim( $fill ) || ! preg_match( '/\p{L}/u', $fill ) ) {
			return $fill;
		}
		$shown = swiftling_transform_text( $owner, self::answerPath( $answer_id, 'blank/' . swiftling_escape_segment( $blank_id ) ), self::SOURCE, $fill );
		return is_string( $shown ) ? $shown : $fill;
	}

	/**
	 * Grades a blank the student typed in a language: right also when it is the translation of the
	 * right answer, compared as the plugin compares the original (any case, unless the blank asks for
	 * it, and each word of a list of right answers). A blank the plugin found right stays right; a
	 * range of numbers is graded as stored.
	 *
	 * @param mixed $correct Whether the plugin found the blank right.
	 * @param mixed $data    The blank and what the student typed.
	 * @return mixed
	 */
	public function gradeBlank( $correct, $data = array() ) {
		if ( true === $correct || 'translate' !== swiftling_mode() || ! is_array( $data ) || ! isset( $data['blank'], $data['user_fill'] ) || ! is_array( $data['blank'] ) ) {
			return $correct;
		}
		$blank = $data['blank'];
		if ( empty( $blank['id'] ) || ! isset( $blank['fill'] ) || 'range' === ( $blank['comparison'] ?? '' ) ) {
			return $correct;
		}
		$place = $this->blankPlace( (string) $blank['id'], (string) $blank['fill'] );
		if ( ! $place ) {
			return $correct;
		}
		$fill = $this->blank( swiftling_post_owner( $place[1] ), $place[0], (string) $blank['id'], (string) $blank['fill'] );
		if ( $fill === $blank['fill'] ) {
			return $correct;
		}
		$typed  = trim( (string) $data['user_fill'] );
		$case   = ! empty( $blank['match_case'] );
		$same   = static function ( string $right ) use ( $typed, $case ): bool {
			$right = trim( $right );
			return $case ? $typed === $right : mb_strtolower( $typed, 'UTF-8' ) === mb_strtolower( $right, 'UTF-8' );
		};
		$rights = 'any' === ( $blank['comparison'] ?? '' ) ? explode( ',', $fill ) : array( $fill );
		foreach ( $rights as $right ) {
			if ( '' !== $typed && $same( $right ) ) {
				return true;
			}
		}
		return $correct;
	}

	/**
	 * Returns the answer a blank belongs to and its question, found by the blank's ID and its right
	 * answer among the blanks the plugin keeps.
	 *
	 * @param string $blank_id ID of the blank.
	 * @param string $fill     Its right answer, as stored.
	 * @return array{0: int, 1: int}|null Answer ID and question ID.
	 */
	private function blankPlace( string $blank_id, string $fill ): ?array {
		$key = $blank_id . "\x01" . $fill;
		if ( ! array_key_exists( $key, $this->rows['blanks'] ) ) {
			$this->rows['blanks'][ $key ] = null;
			foreach ( $this->blankRows( $blank_id ) as $row ) {
				$blanks = maybe_unserialize( $row['meta_value'] );
				if ( is_array( $blanks ) && isset( $blanks[ $blank_id ]['fill'] ) && (string) $blanks[ $blank_id ]['fill'] === $fill ) {
					$question = $this->questionOf( (int) $row['learnpress_question_answer_id'] );
					if ( $question ) {
						$this->rows['blanks'][ $key ] = array( (int) $row['learnpress_question_answer_id'], $question );
						break;
					}
				}
			}
		}
		return $this->rows['blanks'][ $key ];
	}

	/**
	 * Returns the stored blanks of the answers that may hold a blank of an ID.
	 *
	 * @param string $blank_id ID of the blank.
	 * @return array<int, array{learnpress_question_answer_id: string, meta_value: string}>
	 */
	protected function blankRows( string $blank_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The plugin's own table, once a blank in a request.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT learnpress_question_answer_id, meta_value FROM %i WHERE meta_key = %s AND meta_value LIKE %s', $wpdb->prefix . 'learnpress_question_answermeta', '_blanks', '%' . $wpdb->esc_like( '"' . $blank_id . '"' ) . '%' ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Keeps the blanks of a sentence as they are when it goes to machine translation.
	 *
	 * @param mixed $items Texts sent, by key.
	 * @return mixed
	 */
	public function keepBlanks( $items ) {
		if ( ! is_array( $items ) ) {
			return $items;
		}
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) && preg_match_all( '/\{\{FIB_[^}]+\}\}/', $item['text'], $found ) ) {
				$keep                  = isset( $item['keep'] ) && is_array( $item['keep'] ) ? $item['keep'] : array();
				$items[ $key ]['keep'] = array_values( array_unique( array_merge( $keep, $found[0] ) ) );
			}
		}
		return $items;
	}

	/**
	 * Returns the address of a lesson or a quiz with its own slug in the language of the link. The
	 * plugin builds it from its course's address, which has the course's slug of the language already,
	 * and the item's slug as stored.
	 *
	 * @param mixed $link    Address.
	 * @param mixed $item_id Lesson or quiz ID.
	 * @return mixed
	 */
	public function translateItemLink( $link, $item_id = 0 ) {
		$locale = swiftling_link_locale();
		$item   = get_post( (int) $item_id );
		if ( '' === $locale || ! is_string( $link ) || ! $item instanceof \WP_Post || '' === $item->post_name || ! swiftling_has_slugs( $locale ) ) {
			return $link;
		}
		return swiftling_replace_slugs( $link, swiftling_slug_pairs( array( 'post:' . $item->ID => $item->post_name ), $locale ) );
	}

	/**
	 * Returns the path of a text of a section in its course's map, such as "learnpress/section/3/name".
	 *
	 * @param int    $id   Section ID.
	 * @param string $name name or description.
	 */
	public static function sectionPath( int $id, string $name ): string {
		return self::BASE . '/section/' . $id . '/' . $name;
	}

	/**
	 * Returns the path of a text of an answer in its question's map, such as "learnpress/answer/8/title".
	 *
	 * @param int    $id   Answer ID.
	 * @param string $name title or blank/<ID of the blank>.
	 */
	public static function answerPath( int $id, string $name ): string {
		return self::BASE . '/answer/' . $id . '/' . $name;
	}

	/**
	 * Replaces the first place of a text.
	 *
	 * @param string $html     HTML.
	 * @param string $original What is there.
	 * @param string $shown    What goes there.
	 */
	private static function replaceOnce( string $html, string $original, string $shown ): string {
		$at = strpos( $html, $original );
		return false === $at ? $html : substr_replace( $html, $shown, $at, strlen( $original ) );
	}

	/**
	 * Returns an answer of a question as stored.
	 *
	 * @param int $question_id Question ID.
	 * @param int $answer_id   Answer ID.
	 * @return array|null
	 */
	private function answer( int $question_id, int $answer_id ): ?array {
		foreach ( $this->answers( $question_id ) as $row ) {
			if ( (int) $row['question_answer_id'] === $answer_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Returns the answers of a question as stored, in their order.
	 *
	 * @param int $question_id Question ID.
	 * @return array<int, array>
	 */
	protected function answers( int $question_id ): array {
		if ( ! isset( $this->rows['answers'][ $question_id ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it, once a request.
			$rows                                  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE question_id = %d ORDER BY `order` ASC, question_answer_id ASC', $wpdb->prefix . 'learnpress_question_answers', $question_id ), ARRAY_A );
			$this->rows['answers'][ $question_id ] = is_array( $rows ) ? $rows : array();
			foreach ( $this->rows['answers'][ $question_id ] as $row ) {
				$this->rows['question'][ (int) $row['question_answer_id'] ] = $question_id;
			}
		}
		return $this->rows['answers'][ $question_id ];
	}

	/**
	 * Returns the question an answer belongs to.
	 *
	 * @param int $answer_id Answer ID.
	 */
	protected function questionOf( int $answer_id ): int {
		if ( ! isset( $this->rows['question'][ $answer_id ] ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it.
			$this->rows['question'][ $answer_id ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT question_id FROM %i WHERE question_answer_id = %d', $wpdb->prefix . 'learnpress_question_answers', $answer_id ) );
		}
		return $this->rows['question'][ $answer_id ];
	}

	/**
	 * Returns the sections of a course as stored, in their order.
	 *
	 * @param int $course_id Course ID.
	 * @return array<int, array>
	 */
	protected function sections( int $course_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE section_course_id = %d ORDER BY section_order ASC, section_id ASC', $wpdb->prefix . 'learnpress_sections', $course_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns the questions of a quiz, in their order.
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return int[]
	 */
	protected function quizQuestions( int $quiz_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT question_id FROM %i WHERE quiz_id = %d ORDER BY question_order ASC, quiz_question_id ASC', $wpdb->prefix . 'learnpress_quiz_questions', $quiz_id ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Returns the texts a course, a quiz or a question keeps outside its page, for its editor: of a
	 * course, its sections and the fields the plugin reads from its copy of the course; of a question,
	 * its answers, hint and explanation; of a quiz, each of its questions with all of those, as the
	 * quiz shows them only once a student starts it. Each text is read as a page in a language reads
	 * it, so its object and path are the ones translated there.
	 *
	 * @param array $subject Subject, see swiftling_subject().
	 */
	public function listTexts( array $subject ): array {
		$post = 'post' === $subject['kind'] && isset( $subject['post'] ) && $subject['post'] instanceof \WP_Post ? $subject['post'] : null;
		if ( ! $post ) {
			return array();
		}
		$id = (int) $post->ID;
		if ( 'lp_course' === $post->post_type ) {
			return $this->collect(
				function () use ( $id ): void {
					$owner = swiftling_post_owner( $id );
					foreach ( $this->sections( $id ) as $section ) {
						foreach ( array(
							'name'        => 'section_name',
							'description' => 'section_description',
						) as $name => $field ) {
							if ( '' !== trim( (string) $section[ $field ] ) ) {
								swiftling_transform_text( $owner, self::sectionPath( (int) $section['section_id'], $name ), self::SOURCE, (string) $section[ $field ] );
							}
						}
					}
					foreach ( array_merge( array_keys( self::COURSE_LISTS ), array( '_lp_faqs' ), array_keys( self::PRICE_WORDS ) ) as $key ) {
						get_post_meta( $id, $key, true );
					}
				}
			);
		}
		if ( 'lp_question' === $post->post_type ) {
			return $this->collect(
				function () use ( $id ): void {
					$this->readQuestion( $id );
				}
			);
		}
		if ( 'lp_quiz' === $post->post_type ) {
			$questions = $this->quizQuestions( $id );
			return $this->collect(
				function () use ( $questions ): void {
					foreach ( $questions as $question ) {
						$post = get_post( $question );
						if ( $post instanceof \WP_Post ) {
							swiftling_filter_title( $post->post_title, $question );
							swiftling_translate_post_content( swiftling_post_owner( $question ), $post->post_content );
						}
						$this->readQuestion( $question );
					}
				}
			);
		}
		return array();
	}

	/**
	 * Reads the texts of a question its page does not show, as the quiz reads them: its answers, the
	 * right answers of its blanks, its hint and its explanation.
	 *
	 * @param int $question_id Question ID.
	 */
	private function readQuestion( int $question_id ): void {
		$owner = swiftling_post_owner( $question_id );
		foreach ( $this->answers( $question_id ) as $row ) {
			$answer_id = (int) $row['question_answer_id'];
			$title     = (string) $row['title'];
			if ( '' === trim( $title ) ) {
				continue;
			}
			if ( false === strpos( $title, '[fib' ) ) {
				swiftling_transform_text( $owner, self::answerPath( $answer_id, 'title' ), self::SOURCE, $title );
				continue;
			}
			$this->sentence( $owner, $answer_id, $title );
			$blanks = $this->storedBlanks( $answer_id );
			foreach ( $blanks as $blank_id => $blank ) {
				if ( is_array( $blank ) && isset( $blank['fill'] ) && 'range' !== ( $blank['comparison'] ?? '' ) ) {
					$this->blank( $owner, $answer_id, (string) $blank_id, (string) $blank['fill'] );
				}
			}
		}
		get_post_meta( $question_id, '_lp_hint', true );
		get_post_meta( $question_id, '_lp_explanation', true );
	}

	/**
	 * Returns the blanks of a fill in the blanks answer as stored.
	 *
	 * @param int $answer_id Answer ID.
	 * @return array<string, array>
	 */
	protected function storedBlanks( int $answer_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read as the plugin reads it.
		$value  = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE learnpress_question_answer_id = %d AND meta_key = %s', $wpdb->prefix . 'learnpress_question_answermeta', $answer_id, '_blanks' ) );
		$blanks = is_string( $value ) ? maybe_unserialize( $value ) : array();
		return is_array( $blanks ) ? $blanks : array();
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
			$texts[] = array(
				'o'     => $leaf['o'],
				'p'     => $leaf['p'],
				'v'     => $leaf['v'],
				'r'     => $leaf['r'],
				'kind'  => 'field/post_title' === $leaf['p'] || 1 === preg_match( '~/name$~', $leaf['p'] ) ? 'heading' : 'paragraph',
				'place' => $this->fieldLabel( $leaf['o'], $leaf['p'] ),
			);
		}
		return $texts;
	}

	/**
	 * Declares the texts of sections and answers as text: one word, as an answer "Mint", is one. The
	 * right answer of a blank is text only where it has a letter, see blank().
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return bool|null Null for everything else.
	 */
	public function fieldHoldsText( string $owner, string $path ): ?bool {
		if ( 0 !== strpos( $owner, 'post:' ) ) {
			return null;
		}
		$type = get_post_type( (int) substr( $owner, 5 ) );
		if ( 0 === strpos( $path, self::BASE . '/' ) ) {
			return in_array( $type, array( 'lp_course', 'lp_question' ), true ) ? true : null;
		}
		foreach ( array_merge( array( '_lp_hint', '_lp_explanation', '_lp_faqs' ), array_keys( self::PRICE_WORDS ), array_keys( self::COURSE_LISTS ) ) as $key ) {
			if ( 0 === strpos( $path, swiftling_meta_path( $key, 0 ) ) && in_array( $type, array( 'lp_course', 'lp_question' ), true ) ) {
				return true;
			}
		}
		return null;
	}

	/**
	 * Returns what the editor calls a text of a section, an answer, a hint or a field of a course, such
	 * as "Section: Herb basics › Name" or "Question: Which herbs are perennial? › Answer 2".
	 *
	 * @param string $owner Object key of the value.
	 * @param string $path  Path of the value inside its object.
	 * @return string Empty when the value is none of these.
	 */
	public function fieldLabel( string $owner, string $path ): string {
		if ( 0 !== strpos( $owner, 'post:' ) ) {
			return '';
		}
		$post = get_post( (int) substr( $owner, 5 ) );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'lp_course', 'lp_question' ), true ) ) {
			return '';
		}
		if ( 1 === preg_match( '~^' . self::BASE . '/section/(\d+)/(name|description)$~', $path, $found ) ) {
			$name = '';
			foreach ( $this->sections( (int) $post->ID ) as $section ) {
				if ( (int) $section['section_id'] === (int) $found[1] ) {
					$name = (string) $section['section_name'];
				}
			}
			/* translators: %s: name of a section of a course. */
			return sprintf( __( 'Section: %s', 'swiftling' ), $name ) . ' › ' . ( 'name' === $found[2] ? __( 'Name', 'swiftling' ) : __( 'Description', 'swiftling' ) );
		}
		/* translators: %s: title of a question of a quiz. */
		$question = sprintf( __( 'Question: %s', 'swiftling' ), $post->post_title );
		if ( 1 === preg_match( '~^' . self::BASE . '/answer/(\d+)/(title|blank/(.+))$~', $path, $found ) ) {
			$number   = 0;
			$sentence = false;
			foreach ( $this->answers( (int) $post->ID ) as $index => $row ) {
				if ( (int) $row['question_answer_id'] === (int) $found[1] ) {
					$number   = $index + 1;
					$sentence = false !== strpos( (string) $row['title'], '[fib' );
				}
			}
			if ( 'title' === $found[2] ) {
				/* translators: %d: number of an answer of a question, counted from one. */
				return $question . ' › ' . ( $sentence ? __( 'Sentence, with {{FIB_…}} for each blank', 'swiftling' ) : sprintf( __( 'Answer %d', 'swiftling' ), $number ) );
			}
			/* translators: %s: ID of a blank in a sentence. */
			return $question . ' › ' . sprintf( __( 'Right answer of blank %s', 'swiftling' ), rawurldecode( $found[3] ) );
		}
		$names = array(
			'_lp_hint'             => __( 'Hint', 'swiftling' ),
			'_lp_explanation'      => __( 'Explanation', 'swiftling' ),
			'_lp_requirements'     => __( 'Requirements', 'swiftling' ),
			'_lp_key_features'     => __( 'Key features', 'swiftling' ),
			'_lp_target_audiences' => __( 'Target audience', 'swiftling' ),
			'_lp_faqs'             => __( 'FAQs', 'swiftling' ),
			'_lp_price_prefix'     => __( 'Before the price', 'swiftling' ),
			'_lp_price_suffix'     => __( 'After the price', 'swiftling' ),
		);
		foreach ( $names as $key => $name ) {
			if ( 0 === strpos( $path, swiftling_meta_path( $key, 0 ) ) ) {
				return 'lp_question' === $post->post_type ? $question . ' › ' . $name : $name;
			}
		}
		return '';
	}
}
