<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    protected $table = 'audit_log';
    public $timestamps = false;
    protected $fillable = ['uuid', 'user_id', 'user_name', 'user_role', 'action', 'module', 'source', 'object_type', 'object_id', 'summary', 'old_data', 'new_data', 'ip_address', 'user_agent', 'success', 'created_at'];
    protected $casts = ['old_data' => 'array', 'new_data' => 'array'];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = \Carbon\Carbon::now();
            }
            if (empty($model->object_type)) {
                $model->object_type = 'employees';
            }
            if (empty($model->summary)) {
                $model->summary = 'Mobile app activity log';
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
