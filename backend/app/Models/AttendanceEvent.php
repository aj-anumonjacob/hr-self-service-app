<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AttendanceEvent extends Model
{
    protected $table = 'attendance_events';

    protected $fillable = [
        'uuid',
        'employee_id',
        'attendance_record_id',
        'attendance_break_id',
        'event_type',
        'event_time_server',
        'event_time_device',
        'latitude',
        'longitude',
        'accuracy',
        'distance_meters',
        'device_id',
        'app_version',
        'os_version',
        'ip_address',
        'offline_authorization_id',
        'is_offline',
        'sync_status',
        'security_flags',
        'idempotency_key',
        'event_source',
        'actor_user_id',
        'actor_role',
        'metadata'
    ];

    protected $casts = [
        'is_offline' => 'boolean',
        'security_flags' => 'array',
        'metadata' => 'array',
        'event_time_server' => 'datetime',
        'event_time_device' => 'datetime'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function record()
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function attendanceBreak()
    {
        return $this->belongsTo(AttendanceBreak::class, 'attendance_break_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function offlineAuthorization()
    {
        return $this->belongsTo(OfflineAttendanceAuthorization::class, 'offline_authorization_id');
    }
}
