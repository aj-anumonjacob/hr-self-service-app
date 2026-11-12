<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AttendanceRecord extends Model
{
    protected $table = 'attendance_records';
    protected $appends = ['check_in_time', 'check_out_time'];
    protected $fillable = [
        'uuid', 'employee_id', 'date', 'shift_id', 'location_id', 'assignment_id',
        'total_duration_minutes', 'net_duration_minutes', 'break_duration_minutes',
        'late_minutes', 'early_departure_minutes', 'potential_overtime_minutes',
        'status', 'security_risk_level', 'check_in_event_id', 'check_out_event_id'
    ];

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

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function location()
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function assignment()
    {
        return $this->belongsTo(EmployeeLocationAssignment::class, 'assignment_id');
    }

    public function getCheckInTimeAttribute()
    {
        return $this->checkInEvent?->event_time_server;
    }

    public function getCheckOutTimeAttribute()
    {
        return $this->checkOutEvent?->event_time_server;
    }

    public function checkInEvent()
    {
        return $this->belongsTo(AttendanceEvent::class, 'check_in_event_id');
    }

    public function checkOutEvent()
    {
        return $this->belongsTo(AttendanceEvent::class, 'check_out_event_id');
    }

    public function breaks()
    {
        return $this->hasMany(AttendanceBreak::class);
    }

    public function events()
    {
        return $this->hasMany(AttendanceEvent::class);
    }
}
