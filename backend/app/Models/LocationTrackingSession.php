<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LocationTrackingSession extends Model
{
    protected $table = 'location_tracking_sessions';
    protected $fillable = [
        'uuid', 'employee_id', 'attendance_record_id', 'start_time', 'end_time',
        'tracking_mode', 'tracking_interval_seconds', 'status'
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

    public function record()
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function logs()
    {
        return $this->hasMany(EmployeeLocationLog::class, 'tracking_session_id');
    }

    public function geofenceEvents()
    {
        return $this->hasMany(LocationGeofenceEvent::class, 'tracking_session_id');
    }
}
