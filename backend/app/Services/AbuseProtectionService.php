<?php

namespace App\Services;

use App\Models\OtpMailAuditLog;
use App\Models\SecurityConfiguration;
use Illuminate\Support\Facades\Cache;

class AbuseProtectionService
{
    public function isActivationThrottled(int $employeeId, string $ip, string $deviceId): bool
    {
        $windowMins = (int) SecurityConfiguration::getVal('rate_limit_window_minutes', 15);
        $maxEmp = (int) SecurityConfiguration::getVal('max_attempts_employee', 5);
        $maxIp = (int) SecurityConfiguration::getVal('max_attempts_ip', 10);
        $maxDev = (int) SecurityConfiguration::getVal('max_attempts_device', 5);

        $empKey = "activation_attempt_count:emp:{$employeeId}";
        $ipKey = "activation_attempt_count:ip:{$ip}";
        $devKey = "activation_attempt_count:dev:{$deviceId}";

        $empAttempts = (int) Cache::get($empKey, 0);
        $ipAttempts = (int) Cache::get($ipKey, 0);
        $devAttempts = (int) Cache::get($devKey, 0);

        if ($empAttempts >= $maxEmp || $ipAttempts >= $maxIp || $devAttempts >= $maxDev) {
            return true;
        }

        return false;
    }

    public function recordFailedActivation(int $employeeId, string $ip, string $deviceId): void
    {
        $windowMins = (int) SecurityConfiguration::getVal('rate_limit_window_minutes', 15);
        $ttlSeconds = $windowMins * 60;

        $empKey = "activation_attempt_count:emp:{$employeeId}";
        $ipKey = "activation_attempt_count:ip:{$ip}";
        $devKey = "activation_attempt_count:dev:{$deviceId}";

        Cache::put($empKey, (int) Cache::get($empKey, 0) + 1, $ttlSeconds);
        Cache::put($ipKey, (int) Cache::get($ipKey, 0) + 1, $ttlSeconds);
        Cache::put($devKey, (int) Cache::get($devKey, 0) + 1, $ttlSeconds);
    }

    public function isCodeRequestThrottled(int $employeeId, string $ip): bool
    {
        $limit = (int) SecurityConfiguration::getVal('mobile_request_limit_30m', 3);
        $key = "code_request_count:emp:{$employeeId}";
        $count = (int) Cache::get($key, 0);
        return $count >= $limit;
    }

    public function recordCodeRequest(int $employeeId, string $ip): void
    {
        $key = "code_request_count:emp:{$employeeId}";
        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, 1800); // 30 mins window
    }
}
