<?php

namespace App\Modules\Vehicle\Contracts;

use App\Modules\Vehicle\DTOs\VehicleConstraintsDTO;

/**
 * Single source of truth for a vehicle's availability constraints
 * (buffer time and per-day booking cap). Callers in other modules
 * use this instead of reading attributes off the Vehicle model so the
 * Vehicle module's schema can evolve without breaking them.
 */
interface VehicleConstraints
{
    /**
     * Resolve the constraints for a given vehicle. Unknown / soft-deleted
     * vehicles get the safe defaults (no buffer, no cap) so callers never
     * have to guard for missing rows.
     */
    public function for(int $vehicleId): VehicleConstraintsDTO;
}
