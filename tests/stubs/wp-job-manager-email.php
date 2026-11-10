<?php
/**
 * Stand-ins for the classes of WP Job Manager's emails, for the tests of its class: shaped as the
 * plugin's.
 *
 * @package SwiftLing
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Squiz.Commenting, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter, PEAR.NamingConventions.ValidClassName -- The plugin's own names.

if ( ! class_exists( 'WP_Job_Manager_Email', false ) ) {
	/**
	 * WP Job Manager's email: what it is about, private to the base class.
	 */
	abstract class WP_Job_Manager_Email {
		private $args = array();

		public function __construct( $args, $settings ) {
			if ( isset( $args['job_id'] ) ) {
				$args['job'] = get_post( $args['job_id'] );
			}
			$this->args = $args;
		}
	}

	/**
	 * WP Job Manager's notice to an employer that a listing expires.
	 */
	class WP_Job_Manager_Email_Employer_Expiring_Job extends WP_Job_Manager_Email {
		public static function get_key() {
			return 'employer_expiring_job';
		}
	}

	/**
	 * WP Job Manager's notice to the site's owner of a new listing.
	 */
	class WP_Job_Manager_Email_Admin_New_Job extends WP_Job_Manager_Email {
		public static function get_key() {
			return 'admin_new_job';
		}
	}
}
