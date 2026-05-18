<?php

namespace App\Modules\Vehicle\Services;

use App\Modules\Vehicle\Contracts\BusyWindowsProvider;
use App\Modules\Vehicle\Contracts\VehicleAvailabilityChecker;
use App\Modules\Vehicle\Contracts\VehicleConstraints;
use App\Modules\Vehicle\Models\Vehicle;
use Carbon\Carbon;

class VehicleAvailabilityService implements VehicleAvailabilityChecker
{
    /**
     * @param iterable<BusyWindowsProvider> $busyProviders  Every module that
     *   occupies a vehicle (private tour, limousine, fleet job, future
     *   campaign types) registers a BusyWindowsProvider tagged
     *   'busy_windows_providers'. The service composes their results, so a
     *   new booking source plugs in without ever editing this file.
     */
    public function __construct(
        private readonly VehicleConstraints $constraints,
        private readonly iterable           $busyProviders,
    ) {}

    public function isAvailable(
        int    $vehicleId,
        string $date,
        string $time,
        int    $duration = 1,
        array  $selectedVehicles = [],
    ): bool {
        if (in_array($vehicleId, $selectedVehicles, true)) {
            return true;
        }
        if (!Vehicle::find($vehicleId)) {
            return false;
        }

        $constraints   = $this->constraints->for($vehicleId);
        $buffer        = $constraints->bufferMinutes;
        $cap           = $constraints->maxBookingsPerDay;

        $requestedStart = Carbon::parse("$date $time");
        $requestedEnd   = (clone $requestedStart)->addHours(max(1, $duration));

        // Pull every occupied window across every registered source. Each
        // provider locks its own rows for update so a concurrent booking
        // can't race in between the check and the parent transaction's
        // insert. Adding a new booking type means writing a new provider
        // — no edits here.
        $windows = [];
        $sameDayCount = 0;
        foreach ($this->busyProviders as $provider) {
            foreach ($provider->windowsFor([$vehicleId], $date, $date, withLock: true) as $w) {
                $windows[] = $w;
                if ($w->start->toDateString() === $date) {
                    $sameDayCount++;
                }
            }
        }

        if ($cap !== null && $sameDayCount >= $cap) {
            return false;
        }

        foreach ($windows as $w) {
            $bufferedStart = (clone $w->start)->subMinutes($buffer);
            $bufferedEnd   = (clone $w->end)->addMinutes($buffer);
            if ($requestedStart->lt($bufferedEnd) && $requestedEnd->gt($bufferedStart)) {
                return false;
            }
        }

        return true;
    }
}
