<?php
/**
 * A stand-in for the parts of LatePoint that SwiftLing calls: its models, which hand themselves to
 * the plugin's filters when loaded, the meta of a booking, and its replacer of {{variables}}, which
 * hands what it wrote to its filter as LatePoint 5.7 does.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Generic.Classes.OpeningBraceSameLine, Squiz.Commenting, Universal.NamingConventions.NoReservedKeywordParameterNames -- The plugin's own names, in one file for the tests.

if ( ! class_exists( 'OsModel', false ) ) {
	class OsModel {
		public $id;

		/**
		 * Loads a model as the plugin does: from what the tests give it, handed to the plugin's filter.
		 *
		 * @param array $data Field => value.
		 * @return static
		 */
		public static function loaded( array $data ) {
			$model = new static();
			foreach ( $data as $key => $value ) {
				$model->$key = $value;
			}
			return apply_filters( 'latepoint_get_results_as_models', $model );
		}
	}
	class OsServiceModel extends OsModel {
		public $name;
		public $short_description;
		public $duration_name;
	}
	class OsAgentModel extends OsModel {
		public $first_name;
		public $bio;
	}
	class OsSettingsModel extends OsModel {
		public $name;
		public $value;
	}
	class OsBookingModel extends OsModel {
		public $service;
		public $meta = array();

		public function save_meta_by_key( $key, $value ) {
			$this->meta[ $key ] = $value;
			return true;
		}

		public function get_meta_by_key( $key, $default = false ) {
			return $this->meta[ $key ] ?? $default;
		}
	}
	class OsReplacerHelper {
		/**
		 * Writes a template: {{service_name}} and {{customer_full_name}} from the variables.
		 *
		 * @param string $text The template.
		 * @param array  $vars The variables.
		 * @return string
		 */
		public static function replace_all_vars( string $text, array $vars ): string {
			$original = $text;
			$text     = str_replace(
				array( '{{service_name}}', '{{customer_full_name}}' ),
				array( isset( $vars['booking']->service ) ? $vars['booking']->service->name : '', $vars['customer_full_name'] ?? '' ),
				$text
			);
			return apply_filters( 'latepoint_replace_all_vars_in_template', $text, $vars, $original );
		}
	}
}
