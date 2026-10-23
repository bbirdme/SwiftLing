<?php
/**
 * Gravity Forms without the plugin, for the tests of its provider.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Forms\GravityForms;

/**
 * Gravity Forms, without the plugin: a provider that reads its forms from a list, and runs the
 * form through the same filter the plugin does when it has read one.
 */
class FakeGravityForms extends GravityForms {
	/**
	 * Forms by ID, as the plugin would give them.
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
		foreach ( $this->forms as $form ) {
			$forms[] = array(
				'id'    => (int) $form['id'],
				'title' => (string) $form['title'],
			);
		}
		return $forms;
	}

	/**
	 * Reads a form as the plugin does, which ends in the filter.
	 *
	 * @param int  $form_id Form ID.
	 * @param bool $fresh   Whether to read it again; the fake keeps no copy.
	 */
	protected function readForm( int $form_id, bool $fresh = false ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The real one reads from a cache a fake does not have.
		return isset( $this->forms[ $form_id ] ) ? $this->fresh( $form_id ) : null;
	}

	/**
	 * Returns a form as the plugin gives it each time it has read it from its tables: new field
	 * objects every time, not the ones of an earlier read, run through the filter.
	 *
	 * @param int $form_id Form ID.
	 */
	public function fresh( int $form_id ): array {
		$form = $this->forms[ $form_id ];
		foreach ( $form['fields'] as $index => $field ) {
			$form['fields'][ $index ] = is_object( $field ) ? clone $field : $field;
		}
		return $this->filterForm( $form );
	}
}
