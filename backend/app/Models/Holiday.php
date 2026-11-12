<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Holiday extends Model
{
    protected $fillable = ['uuid', 'name', 'start_date', 'end_date', 'holiday_type', 'description', 'status'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->uuid = Str::uuid()->toString();
        });
    }

    public function scopes()
    {
        return $this->hasMany(HolidayScope::class);
    }
}
