<?php
/**
 * Simply Schedule Appointments without the plugin, for the tests of its class.
 *
 * @package SwiftLing
 */

namespace SwiftLing\Tests;

use SwiftLing\Builders\SimplyScheduleAppointments;

require_once __DIR__ . '/ssa.php';

/**
 * Simply Schedule Appointments, without the plugin: active, with the locales its appointments were
 * booked in, and everything else of the real class.
 */
class FakeSimplyScheduleAppointments extends SimplyScheduleAppointments {
	/**
	 * The locale of each appointment, by ID.
	 *
	 * @var array<int, string>
	 */
	public $locales = array();

	/**
	 * Whether the plugin counts as active.
	 */
	public function isActive(): bool {
		return true;
	}

	/**
	 * Returns the locale an appointment was booked in.
	 *
	 * @param int $appointment The appointment's ID.
	 */
	protected function customerLocale( int $appointment ): string {
		return $this->locales[ $appointment ] ?? '';
	}
}
