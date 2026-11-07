<?php
/**
 * A stand-in for the part of Simple Membership that SwiftLing calls: its settings, read once into an
 * object of its own, as Simple Membership 4.8 keeps them.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Squiz.Commenting -- The plugin's own names, for the tests.

if ( ! class_exists( 'SwpmSettings', false ) ) {
	class SwpmSettings {
		private static $instance = null;
		private $settings;

		private function __construct() {
			$this->settings = (array) get_option( 'swpm-settings' );
		}

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new SwpmSettings();
			}
			return self::$instance;
		}

		public static function forget() {
			self::$instance = null;
		}

		public function get_value( $key, $fallback = '' ) {
			return $this->settings[ $key ] ?? $fallback;
		}

		public function set_value( $key, $value ) {
			$this->settings[ $key ] = $value;
			return $this;
		}
	}
}
