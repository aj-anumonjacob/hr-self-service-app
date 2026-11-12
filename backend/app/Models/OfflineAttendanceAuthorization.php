<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OfflineAttendanceAuthorization extends Model
{
    protected $table = 'offline_attendance_authorizations';
    protected $fillable = ['uuid', 'employee_id', 'device_id', 'valid_from', 'valid_to', 'policy_data', 'cryptographic_signature'];
    protected $casts = [
        'policy_data' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
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
}
