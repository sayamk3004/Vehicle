<?php

namespace App\Modules\Vehicle\Models;

use App\Modules\Organization\Models\Employee;
use App\Modules\Shared\Models\Job;
use App\Modules\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

class JobVehicle extends Model
{
    protected $connection = 'organizum';
    protected $table = 'job_vehicles';
    protected $fillable = ['job_id', 'vehicle_id', 'driver_id', 'driver_role'];

    public function driver()
    {
        return $this->belongsTo(Employee::class, 'driver_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function jobVehicles()
    {
        return $this->hasMany(self::class, 'vehicle_id');
    }
}
