<?php
/**
 * A stand-in for the senders of Bookly's emails, so SwiftLing's reading of who an email goes to can be
 * tested: the plugin's code that notifies the customer and the staff, each asking for its texts by
 * name, as Bookly 28.7 does.
 *
 * @package SwiftLing
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Squiz.Commenting, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter -- The plugin's own names and arguments.

namespace Bookly\Lib\Notifications\Booking;

if ( ! class_exists( __NAMESPACE__ . '\\BaseSender', false ) ) {
	class CustomerAppointment {
		public $locale;

		public function __construct( $locale ) {
			$this->locale = $locale;
		}

		public function getLocale() {
			return $this->locale;
		}
	}

	class Item {
		public $ca;

		public function __construct( $locale ) {
			$this->ca = new CustomerAppointment( $locale );
		}

		public function getCA() {
			return $this->ca;
		}
	}

	class BaseSender {
		/**
		 * Asks for the texts of an email to the customer of a booking, as the plugin does.
		 *
		 * @param array $names Name => text.
		 * @param Item  $item  The booking.
		 * @return array
		 */
		public static function notifyClient( array $names, Item $item ) {
			return self::texts( $names );
		}

		/**
		 * Asks for the texts of an email to the staff, as the plugin does.
		 *
		 * @param array $names Name => text.
		 * @param Item  $item  The booking.
		 * @return array
		 */
		public static function notifyStaffAndAdmins( array $names, Item $item ) {
			return self::texts( $names );
		}

		/**
		 * Returns texts through the filter the plugin hands them to.
		 *
		 * @param array $names Name => text.
		 * @return array
		 */
		private static function texts( array $names ) {
			foreach ( $names as $name => $text ) {
				$names[ $name ] = apply_filters( 'wpml_translate_single_string', $text, 'bookly', $name, null );
			}
			return $names;
		}
	}
}
