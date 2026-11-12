<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCertificate extends Model
{
    protected $fillable = ['employee_id', 'platform_device_id', 'issued_at', 'expires_at'];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
