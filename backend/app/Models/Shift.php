<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Shift extends Model
{
    protected $fillable = [
        'uuid', 'company_id', 'name', 'code', 'type', 'start_time', 'end_time',
        'flexible_minutes', 'grace_period_minutes', 'late_threshold_minutes',
        'early_departure_minutes', 'min_working_minutes', 'break_rules', 'overtime_calc_enabled',
        'working_days'
    ];
    protected $casts = ['break_rules' => 'array', 'overtime_calc_enabled' => 'boolean', 'working_days' => 'array'];

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
}
