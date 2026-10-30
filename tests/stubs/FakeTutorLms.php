<?php
/**
 * Tutor LMS without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\TutorLms;

/**
 * Tutor LMS, without the plugin: active, with the post types and the tables of questions and
 * answers the plugin makes, and everything else of the real class.
 */
class FakeTutorLms extends TutorLms {
	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Registers the plugin's post types as Tutor LMS 4.2 does: visitors open a course, a lesson and a
	 * quiz, never a topic or an announcement. Makes the plugin's tables of questions and answers,
	 * and of carts and orders, with the columns it reads; the test suite makes them temporary.
	 */
	public static function install(): void {
		register_post_type(
			'courses',
			array(
				'public'   => true,
				'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
				'rewrite'  => array( 'slug' => 'courses' ),
			)
		);
		register_post_type( 'lesson', array( 'public' => true ) );
		register_post_type(
			'tutor_quiz',
			array(
				'public'  => true,
				'show_ui' => false,
			)
		);
		register_post_type( 'topics', array( 'public' => false ) );
		register_post_type( 'tutor_announcements', array( 'public' => false ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- The plugin's tables, made for a test.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tutor_quiz_questions ( question_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, quiz_id bigint(20) unsigned DEFAULT NULL, question_title text, question_description longtext, answer_explanation longtext, question_type varchar(50) DEFAULT NULL, question_mark decimal(9,2) DEFAULT NULL, question_settings longtext, question_order int(11) DEFAULT NULL, PRIMARY KEY (question_id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tutor_quiz_question_answers ( answer_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, belongs_question_id bigint(20) unsigned DEFAULT NULL, belongs_question_type varchar(250) DEFAULT NULL, answer_title text, is_correct tinyint(4) DEFAULT NULL, image_id bigint(20) DEFAULT NULL, answer_two_gap_match text, answer_view_format varchar(250) DEFAULT NULL, answer_settings text, answer_order int(11) DEFAULT 0, PRIMARY KEY (answer_id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tutor_carts ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, user_id bigint(20) unsigned NOT NULL, coupon_code varchar(50) DEFAULT NULL, PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tutor_cart_items ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, cart_id bigint(20) unsigned NOT NULL, course_id bigint(20) unsigned NOT NULL, PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tutor_order_items ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, order_id bigint(20) unsigned NOT NULL, item_id bigint(20) unsigned NOT NULL, PRIMARY KEY (id) )" );
		// phpcs:enable
	}
}
