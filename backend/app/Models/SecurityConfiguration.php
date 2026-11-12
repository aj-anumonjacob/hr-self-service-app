<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SecurityConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'updated_by_user_id',
    ];

    public static function getVal(string $key, mixed $default = null): mixed
    {
        $rec = static::where('key', $key)->first();
        if (!$rec) {
            return $default;
        }
        $decoded = json_decode($rec->value, true);
        return $decoded !== null ? $decoded : $rec->value;
    }

    public static function setVal(string $key, mixed $val, ?int $userId = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            [
                'value' => json_encode($val),
                'updated_by_user_id' => $userId,
            ]
        );
    }
}
