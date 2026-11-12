<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Company extends Model
{
    protected $fillable = ['uuid', 'name', 'code', 'logo_url', 'registration_number', 'timezone'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = Str::uuid()->toString();
        });
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function designations()
    {
        return $this->hasMany(Designation::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function workLocations()
    {
        return $this->hasMany(WorkLocation::class);
    }

    public function weeklyOffRules()
    {
        return $this->hasMany(WeeklyOffRule::class);
    }
}
