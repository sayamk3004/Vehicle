<?php

use App\Modules\Vehicle\Controllers\VehicleController;
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
