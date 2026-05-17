<?php

namespace App\Modules\Vehicle\Models;

use App\Modules\Organization\Models\Employee;
use App\Modules\Vehicle\Models\MaintenanceRequest;
use App\Modules\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RepairHistory extends Model
{
    protected $connection = 'organizum';
    protected $table = 'repair_histories';
    protected $fillable = [
        'maintenance_request_id',
        'part_name',
        'quantity',
        'cost',
        'labor_cost',
        'repair_by', // 'owner' or 'employee'
        'employee_id',
    ];

    public function maintenanceRequest()
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class)->with('user');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
