<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BreakNotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'break_id',
        'attendance_record_id',
        'employee_id',
        'recipient_user_id',
        'recipient_role',
        'notification_type',
        'escalation_sequence_number',
        'scheduled_at',
        'attempted_at',
        'sent_at',
        'delivery_provider_message_id',
        'delivery_status',
        'failure_reason',
        'title',
        'message',
        'idempotency_key'
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'attempted_at' => 'datetime',
        'sent_at' => 'datetime',
        'escalation_sequence_number' => 'integer'
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

    public function attendanceBreak()
    {
        return $this->belongsTo(AttendanceBreak::class, 'break_id');
    }

    public function attendanceRecord()
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function recipientUser()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
