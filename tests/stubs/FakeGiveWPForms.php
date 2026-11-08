<?php
/**
 * GiveWP's forms without the plugin, for the tests of their class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Forms\GiveWP;

/**
 * GiveWP's forms, without the plugin: a class that reads the data of its forms, as the plugin hands them
 * to its script, from a list.
 */
class FakeGiveWPForms extends GiveWP {
	/**
	 * The data of each form, by ID: its fields under "nodes", its settings under "settings".
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
	 * Returns the forms of the list.
	 */
	protected function allForms(): array {
		$forms = array();
		foreach ( $this->forms as $id => $form ) {
			$forms[] = array(
				'id'    => (int) $id,
				'title' => (string) ( $form['settings']['formTitle'] ?? '' ),
			);
		}
		return $forms;
	}

	/**
	 * Returns a form's data from the list.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function dataOf( int $form_id ): ?array {
		return $this->forms[ $form_id ] ?? null;
	}

	/**
	 * Whether the current user may edit forms: the test's administrator may.
	 */
	protected function mayEdit(): bool {
		return true;
	}
}
