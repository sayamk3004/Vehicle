<?php

namespace App\Modules\Vehicle\Models;

use App\Modules\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Inspection extends Model
{
    use HasFactory;
    protected $connection = 'organizum';
    protected $table = 'inspections';

    protected $fillable = [
        'job_id',
        'vehicle_id',
        'type',
        'remark',
        'start_mileage',
        'end_mileage',
        'options',
        'images',
    ];

    protected $casts = [
        'options' => 'array',
        'images'  => 'array',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function inspection_options()
    {
        if ($this->options === null) {
            return collect();
        }
        return InspectionOption::whereIn('id', $this->options)->get();
    }
}
