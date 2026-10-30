<?php
/**
 * LearnPress without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\LearnPress;

/**
 * LearnPress, without the plugin: active, with the post types and the tables of sections, quizzes
 * and answers the plugin makes, and everything else of the real class.
 */
class FakeLearnPress extends LearnPress {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the plugin's post types as LearnPress 4.4 does, visitors open each, and makes its
	 * tables with the columns the class reads; the test suite makes them temporary.
	 */
	public static function install(): void {
		register_post_type(
			'lp_course',
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'courses' ),
			)
		);
		foreach ( array( 'lp_lesson', 'lp_quiz', 'lp_question' ) as $type ) {
			register_post_type( $type, array( 'public' => true ) );
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The plugin's tables, made for a test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}learnpress_sections ( section_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, section_name varchar(255) NOT NULL DEFAULT '', section_course_id bigint(20) unsigned NOT NULL DEFAULT 0, section_order bigint(10) unsigned NOT NULL DEFAULT 1, section_description longtext, PRIMARY KEY (section_id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}learnpress_quiz_questions ( quiz_question_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, quiz_id bigint(20) unsigned NOT NULL DEFAULT 0, question_id bigint(20) unsigned NOT NULL DEFAULT 0, question_order bigint(10) unsigned NOT NULL DEFAULT 1, PRIMARY KEY (quiz_question_id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}learnpress_question_answers ( question_answer_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, question_id bigint(20) unsigned NOT NULL DEFAULT 0, title text, value varchar(32) NOT NULL DEFAULT '', `order` bigint(20) unsigned NOT NULL DEFAULT 1, is_true varchar(3) DEFAULT NULL, PRIMARY KEY (question_answer_id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}learnpress_question_answermeta ( meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, learnpress_question_answer_id bigint(20) unsigned NOT NULL, meta_key varchar(255) NOT NULL DEFAULT '', meta_value longtext, PRIMARY KEY (meta_id) )" );
		// phpcs:enable
	}
}
