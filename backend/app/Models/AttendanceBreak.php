<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AttendanceBreak extends Model
{
    use HasFactory;

    protected $table = 'attendance_breaks';

    protected $fillable = [
        'uuid',
        'attendance_record_id',
        'employee_id',
        'break_policy_id',
        'start_time_server',
        'start_time_device',
        'end_time_server',
        'end_time_device',
        'duration_minutes',
        'duration_seconds',
        'overdue_duration_seconds',
        'expected_end_at',
        'became_overdue_at',
        'next_notification_at',
        'overdue_notification_count',
        'escalation_level',
        'notification_series_status',
        'notification_series_stopped_at',
        'notification_series_stop_reason',
        'status',
        'violation_code',
        'approval_status',
        'approved_by',
        'approved_at',
        'idempotency_key',
        'is_paid',
        'reason'
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'start_time_server' => 'datetime',
        'start_time_device' => 'datetime',
        'end_time_server' => 'datetime',
        'end_time_device' => 'datetime',
        'expected_end_at' => 'datetime',
        'became_overdue_at' => 'datetime',
        'next_notification_at' => 'datetime',
        'notification_series_stopped_at' => 'datetime',
        'approved_at' => 'datetime',
        'duration_minutes' => 'integer',
        'duration_seconds' => 'integer',
        'overdue_duration_seconds' => 'integer',
        'overdue_notification_count' => 'integer',
        'escalation_level' => 'integer'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function record()
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function breakPolicy()
    {
        return $this->belongsTo(BreakPolicy::class);
    }

    public function approver()
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    public function notificationLogs()
    {
        return $this->hasMany(BreakNotificationLog::class, 'break_id');
    }
}
