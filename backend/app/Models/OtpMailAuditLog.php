<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OtpMailAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'event_type',
        'employee_id',
        'masked_identifier',
        'administrator_id',
        'device_instance_id',
        'masked_ip',
        'result',
        'reason_category',
        'delivery_status',
        'provider_message_id',
        'audit_correlation_id',
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

    public static function logEvent(array $data): self
    {
        return static::create([
            'event_type' => $data['event_type'],
            'employee_id' => $data['employee_id'] ?? null,
            'masked_identifier' => isset($data['identifier']) ? static::maskString($data['identifier']) : null,
            'administrator_id' => $data['administrator_id'] ?? null,
            'device_instance_id' => $data['device_instance_id'] ?? null,
            'masked_ip' => isset($data['ip']) ? static::maskIp($data['ip']) : null,
            'result' => $data['result'] ?? 'success',
            'reason_category' => $data['reason_category'] ?? null,
            'delivery_status' => $data['delivery_status'] ?? null,
            'provider_message_id' => $data['provider_message_id'] ?? null,
            'audit_correlation_id' => $data['audit_correlation_id'] ?? (string) Str::uuid(),
        ]);
    }

    public static function maskString(?string $str): ?string
    {
        if (empty($str)) {
            return null;
        }
        if (filter_var($str, FILTER_VALIDATE_EMAIL)) {
            $parts = explode('@', $str);
            $name = $parts[0];
            $domain = $parts[1] ?? '';
            $maskedName = strlen($name) > 2 ? substr($name, 0, 2) . str_repeat('*', strlen($name) - 2) : $name;
            return $maskedName . '@' . $domain;
        }
        $len = strlen($str);
        if ($len <= 3) {
            return str_repeat('*', $len);
        }
        return substr($str, 0, 2) . str_repeat('*', $len - 4) . substr($str, -2);
    }

    public static function maskIp(?string $ip): ?string
    {
        if (empty($ip)) {
            return null;
        }
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return '127.0.0.*';
        }
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.***.***';
        }
        return substr($ip, 0, 6) . '****';
    }
}
