<?php
/**
 * What the Forms screen asks of a form plugin.
 *
 * @package SwiftLing
 */

declare( strict_types=1 );

namespace SwiftLing\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * A form plugin SwiftLing has a class for, wherever the plugin keeps its forms: as posts, see
 * Provider, or in tables of its own, see GravityForms. The Forms screen lists what these classes
 * say, and the translation editor opens a form under the subject key the class gives it.
 */
interface FormPlugin {
	/**
	 * Returns the plugin's name, as the screens show it.
	 */
	public function name(): string;

	/**
	 * Returns the forms of the plugin, each with the subject key its texts are translated under and
	 * how many pages show it. Empty when the plugin is not on the site.
	 *
	 * @return array<int, array{key: string, id: int, title: string, pages: int}>
	 */
	public function forms(): array;
}
