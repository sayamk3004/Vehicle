<?php

namespace App\Modules\Vehicle\Providers;

use App\Modules\Vehicle\Contracts\VehicleAvailabilityChecker;
use App\Modules\Vehicle\Services\VehicleAvailabilityService;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VehicleAvailabilityChecker::class, VehicleAvailabilityService::class);
    }

    public function boot(): void
    {
        //
    }
}
