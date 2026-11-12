<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmployeeLocationAssignment extends Model
{
    protected $table = 'employee_location_assignments';
    protected $fillable = [
        'uuid', 'employee_id', 'original_location_id', 'assigned_location_id', 'assigned_shift_id',
        'start_time', 'end_time', 'assignment_type', 'reason', 'instructions', 'manager_id',
        'status', 'tracking_policy', 'offline_policy', 'employee_acknowledged'
    ];
    protected $casts = ['employee_acknowledged' => 'boolean'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = Str::uuid()->toString();
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function originalLocation()
    {
        return $this->belongsTo(WorkLocation::class, 'original_location_id');
    }

    public function assignedLocation()
    {
        return $this->belongsTo(WorkLocation::class, 'assigned_location_id');
    }

    public function assignedShift()
    {
        return $this->belongsTo(Shift::class, 'assigned_shift_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
