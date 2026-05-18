<?php

namespace App\Modules\Vehicle\Contracts;

use App\Modules\Vehicle\DTOs\BusyWindow;

/**
 * Every module that occupies a vehicle (private tour, limousine, fleet
 * job, future booking types) ships an implementation of this contract
 * and tags it 'busy_windows_providers' in its service provider.
 *
 * The Vehicle module's availability + slot-suggestion services iterate
 * over all tagged providers and concatenate results, so adding a new
 * occupant type requires only a new provider — no edits to Vehicle.
 */
interface BusyWindowsProvider
{
    /**
     * Return the time windows during which any of $vehicleIds is occupied
     * within [$fromDate, $toDate] (inclusive).
     *
     * @param int[]  $vehicleIds
     * @param string $fromDate  Y-m-d (inclusive)
     * @param string $toDate    Y-m-d (inclusive)
     * @param bool   $withLock  true when called inside a booking transaction
     *                          (provider must lockForUpdate so concurrent
     *                          bookings can't race the slot); false from
     *                          read-only slot-suggestion paths.
     *
     * @return iterable<BusyWindow>
     */
    public function windowsFor(
        array  $vehicleIds,
        string $fromDate,
        string $toDate,
        bool   $withLock = false,
    ): iterable;
}
