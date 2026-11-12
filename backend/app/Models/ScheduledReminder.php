<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledReminder extends Model
{
    protected $fillable = [
        'employee_id',
        'shift_id',
        'shift_date',
        'reminder_type',
        'scheduled_for',
        'status',
        'idempotency_key',
        'attempt_count',
        'sent_at',
        'cancellation_reason'
    ];

    protected $casts = [
        'shift_date' => 'date:Y-m-d',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
