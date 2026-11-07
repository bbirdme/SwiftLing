<?php
/**
 * A stand-in for the parts of wpDataTables that SwiftLing calls: the columns of a table, handed to the
 * plugin's filter, and its rows, read from its source, as wpDataTables 6.6 has them.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Squiz.Commenting, Generic.CodeAnalysis.UnusedFunctionParameter, Universal.Files.SeparateFunctionsFromOO -- The plugin's own names, in one file for the tests.

if ( ! class_exists( 'WPDataTable', false ) ) {
	class WDTConfigController {
		public static function loadColumnsFromDB( $table_id ) {
			return apply_filters( 'wpdatatables_filter_columns_metadata', array( (object) array( 'display_header' => 'Biljka' ) ), $table_id );
		}
	}

	class WPDataTable {
		public static function loadWpDataTable( $table_id ) {
			return new self();
		}

		public function getDataRows() {
			return array(
				array(
					'Biljka' => 'Bosiljak',
					'Cijena' => '3,50',
				),
			);
		}
	}
}
