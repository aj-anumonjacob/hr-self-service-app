<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailNotificationLog extends Model
{
    protected $fillable = [
        'recipient_email',
        'recipient_name',
        'template_name',
        'related_type',
        'related_id',
        'subject',
        'body_text',
        'delivery_status',
        'attempted_at',
        'sent_at',
        'failure_reason',
        'retry_count',
        'idempotency_key'
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
