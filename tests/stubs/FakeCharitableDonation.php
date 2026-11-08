<?php
/**
 * A donation of Charitable, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A donation of Charitable: its ID and the rows of its campaigns, as the plugin keeps them, with the
 * names the campaigns had when it was made.
 */
class FakeCharitableDonation {
	/**
	 * The donation's ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * The rows of its campaigns.
	 *
	 * @var object[]
	 */
	private $records;

	/**
	 * Makes the donation.
	 *
	 * @param int      $id      ID.
	 * @param object[] $records Rows of its campaigns.
	 */
	public function __construct( $id, array $records = array() ) {
		$this->ID      = $id;
		$this->records = $records;
	}

	/**
	 * Returns the rows of its campaigns, the same objects each time, as the plugin keeps them.
	 */
	public function get_campaign_donations() {
		return $this->records;
	}
}
