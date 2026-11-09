<?php
/**
 * The cache of NextGEN Gallery without the plugin, for the tests of its class: notes what is emptied.
 *
 * @package SwiftLing
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- The plugin's own names.

namespace Imagely\NGG\Util;

/**
 * NextGEN Gallery's cache of what it printed.
 */
class Transient {
	/**
	 * The groups emptied.
	 *
	 * @var array
	 */
	public static $flushed = array();

	/**
	 * Empties a group of the cache.
	 *
	 * @param string|null $group The group.
	 */
	public static function flush( $group = null ) {
		self::$flushed[] = $group;
	}
}
