<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class InAppNotification extends Model
{
    protected $table = 'in_app_notifications';

    protected $fillable = [
        'uuid',
        'user_id',
        'recipient_role',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'read_at',
        'sent_at',
        'scheduled_at',
        'idempotency_key',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (is_null($model->is_read)) {
                $model->is_read = false;
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

?>
