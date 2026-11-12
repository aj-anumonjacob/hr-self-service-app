<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WorkLocation extends Model
{
    protected $table = 'work_locations';
    protected $fillable = [
        'uuid', 'company_id', 'code', 'name', 'address', 'latitude', 'longitude',
        'check_in_radius', 'checkout_radius', 'default_shift_id', 'manager_id',
        'status', 'background_tracking_policy', 'offline_attendance_policy'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = Str::uuid()->toString();
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function defaultShift()
    {
        return $this->belongsTo(Shift::class, 'default_shift_id');
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
