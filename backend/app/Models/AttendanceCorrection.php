<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AttendanceCorrection extends Model
{
    protected $table = 'attendance_corrections';
    protected $fillable = [
        'uuid', 'employee_id', 'date', 'correction_type', 'requested_time',
        'reason', 'evidence_file_url', 'manager_id', 'status', 'manager_notes'
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

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
