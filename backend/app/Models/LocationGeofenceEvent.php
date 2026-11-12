<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LocationGeofenceEvent extends Model
{
    protected $table = 'location_geofence_events';
    protected $fillable = [
        'uuid', 'employee_id', 'tracking_session_id', 'event_type',
        'geofence_location_id', 'latitude', 'longitude', 'accuracy', 'captured_at'
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

    public function session()
    {
        return $this->belongsTo(LocationTrackingSession::class, 'tracking_session_id');
    }

    public function location()
    {
        return $this->belongsTo(WorkLocation::class, 'geofence_location_id');
    }
}
