<?php
/**
 * A stand-in for the parts of Simply Schedule Appointments that SwiftLing calls: ssa() with the
 * plugin's appointment types and its renderer of email templates, which here fills each
 * {{ name }} with its value. Each test sets what it needs on swiftling_test_ssa().
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- The plugin's own names, in one file for the tests.

/**
 * The plugin's main object, as the tests set it up.
 */
class SwiftLing_Test_Ssa {
	/**
	 * The renderer of email templates.
	 *
	 * @var SwiftLing_Test_Ssa_Notifications
	 */
	public $notifications;

	/**
	 * The model of appointment types.
	 *
	 * @var SwiftLing_Test_Ssa_Types
	 */
	public $appointment_type_model;

	/**
	 * Makes the object.
	 */
	public function __construct() {
		$this->notifications          = new SwiftLing_Test_Ssa_Notifications();
		$this->appointment_type_model = new SwiftLing_Test_Ssa_Types();
	}
}

/**
 * The plugin's renderer of email templates: fills {{ name }} with the value of name.
 */
class SwiftLing_Test_Ssa_Notifications {
	/**
	 * Returns a template written for an appointment.
	 *
	 * @param object $appointment The appointment.
	 * @param string $template    The template.
	 * @param array  $vars        The values of its placeholders.
	 * @return string
	 */
	public function get_rendered_template_string_for_appointment( $appointment, $template, $vars = array() ) {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-z_]+)\s*\}\}/',
			static function ( $found ) use ( $vars ) {
				return isset( $vars[ $found[1] ] ) ? (string) $vars[ $found[1] ] : '';
			},
			$template
		);
	}
}

/**
 * The plugin's model of appointment types.
 */
class SwiftLing_Test_Ssa_Types {
	/**
	 * The types, by field.
	 *
	 * @var array
	 */
	public $types = array();

	/**
	 * Returns the types.
	 *
	 * @return array
	 */
	public function query() {
		return $this->types;
	}
}

if ( ! function_exists( 'ssa' ) ) {
	/**
	 * Returns the plugin's main object.
	 *
	 * @return SwiftLing_Test_Ssa
	 */
	function ssa() {
		return swiftling_test_ssa();
	}
}

/**
 * Returns the plugin's main object of the tests, made once.
 *
 * @return SwiftLing_Test_Ssa
 */
function swiftling_test_ssa() {
	static $ssa = null;
	if ( null === $ssa ) {
		$ssa = new SwiftLing_Test_Ssa();
	}
	return $ssa;
}
