<?php

namespace App\Modules\Vehicle\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InspectionOption extends Model
{
    use HasFactory;
    protected $connection = 'organizum';
    protected $table = 'inspection_options';

    protected $fillable = ['name'];
}
