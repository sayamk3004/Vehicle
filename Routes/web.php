<?php

use App\Modules\Vehicle\Controllers\VehicleController;
use App\Modules\Vehicle\Controllers\Admin\VehicleController as AdminVehicleController;

use Illuminate\Support\Facades\Route;



Route::group(['prefix' => 'vehicles', 'as' => 'vehicle.', 'middleware' => 'auth'], function () {
    Route::get('/', [VehicleController::class, 'index'])->name('index');
    // Route::group(['middleware' => ['CheckCreationLimits:vehicle']], function () {
    Route::get('/create', [VehicleController::class, 'add'])->name('add');
    Route::get('/edit-{id}', [VehicleController::class, 'edit'])->name('edit');
    Route::post('/update-{id}', [VehicleController::class, 'update'])->name('update');
    Route::post('/create', [VehicleController::class, 'create'])->name('create');
    // });
    Route::post('/delete-{id}', [VehicleController::class, 'delete'])->name('delete');
    Route::get('/view-{id}', [VehicleController::class, 'view'])->name('view');
    Route::put('{id}/status', [VehicleController::class, 'updateStatus'])->name('statusChange');

    Route::get('/{status}', [VehicleController::class, 'list'])->name('list');
});
Route::group(['prefix' => 'vehicles', 'as' => 'vehicle.'], function () {
    Route::get('/{slug}/fetch-vehicles', [VehicleController::class, 'fetch'])->name('fetch');
});
Route::group(['prefix' => 'vehicles', 'as' => 'vehicle.'], function () {
    Route::get('/{vehicleId}/check-availability', [VehicleController::class, 'check'])->name('checkAvailability');
});


Route::middleware(['auth:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::prefix('vehicles')->as('vehicle.')->group(function () {

            Route::get('/', [AdminVehicleController::class, 'index'])->name('index');

            // create
            Route::get('/create', [AdminVehicleController::class, 'add'])->name('add');
            Route::post('/create', [AdminVehicleController::class, 'create'])->name('create');

            // edit/update/view/delete
            Route::get('/edit-{id}', [AdminVehicleController::class, 'edit'])->name('edit');
            Route::post('/update-{id}', [AdminVehicleController::class, 'update'])->name('update');
            Route::post('/delete-{id}', [AdminVehicleController::class, 'delete'])->name('delete');
            Route::get('/view-{id}', [AdminVehicleController::class, 'view'])->name('view');

            // status change
            Route::put('{id}/status', [AdminVehicleController::class, 'updateStatus'])->name('statusChange');

            // availability & fetch (put these before the {status} catch-all)
            Route::get('/{vehicleId}/check-availability', [AdminVehicleController::class, 'check'])->name('checkAvailability');
            Route::get('/{slug}/fetch-vehicles', [AdminVehicleController::class, 'fetch'])->name('fetch');

            // filtered lists (active/paused/all)
            Route::get('/{status}', [AdminVehicleController::class, 'list'])->name('list');
        });
    });
