<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AttendanceRequest extends Model
{
    protected $fillable = [
        'uuid',
        'employee_id',
        'reporting_manager_id',
        'company_id',
        'shift_id',
        'date',
        'request_type',
        'delay_minutes',
        'expected_arrival_at',
        'reason_code',
        'description',
        'status',
        'submitted_at',
        'actioned_at',
        'manager_comment',
        'explanation_request',
        'employee_response'
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'expected_arrival_at' => 'datetime',
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

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function actions()
    {
        return $this->hasMany(AttendanceRequestAction::class)->orderBy('created_at', 'asc');
    }
}
