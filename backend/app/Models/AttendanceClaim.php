<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AttendanceClaim extends Model
{
    protected $fillable = [
        'uuid',
        'employee_id',
        'reporting_manager_id',
        'company_id',
        'attendance_record_id',
        'claim_type',
        'date',
        'recorded_check_in_at',
        'recorded_check_out_at',
        'requested_check_in_at',
        'requested_check_out_at',
        'reason_code',
        'reason',
        'description',
        'status',
        'submitted_at',
        'actioned_at',
        'manager_comment',
        'manager_notes',
        'explanation_request',
        'employee_response'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'recorded_check_in_at' => 'datetime',
        'recorded_check_out_at' => 'datetime',
        'submitted_at' => 'datetime',
        'actioned_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (!$model->uuid) {
                $model->uuid = Str::uuid()->toString();
            }
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reportingManager()
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function attendanceRecord()
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function actions()
    {
        return $this->hasMany(AttendanceClaimAction::class)->orderBy('created_at', 'asc');
    }
}
