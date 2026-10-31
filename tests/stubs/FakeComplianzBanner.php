<?php
/**
 * A cookie banner of Complianz without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * A banner of Complianz: a row of the plugin's table, whose texts it hands to its template through a
 * filter, as Complianz 7.5 does in CMPLZ_COOKIEBANNER::get_html_settings().
 */
class FakeComplianzBanner {
	/**
	 * The banner's ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * The banner's row: texts as stored, some as an array with "text" and "show".
	 *
	 * @var array
	 */
	public $row;

	/**
	 * Makes a banner.
	 *
	 * @param int   $id  The banner's ID.
	 * @param array $row The banner's row.
	 */
	public function __construct( $id, $row ) {
		$this->ID  = $id;
		$this->row = $row;
	}

	/**
	 * Returns the banner's texts and settings for its template.
	 *
	 * @return array
	 */
	public function get_html_settings() {
		return apply_filters( 'cmplz_cookiebanner_settings_html', $this->row, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The plugin's own filter, as it runs it.
	}
}
