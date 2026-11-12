<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Announcement extends Model
{
    protected $fillable = ['uuid', 'company_id', 'title', 'body', 'target_scope', 'target_id'];

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
