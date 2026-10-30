<?php
/**
 * Where Bricks keeps what it read of its global data, without the theme.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

/**
 * Bricks\Database, without the theme: only what Bricks read of its global data, such as its
 * components, as Database::get_global_data() in includes/database.php fills it.
 */
class FakeBricksDatabase {
	/**
	 * Global data, under Bricks' name for it.
	 *
	 * @var array
	 */
	public static $global_data = array(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase -- Bricks' name.
}

if ( ! class_exists( 'Bricks\Database' ) ) {
	class_alias( FakeBricksDatabase::class, 'Bricks\Database' );
}
