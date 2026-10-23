<?php
/**
 * A screen another plugin adds to SwiftLing's menu, for HooksTest.
 *
 * @package SwiftLing
 */

defined( 'ABSPATH' ) || exit;

\SwiftLing\Admin\Ui::screenHeader(
	array(
		'crumb' => 'Settings',
		'title' => 'Glossary',
	)
);
?>
<p class="swiftling-test-added">A screen of another plugin.</p>
