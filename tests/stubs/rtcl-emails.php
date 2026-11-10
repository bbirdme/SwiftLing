<?php
/**
 * Stand-ins for the classes of Classified Listing's emails and ads, for the tests of its class:
 * shaped as the plugin's.
 *
 * @package SwiftLing
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Squiz.Commenting, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, PEAR.NamingConventions.ValidClassName -- The plugin's own names.

namespace Rtcl\Emails;

if ( ! class_exists( __NAMESPACE__ . '\\ListingPublishedEmailToOwner', false ) ) {
	/**
	 * Classified Listing's email to an ad's owner that the ad is published.
	 */
	class ListingPublishedEmailToOwner {
		public $object;
	}

	/**
	 * Classified Listing's email to the site's owner about a message to an ad.
	 */
	class ListingContactEmailToAdmin {
		public $object;
	}

	/**
	 * Classified Listing's email to a visitor who registered.
	 */
	class UserNewRegistrationEmailToUser {
		public $object;
	}

	/**
	 * Classified Listing's object of an ad.
	 */
	class Listing {
		private $id;

		public function __construct( $id ) {
			$this->id = $id;
		}

		public function get_id() {
			return $this->id;
		}
	}
}
