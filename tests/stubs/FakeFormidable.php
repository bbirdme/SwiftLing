<?php
/**
 * Formidable Forms without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Forms\Formidable;

/**
 * Formidable Forms, without the plugin: a class that reads its forms from a list and hands them to
 * the same filters the plugin calls while it shows a form.
 */
class FakeFormidable extends Formidable {
	/**
	 * Forms by ID: the form as the plugin's object, and its fields as the arrays it shows.
	 *
	 * @var array<int, array{form: object, fields: array}>
	 */
	public $forms = array();

	/**
	 * Actions by form ID, as the plugin's objects with their type in post_excerpt and their settings
	 * as an array.
	 *
	 * @var array<int, object[]>
	 */
	public $actions = array();

	/**
	 * The plugin's global settings.
	 *
	 * @var object|null
	 */
	public $settings = null;

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
				'title' => (string) $form['form']->name,
			);
		}
		return $forms;
	}

	/**
	 * Returns a form as the plugin's object.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formObject( int $form_id ) {
		return isset( $this->forms[ $form_id ] ) ? clone $this->forms[ $form_id ]['form'] : null;
	}

	/**
	 * Returns the fields of a form as the arrays the plugin shows.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function fieldsOf( int $form_id ): array {
		return isset( $this->forms[ $form_id ] ) ? $this->forms[ $form_id ]['fields'] : array();
	}

	/**
	 * Returns the actions of a form of a type.
	 *
	 * @param int    $form_id Form ID.
	 * @param string $type    Type of action, e.g. on_submit or email.
	 */
	protected function actionsOf( int $form_id, string $type ): array {
		$actions = array();
		foreach ( isset( $this->actions[ $form_id ] ) ? $this->actions[ $form_id ] : array() as $action ) {
			if ( $type === $action->post_excerpt ) {
				$actions[] = $action;
			}
		}
		return $actions;
	}

	/**
	 * Returns the plugin's global settings.
	 */
	protected function globalSettings() {
		return $this->settings;
	}
}
