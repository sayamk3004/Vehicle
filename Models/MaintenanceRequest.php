<?php

namespace App\Modules\Vehicle\Models;

use App\Modules\Organization\Models\Employee;
use App\Modules\Shared\Models\User;
use App\Modules\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

class MaintenanceRequest extends Model
{
    protected $connection = 'organizum';
    protected $table = 'maintenance_requests';

    protected $fillable = [
        'vehicle_id',
        'user_id',
        'employee_id',
        'problem_name',
        'problem_description',
        'image',
        'status',
        'organization_id',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id')->with(['user' => function ($query) {
            $query->select('id', 'f_name', 'l_name');
        }]);
    }

    public function repairHistories()
    {
        return $this->hasMany(RepairHistory::class)->with(['employee', 'user']);
    }

    public function repairCount()
    {
        return $this->repairHistories()->count();
    }
}
