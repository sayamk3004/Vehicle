<?php

namespace App\Modules\Vehicle\Contracts;

/**
 * Public API the Vehicle module exposes for checking whether a vehicle
 * is free for a given time window. Used by Booking (and any other module)
 * before reserving a vehicle.
 *
 * Implementations must be called inside a DB::transaction() so that
 * lockForUpdate() inside the underlying query takes effect.
 */
interface VehicleAvailabilityChecker
{
    /**
     * @param int      $vehicleId
     * @param string   $date              Y-m-d
     * @param string   $time              H:i or H:i:s
     * @param int|null $duration          hours (default 1)
     * @param array    $selectedVehicles  vehicle IDs to skip check for (campaign flow)
     * @param int      $bufferMinutes     buffer enforced symmetrically around existing bookings
     * @param int|null $maxBookingsPerDay null = unlimited
     */
    public function isAvailable(
        int $vehicleId,
        string $date,
        string $time,
        ?int $duration = 1,
        array $selectedVehicles = [],
        int $bufferMinutes = 0,
        ?int $maxBookingsPerDay = null
    ): bool;
}
