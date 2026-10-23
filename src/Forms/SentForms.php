<?php
/**
 * What the form plugins share about a form a visitor sends: whom an email writes to, and the
 * emails to the site's owner sent in the original.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * A form sent from a page in another language is answered in that language, see
 * swiftling_sent_form_language(): its message, its errors and the email to the visitor. An email
 * to the site's owner is not the visitor's, so it is built and sent in the original, texts and
 * locale, see swiftling_pause_translation(). Whether an email is the visitor's is decided from the
 * addresses it is sent to: every one of them taken from what the visitor sent.
 */
trait SentForms {
	/**
	 * How many emails to the site's owner this class is sending in the original now.
	 *
	 * @var int
	 */
	private $owner_mails = 0;

	/**
	 * Whether an email writes to the visitor alone: every address in its list of recipients, split
	 * at commas and semicolons, is one the visitor gave, such as a field's tag. An empty list is not.
	 *
	 * @param string   $to         The list of recipients, as the plugin keeps it.
	 * @param callable $is_visitor Whether one recipient is the visitor's: called with it, trimmed.
	 */
	protected static function onlyVisitors( string $to, callable $is_visitor ): bool {
		$recipients = array_filter( array_map( 'trim', (array) preg_split( '/[,;]+/', $to ) ) );
		if ( ! $recipients ) {
			return false;
		}
		foreach ( $recipients as $recipient ) {
			if ( ! $is_visitor( $recipient ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns what code reads in the original, in a request in another language: stored values
	 * untranslated, see swiftling_pause_translation(). Elsewhere it reads them as they are.
	 *
	 * @param callable $read Code that reads.
	 * @return mixed What it returns.
	 */
	protected static function inOriginal( callable $read ) {
		if ( 'translate' !== swiftling_mode() ) {
			return $read();
		}
		swiftling_pause_translation();
		try {
			return $read();
		} finally {
			swiftling_resume_translation();
		}
	}

	/**
	 * Starts sending an email to the site's owner in the original, until endOwnerMails(). Only in a
	 * request in another language; elsewhere it is in the original anyway.
	 */
	protected function startOwnerMail(): void {
		if ( 'translate' !== swiftling_mode() ) {
			return;
		}
		swiftling_pause_translation();
		++$this->owner_mails;
	}

	/**
	 * Ends the emails to the site's owner this class started, so the request goes on in its language.
	 */
	protected function endOwnerMails(): void {
		while ( $this->owner_mails > 0 ) {
			swiftling_resume_translation();
			--$this->owner_mails;
		}
	}
}
