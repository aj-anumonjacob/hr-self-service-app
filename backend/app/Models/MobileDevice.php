<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobileDevice extends Model
{
    protected $table = 'mobile_devices';
    protected $fillable = [
        'uuid', 'employee_id', 'device_name', 'device_model', 'os_version',
        'app_version', 'platform_device_id', 'status', 'registered_at',
        'last_login_at', 'last_login_latitude', 'last_login_longitude',
        'last_login_accuracy', 'last_login_ip',
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
