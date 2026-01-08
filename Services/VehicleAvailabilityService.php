<?php

namespace App\Modules\Vehicle\Services;


use App\Modules\Shared\Models\Vehicle;
use App\Modules\Shared\Models\JobVehicle;
use Carbon\Carbon;

class VehicleAvailabilityService
{
    /**
     * Check if a vehicle is available for a requested date/time
     *
     * @param int $vehicleId
     * @param string $date (Y-m-d)
     * @param string $time (H:i:s)
     * @param string|null $timezone
     * @param int|null $duration in hours
     * @return bool
     */
    public function isAvailable(int $vehicleId, string $date, string $time, ?int $duration = 1, array $selectedVehicles = []): bool
    {
        if (in_array($vehicleId, $selectedVehicles)) {
            return true;
        }

        $requestedStart = Carbon::parse("$date $time");
        $requestedEnd = (clone $requestedStart)->addHours($duration);

        $vehicle = Vehicle::findOrfail($vehicleId);
        if (!$vehicle) return false;

        $engagedJobs = JobVehicle::with(['job'])
            ->where('vehicle_id', $vehicleId)
            ->get();

        foreach ($engagedJobs as $jobVehicle) {
            $jobable = $jobVehicle->job->jobable;
            if (!$jobable) continue;

            $jobStart = Carbon::parse("{$jobable->date} {$jobable->time}");
            $jobEnd = (clone $jobStart)->addHours($jobable->duration ?? 1);

            if ($requestedStart->lt($jobEnd) && $requestedEnd->gt($jobStart)) {
                return false;
            }
        }

        return true;
    }
}
