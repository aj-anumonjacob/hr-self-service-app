<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WeeklyOffRule extends Model
{
    protected $table = 'weekly_off_rules';
    protected $fillable = ['uuid', 'company_id', 'name', 'days'];
    protected $casts = ['days' => 'array'];

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
