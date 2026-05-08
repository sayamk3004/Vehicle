<?php

namespace App\Modules\Vehicle\Services;

use App\Modules\Shared\Models\Vehicle;
use App\Modules\Shared\Models\JobVehicle;
use Carbon\Carbon;

class VehicleAvailabilityService
{
    /**
     * Check if a vehicle is available for the requested date/time.
     *
     * Must be called inside a DB::transaction() so lockForUpdate() takes effect.
     *
     * @param int        $vehicleId
     * @param string     $date             Y-m-d
     * @param string     $time             H:i or H:i:s
     * @param int|null   $duration         hours (default 1)
     * @param array      $selectedVehicles skip check for these vehicle IDs (campaign flow)
     * @param int        $bufferMinutes    buffer time to enforce between bookings on the same day
     * @param int|null   $maxBookingsPerDay null = unlimited
     */
    public function isAvailable(
        int $vehicleId,
        string $date,
        string $time,
        ?int $duration = 1,
        array $selectedVehicles = [],
        int $bufferMinutes = 0,
        ?int $maxBookingsPerDay = null
    ): bool {
        if (in_array($vehicleId, $selectedVehicles)) {
            return true;
        }

        if (!Vehicle::find($vehicleId)) {
            return false;
        }

        $requestedStart = Carbon::parse("$date $time");
        $requestedEnd   = (clone $requestedStart)->addHours(max(1, (int) $duration));

        // Lock existing job-vehicle rows so concurrent requests block until this transaction commits.
        $engagedJobs = JobVehicle::with(['job.jobable'])
            ->where('vehicle_id', $vehicleId)
            ->lockForUpdate()
            ->get();

        // Max bookings per day — count how many jobs exist for this vehicle on the requested date.
        if ($maxBookingsPerDay !== null) {
            $dayCount = $engagedJobs->filter(function ($jv) use ($date) {
                $jobable = $jv->job?->jobable;
                return $jobable && Carbon::parse($jobable->date)->toDateString() === $date;
            })->count();

            if ($dayCount >= $maxBookingsPerDay) {
                return false;
            }
        }

        // Overlap check with optional buffer applied around each existing booking.
        foreach ($engagedJobs as $jobVehicle) {
            $jobable = $jobVehicle->job?->jobable;
            if (!$jobable) {
                continue;
            }

            $jobStart = Carbon::parse("{$jobable->date} {$jobable->time}")
                ->subMinutes($bufferMinutes);
            $jobEnd   = Carbon::parse("{$jobable->date} {$jobable->time}")
                ->addHours($jobable->duration ?? 1)
                ->addMinutes($bufferMinutes);

            if ($requestedStart->lt($jobEnd) && $requestedEnd->gt($jobStart)) {
                return false;
            }
        }

        return true;
    }
}
