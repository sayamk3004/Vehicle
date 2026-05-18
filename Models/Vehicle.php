<?php

namespace App\Modules\Vehicle\Models;

use App\Modules\Booking\Models\City;
use App\Modules\Booking\Models\JobCategory;
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
        'buffer_time_minutes',
        'max_bookings_per_day',
        // Customer-facing details (added 2026-05-19).
        'transmission',
        'fuel_type',
        'wheelchair_accessible',
    ];

    protected $casts = [
        'buffer_time_minutes'   => 'integer',
        'max_bookings_per_day'  => 'integer',
        'wheelchair_accessible' => 'boolean',
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

    /**
     * Job categories a vehicle is configured to serve (airport, hotel, sightseeing, etc.).
     * Many-to-many — a vehicle can serve multiple categories, and a category can be
     * served by multiple vehicles. The booking flow filters available vehicles by
     * the customer's selected job category.
     */
    public function jobCategories()
    {
        return $this->belongsToMany(
            JobCategory::class,
            'vehicle_job_category',
            'vehicle_id',
            'job_category_id'
        )->withTimestamps();
    }

    public function zoneAssignments()
    {
        return $this->morphMany(ZoneAssignment::class, 'zoneable');
    }
}
