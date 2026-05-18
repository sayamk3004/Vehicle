<?php

namespace App\Modules\Vehicle\DTOs;

use Carbon\Carbon;

/**
 * A single occupied time window on a vehicle, returned by any
 * BusyWindowsProvider. Sources vary (private-tour booking, limousine,
 * fleet job, etc.) but the shape stays the same so the availability
 * and slot-suggestion services can treat them uniformly.
 */
final class BusyWindow
{
    public function __construct(
        public readonly int    $vehicleId,
        public readonly Carbon $start,
        public readonly Carbon $end,
        /** Where this window came from — handy for logging, never for branching. */
        public readonly string $source,
    ) {}
}
