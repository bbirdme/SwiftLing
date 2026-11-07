<?php
/**
 * A stand-in for the part of TablePress that SwiftLing calls: its model of tables, which lists them and
 * loads one, as TablePress 3.4 does.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Squiz.Commenting, Generic.CodeAnalysis.UnusedFunctionParameter -- The plugin's own names, in one file for the tests.

if ( ! class_exists( 'TablePress', false ) ) {
	class TablePress_Test_Model {
		public function load_all( $prime = true ) {
			return array_keys( $GLOBALS['tablepress_test_tables'] ?? array() );
		}

		public function load( $id, $data = true, $options = true ) {
			$table = $GLOBALS['tablepress_test_tables'][ $id ] ?? null;
			if ( null === $table ) {
				return new WP_Error( 'none' );
			}
			if ( ! $data ) {
				unset( $table['data'] );
			}
			return $table;
		}
	}

	abstract class TablePress {
		public static function load_model( $model ) {
			return new TablePress_Test_Model();
		}
	}
}
