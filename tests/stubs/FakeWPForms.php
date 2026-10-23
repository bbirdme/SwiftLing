<?php
/**
 * WPForms without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Forms\WPForms;

/**
 * WPForms, without the plugin: a class that reads its forms from a list and hands each to the same
 * filter the plugin calls while it shows a form.
 */
class FakeWPForms extends WPForms {
	/**
	 * Forms by ID, decoded, as the plugin keeps them in the content of their posts.
	 *
	 * @var array<int, array>
	 */
	public $forms = array();

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Returns every form.
	 */
	protected function allForms(): array {
		$forms = array();
		foreach ( $this->forms as $id => $form ) {
			$forms[] = array(
				'id'    => (int) $id,
				'title' => (string) $form['settings']['form_title'],
			);
		}
		return $forms;
	}

	/**
	 * Returns a form, decoded, as the plugin keeps it.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formData( int $form_id ): ?array {
		return isset( $this->forms[ $form_id ] ) ? $this->forms[ $form_id ] : null;
	}
}
