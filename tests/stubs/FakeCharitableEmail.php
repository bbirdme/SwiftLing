<?php
/**
 * An email of Charitable, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * An email of Charitable, as the plugin's object answers what SwiftLing asks: which email it is, and the
 * donation it is about.
 */
class FakeCharitableEmail {
	/**
	 * The email, as "donation_receipt".
	 *
	 * @var string
	 */
	private $id;

	/**
	 * The donation, or null.
	 *
	 * @var object|null
	 */
	private $donation;

	/**
	 * Makes the email.
	 *
	 * @param string      $id       The email.
	 * @param object|null $donation The donation.
	 */
	public function __construct( $id, $donation = null ) {
		$this->id       = $id;
		$this->donation = $donation;
	}

	/**
	 * Returns which email it is.
	 */
	public function get_email_id() {
		return $this->id;
	}

	/**
	 * Returns the donation it is about.
	 */
	public function get_donation() {
		return $this->donation;
	}
}
