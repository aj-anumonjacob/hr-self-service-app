<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Notification extends Model
{
    protected $fillable = ['uuid', 'employee_id', 'title', 'body', 'payload', 'is_read', 'sent_at', 'read_at'];
    protected $casts = ['payload' => 'array', 'is_read' => 'boolean'];

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
}
