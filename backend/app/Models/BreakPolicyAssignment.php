<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BreakPolicyAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'break_policy_id',
        'assignable_type',
        'assignable_id',
        'effective_from',
        'effective_until',
        'assigned_by'
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_until' => 'date'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function breakPolicy()
    {
        return $this->belongsTo(BreakPolicy::class);
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
