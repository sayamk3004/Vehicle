<?php

namespace App\Modules\Vehicle\DTOs;

/**
 * Immutable view of a vehicle's availability constraints. Returned by
 * the VehicleConstraints contract so callers outside the Vehicle module
 * never need to read Eloquent attributes on the Vehicle model directly.
 */
final class VehicleConstraintsDTO
{
    public function __construct(
        public readonly int $bufferMinutes,
        public readonly ?int $maxBookingsPerDay,
    ) {}
}
