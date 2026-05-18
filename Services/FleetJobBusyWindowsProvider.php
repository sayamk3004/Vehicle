<?php

namespace App\Modules\Vehicle\Services;

use App\Modules\Vehicle\Contracts\BusyWindowsProvider;
use App\Modules\Vehicle\DTOs\BusyWindow;
use App\Modules\Vehicle\Models\JobVehicle;
use Carbon\Carbon;

/**
 * Reports vehicle occupancy from the operator's fleet jobs (the
 * organizum DB's job_vehicles + job tables). Lives inside the Vehicle
 * module because the JobVehicle / Job models already belong here.
 */
class FleetJobBusyWindowsProvider implements BusyWindowsProvider
{
    public function windowsFor(
        array  $vehicleIds,
        string $fromDate,
        string $toDate,
        bool   $withLock = false,
    ): iterable {
        if (empty($vehicleIds)) {
            return [];
        }

        $q = JobVehicle::with(['job.jobable'])->whereIn('vehicle_id', $vehicleIds);
        if ($withLock) {
            $q->lockForUpdate();
        }

        $rows  = $q->get();
        $from  = Carbon::parse($fromDate)->startOfDay();
        $to    = Carbon::parse($toDate)->endOfDay();
        $out   = [];

        foreach ($rows as $jv) {
            $jobable = $jv->job?->jobable;
            if (!$jobable) continue;

            $date = $jobable->date ?? null;
            $time = $jobable->time ?? null;
            if (!$date || !$time) continue;

            $start = Carbon::parse("{$date} {$time}");
            if ($start->lt($from) || $start->gt($to)) continue;

            $end = (clone $start)->addHours(max(1, (int) ($jobable->duration ?? 1)));
            $out[] = new BusyWindow((int) $jv->vehicle_id, $start, $end, 'fleet_job');
        }
        return $out;
    }
}
