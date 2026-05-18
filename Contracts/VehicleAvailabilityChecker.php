<?php

namespace App\Modules\Vehicle\Contracts;

/**
 * Public API the Vehicle module exposes for checking whether a vehicle
 * is free for a given time window. Used by Booking (and any other module)
 * before reserving a vehicle.
 *
 * Implementations must be called inside a DB::transaction() so that
 * lockForUpdate() inside the underlying query takes effect.
 *
 * Buffer time and per-day cap are NOT parameters — they live on the
 * vehicle itself and are resolved by the implementation via the
 * VehicleConstraints contract. Callers in other modules never need to
 * know how those values are stored.
 */
interface VehicleAvailabilityChecker
{
    /**
     * @param int    $vehicleId
     * @param string $date              Y-m-d
     * @param string $time              H:i or H:i:s
     * @param int    $duration          hours
     * @param int[]  $selectedVehicles  vehicle IDs to skip the check for
     *                                  (e.g. multi-vehicle campaign flow that
     *                                  has already reserved its own picks)
     */
    public function isAvailable(
        int $vehicleId,
        string $date,
        string $time,
        int $duration = 1,
        array $selectedVehicles = [],
    ): bool;
}
