<?php

namespace App\Modules\Vehicle\Providers;

use App\Modules\Vehicle\Contracts\BusyWindowsProvider;
use App\Modules\Vehicle\Contracts\VehicleAvailabilityChecker;
use App\Modules\Vehicle\Contracts\VehicleConstraints;
use App\Modules\Vehicle\Services\FleetJobBusyWindowsProvider;
use App\Modules\Vehicle\Services\VehicleAvailabilityService;
use App\Modules\Vehicle\Services\VehicleConstraintsService;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VehicleConstraints::class, VehicleConstraintsService::class);

        // The Vehicle module's own contribution to vehicle-occupancy queries —
        // the operator's scheduled fleet jobs. Other modules add more providers
        // under the same tag from their own service providers.
        $this->app->tag([FleetJobBusyWindowsProvider::class], 'busy_windows_providers');

        // VehicleAvailabilityChecker depends on the tagged provider collection.
        // Resolved via a closure so the tag is queried at construction time
        // (after every module's register() has run).
        $this->app->bind(VehicleAvailabilityChecker::class, function ($app) {
            return new VehicleAvailabilityService(
                constraints:   $app->make(VehicleConstraints::class),
                busyProviders: $app->tagged('busy_windows_providers'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
