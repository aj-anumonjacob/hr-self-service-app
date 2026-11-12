<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeviceBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'employee_id',
        'platform_device_id',
        'device_name',
        'platform',
        'os_version',
        'app_version',
        'public_key',
        'status',
        'bound_at',
        'revoked_at',
        'audit_correlation_id',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
        'revoked_at' => 'datetime',
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

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
