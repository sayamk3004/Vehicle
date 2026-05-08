<?php

namespace App\Modules\Vehicle\Services;

use App\Modules\Shared\Models\Vehicle;
use App\Modules\Shared\Models\JobVehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class VehicleAvailabilityService
{
    /**
     * Check if a vehicle is available for the requested date/time.
     *
     * Checks three sources of occupancy:
     *   1. Fleet job-vehicles (organizum DB)
     *   2. Customer private-tour bookings (bookinpro DB)
     *   3. Customer limousine bookings (bookinpro DB)
     *
     * Must be called inside a DB::transaction() so lockForUpdate() takes effect.
     *
     * @param int        $vehicleId
     * @param string     $date             Y-m-d
     * @param string     $time             H:i or H:i:s
     * @param int|null   $duration         hours (default 1)
     * @param array      $selectedVehicles skip check for these vehicle IDs (campaign flow)
     * @param int        $bufferMinutes    buffer time to enforce between bookings
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

        // --- Fleet job-vehicles (organizum DB) ---
        $engagedJobs = JobVehicle::with(['job.jobable'])
            ->where('vehicle_id', $vehicleId)
            ->lockForUpdate()
            ->get();

        // --- Customer private-tour bookings (bookinpro DB) ---
        // Vehicle may be tied via private_tours.vehicle_id (service default)
        // or via booking_private_tours.selected_vehicle_id (campaign funnel choice).
        $tourIds = DB::table('private_tours')
            ->where('vehicle_id', $vehicleId)
            ->pluck('id')
            ->toArray();

        $ptBookings = DB::table('booking_private_tours')
            ->join('bookings', 'bookings.id', '=', 'booking_private_tours.booking_id')
            ->where(function ($q) use ($vehicleId, $tourIds) {
                $q->whereIn('booking_private_tours.private_tour_id', $tourIds)
                  ->orWhere('booking_private_tours.selected_vehicle_id', $vehicleId);
            })
            ->whereNotIn('bookings.status', ['cancelled', 'rejected', 'completed', 'expired'])
            ->lockForUpdate()
            ->get(['booking_private_tours.date', 'booking_private_tours.time', 'booking_private_tours.hours']);

        // --- Customer limousine bookings (bookinpro DB) ---
        $limoServiceIds = DB::table('limousine_services')
            ->where('vehicle_id', $vehicleId)
            ->pluck('id')
            ->toArray();

        $limoBookings = collect();
        if (!empty($limoServiceIds)) {
            $limoBookings = DB::table('booking_limousines')
                ->join('bookings', 'bookings.id', '=', 'booking_limousines.booking_id')
                ->whereIn('booking_limousines.limousine_service_id', $limoServiceIds)
                ->whereNotIn('bookings.status', ['cancelled', 'rejected', 'completed', 'expired'])
                ->lockForUpdate()
                ->get([
                    'booking_limousines.date',
                    'booking_limousines.time',
                    'booking_limousines.hours',
                    'booking_limousines.return_date',
                    'booking_limousines.return_time',
                ]);
        }

        // --- Max bookings per day check (all sources combined) ---
        if ($maxBookingsPerDay !== null) {
            $fleetDayCount = $engagedJobs->filter(function ($jv) use ($date) {
                $jobable = $jv->job?->jobable;
                return $jobable && Carbon::parse($jobable->date)->toDateString() === $date;
            })->count();

            $ptDayCount = $ptBookings->filter(fn ($b) => $b->date === $date)->count();

            $limoDayCount = $limoBookings->filter(
                fn ($b) => ($b->date ?? null) === $date || ($b->return_date ?? null) === $date
            )->count();

            if (($fleetDayCount + $ptDayCount + $limoDayCount) >= $maxBookingsPerDay) {
                return false;
            }
        }

        // --- Overlap helper (buffer applied symmetrically around each existing booking) ---
        $overlaps = function (Carbon $existStart, Carbon $existEnd) use ($requestedStart, $requestedEnd, $bufferMinutes): bool {
            $windowStart = (clone $existStart)->subMinutes($bufferMinutes);
            $windowEnd   = (clone $existEnd)->addMinutes($bufferMinutes);
            return $requestedStart->lt($windowEnd) && $requestedEnd->gt($windowStart);
        };

        // Fleet jobs
        foreach ($engagedJobs as $jobVehicle) {
            $jobable = $jobVehicle->job?->jobable;
            if (!$jobable) {
                continue;
            }
            $s = Carbon::parse("{$jobable->date} {$jobable->time}");
            $e = (clone $s)->addHours(max(1, (int) ($jobable->duration ?? 1)));
            if ($overlaps($s, $e)) {
                return false;
            }
        }

        // Customer private-tour bookings
        foreach ($ptBookings as $b) {
            $s = Carbon::parse("{$b->date} {$b->time}");
            $e = (clone $s)->addHours(max(1, (int) ($b->hours ?? 1)));
            if ($overlaps($s, $e)) {
                return false;
            }
        }

        // Customer limousine bookings (outbound leg + optional return leg)
        foreach ($limoBookings as $b) {
            if ($b->date && $b->time) {
                $s = Carbon::parse("{$b->date} {$b->time}");
                $e = (clone $s)->addHours(max(1, (int) ($b->hours ?? 1)));
                if ($overlaps($s, $e)) {
                    return false;
                }
            }
            if ($b->return_date && $b->return_time) {
                $s = Carbon::parse("{$b->return_date} {$b->return_time}");
                $e = (clone $s)->addHours(max(1, (int) ($b->hours ?? 1)));
                if ($overlaps($s, $e)) {
                    return false;
                }
            }
        }

        return true;
    }
}
