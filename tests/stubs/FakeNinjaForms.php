<?php
/**
 * Ninja Forms without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Forms\NinjaForms;

/**
 * Ninja Forms, without the plugin: a class that reads its forms from a list and hands their
 * settings and fields to the same filters the plugin calls while it shows a form.
 */
class FakeNinjaForms extends NinjaForms {
	/**
	 * Forms by ID: their settings and their fields, as the plugin keeps them.
	 *
	 * @var array<int, array{settings: array, fields: array}>
	 */
	public $forms = array();

	/**
	 * Actions by form ID: each its ID and its settings, as the plugin keeps them.
	 *
	 * @var array<int, array<int, array{id: int, settings: array}>>
	 */
	public $actions = array();

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
				'title' => (string) $form['settings']['title'],
			);
		}
		return $forms;
	}

	/**
	 * Returns the settings of a form, as the plugin's model gives them.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formSettings( int $form_id ): array {
		return $this->forms[ $form_id ]['settings'];
	}

	/**
	 * Returns the fields of a form, each its settings with its ID, as the plugin's models give them.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formFields( int $form_id ): array {
		return $this->forms[ $form_id ]['fields'];
	}

	/**
	 * Returns the actions of a form.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formActions( int $form_id ): array {
		return isset( $this->actions[ $form_id ] ) ? $this->actions[ $form_id ] : array();
	}
}
