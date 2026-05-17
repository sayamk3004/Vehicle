<?php

namespace App\Modules\Vehicle\Models;

use App\Modules\Booking\Models\City;
use App\Modules\Booking\Models\PricingTemplate;
use App\Modules\Booking\Models\VehicleType;
use App\Modules\Shared\Models\Job;
use App\Modules\Shared\Models\Organization;
use App\Modules\Shared\Models\ZoneAssignment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'organizum';
    protected $table = 'vehicles';

    protected $fillable = [
        'user_id',
        'organization_id',
        'slug',
        'title',
        'reg_num',
        'seats',
        'image',
        'color',
        'model',
        'year',
        'mileage',
        'description',
        'status',
        'token',
        'city_id',
        'pricing_template_id',
        'vehicle_type_id',
    ];

    public function inspections()
    {
        return $this->hasMany(Inspection::class, 'vehicle_id')->with(['job']);
    }

    public function jobs()
    {
        return $this->morphMany(Job::class, 'jobable');
    }

    public function jobVehicles()
    {
        return $this->hasMany(JobVehicle::class, 'vehicle_id');
    }

    public function maintenances()
    {
        return $this->hasMany(MaintenanceRequest::class, 'vehicle_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function pricingTemplate()
    {
        return $this->belongsTo(PricingTemplate::class);
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class, 'vehicle_type_id');
    }

    public function vehicleTypes()
    {
        return $this->belongsToMany(
            VehicleType::class,
            'vehicle_vehicle_type',
            'vehicle_id',
            'vehicle_type_id'
        )->withTimestamps();
    }

    public function zoneAssignments()
    {
        return $this->morphMany(ZoneAssignment::class, 'zoneable');
    }
}
