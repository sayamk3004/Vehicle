<?php

namespace App\Modules\Vehicle\Services;

use App\Modules\Vehicle\Contracts\VehicleConstraints;
use App\Modules\Vehicle\DTOs\VehicleConstraintsDTO;
use App\Modules\Vehicle\Models\Vehicle;

class VehicleConstraintsService implements VehicleConstraints
{
    public function for(int $vehicleId): VehicleConstraintsDTO
    {
        $vehicle = Vehicle::find($vehicleId);

        return new VehicleConstraintsDTO(
            bufferMinutes:     (int) ($vehicle->buffer_time_minutes ?? 0),
            maxBookingsPerDay: $vehicle && $vehicle->max_bookings_per_day !== null
                ? (int) $vehicle->max_bookings_per_day
                : null,
        );
    }
}
