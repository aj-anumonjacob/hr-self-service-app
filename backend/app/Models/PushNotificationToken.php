<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushNotificationToken extends Model
{
    protected $table = 'push_notification_tokens';
    protected $fillable = ['employee_id', 'device_id', 'fcm_token', 'active'];
    protected $casts = ['active' => 'boolean'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function device()
    {
        return $this->belongsTo(MobileDevice::class, 'device_id');
    }
}
