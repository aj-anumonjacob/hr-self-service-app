<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmployeeLocationLog extends Model
{
    protected $table = 'employee_location_logs';
    protected $fillable = [
        'uuid', 'tracking_session_id', 'employee_id', 'latitude', 'longitude',
        'accuracy', 'speed', 'heading', 'captured_at', 'received_at',
        'battery_level', 'network_status', 'device_id', 'security_indicators', 'is_offline'
    ];
    protected $casts = [
        'security_indicators' => 'array',
        'is_offline' => 'boolean'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = Str::uuid()->toString();
        });
    }

    public function session()
    {
        return $this->belongsTo(LocationTrackingSession::class, 'tracking_session_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
