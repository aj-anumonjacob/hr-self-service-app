<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OtpMailAuditLog;
use App\Models\SecurityConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SecurityConfigController extends Controller
{
    public function show()
    {
        $keys = [
            'code_expiry_minutes',
            'max_attempts_employee',
            'max_attempts_ip',
            'max_attempts_device',
            'rate_limit_window_minutes',
            'cooldown_minutes',
            'mobile_request_limit_30m',
            'resend_countdown_seconds',
            'allow_multiple_devices',
            'notification_recipients',
            'sender_email',
        ];

        $config = [];
        foreach ($keys as $k) {
            $config[$k] = SecurityConfiguration::getVal($k);
        }

        return response()->json([
            'success' => true,
            'data' => $config,
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code_expiry_minutes' => 'nullable|integer|min:1|max:1440',
            'max_attempts_employee' => 'nullable|integer|min:1|max:100',
            'max_attempts_ip' => 'nullable|integer|min:1|max:1000',
            'max_attempts_device' => 'nullable|integer|min:1|max:100',
            'rate_limit_window_minutes' => 'nullable|integer|min:1|max:1440',
            'cooldown_minutes' => 'nullable|integer|min:1|max:1440',
            'mobile_request_limit_30m' => 'nullable|integer|min:1|max:50',
            'resend_countdown_seconds' => 'nullable|integer|min:10|max:600',
            'allow_multiple_devices' => 'nullable|boolean',
            'notification_recipients' => 'nullable|array',
            'sender_email' => 'nullable|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $adminId = auth()->id();
        $ip = $request->ip();

        foreach ($request->only([
            'code_expiry_minutes',
            'max_attempts_employee',
            'max_attempts_ip',
            'max_attempts_device',
            'rate_limit_window_minutes',
            'cooldown_minutes',
            'mobile_request_limit_30m',
            'resend_countdown_seconds',
            'allow_multiple_devices',
            'notification_recipients',
            'sender_email',
        ]) as $k => $v) {
            if ($v !== null) {
                $oldVal = SecurityConfiguration::getVal($k);
                SecurityConfiguration::setVal($k, $v, $adminId);

                OtpMailAuditLog::logEvent([
                    'event_type' => 'security_config_changed',
                    'administrator_id' => $adminId,
                    'ip' => $ip,
                    'result' => 'success',
                    'reason_category' => "{$k}: " . json_encode($oldVal) . ' -> ' . json_encode($v),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Security configuration updated successfully.',
        ]);
    }
}
