<?php
/**
 * Fluent Forms without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Forms\FluentForms;

/**
 * Fluent Forms, without the plugin: a class that reads its forms from a list and hands each to the
 * same filter the plugin calls while it shows a form.
 */
class FakeFluentForms extends FluentForms {
	/**
	 * Forms by ID: their title and their fields, decoded, as the plugin keeps them.
	 *
	 * @var array<int, array{title: string, fields: array}>
	 */
	public $forms = array();

	/**
	 * Settings of the forms by ID, decoded, as the plugin keeps them under formSettings.
	 *
	 * @var array<int, array>
	 */
	public $settings = array();

	/**
	 * Emails of the forms by ID: each its ID and its settings, decoded.
	 *
	 * @var array<int, array<int, array{id: int, settings: array}>>
	 */
	public $feeds = array();

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
				'title' => (string) $form['title'],
			);
		}
		return $forms;
	}

	/**
	 * Returns the fields of a form, decoded, as the plugin keeps them.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formFields( int $form_id ): ?array {
		return isset( $this->forms[ $form_id ] ) ? $this->forms[ $form_id ]['fields'] : null;
	}

	/**
	 * Returns a form as the plugin hands it to the filter: an object with its ID and its fields.
	 *
	 * @param int $form_id Form ID.
	 */
	public function shown( int $form_id ) {
		return $this->filterForm(
			(object) array(
				'id'     => $form_id,
				'title'  => $this->forms[ $form_id ]['title'],
				'fields' => $this->forms[ $form_id ]['fields'],
			)
		);
	}

	/**
	 * Returns the settings of a form.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function formSettings( int $form_id ): ?array {
		return isset( $this->settings[ $form_id ] ) ? $this->settings[ $form_id ] : null;
	}

	/**
	 * Returns the emails of a form.
	 *
	 * @param int $form_id Form ID.
	 */
	protected function notificationFeeds( int $form_id ): array {
		return isset( $this->feeds[ $form_id ] ) ? $this->feeds[ $form_id ] : array();
	}

	/**
	 * Fills in the tags of a text as the plugin does, for {inputs.name} alone.
	 *
	 * @param string     $text     Text with tags.
	 * @param int|string $entry_id Entry ID.
	 * @param array      $data     What the visitor sent.
	 * @param mixed      $form     The form.
	 */
	protected function fillTags( string $text, $entry_id, array $data, $form ): string {
		return (string) preg_replace_callback(
			'/\{inputs\.([a-z_]+)\}/',
			static function ( $found ) use ( $data ) {
				return isset( $data[ $found[1] ] ) ? (string) $data[ $found[1] ] : '';
			},
			$text
		);
	}
}
