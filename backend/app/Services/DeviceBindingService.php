<?php

namespace App\Services;

use App\Models\ActivationCode;
use App\Models\DeviceBinding;
use App\Models\Employee;
use App\Models\OtpMailAuditLog;
use App\Models\RefreshToken;
use App\Models\SecurityConfiguration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DeviceBindingService
{
    /**
     * Atomically activate account, consume activation code, and create device binding.
     */
    public function activateAccountAndBindDevice(
        Employee $employee,
        ActivationCode $codeRecord,
        string $password,
        array $deviceDetails,
        ?string $ip = null
    ): array {
        return DB::transaction(function () use ($employee, $codeRecord, $password, $deviceDetails, $ip) {
            $correlationId = (string) Str::uuid();

            // 1. Lock and consume activation code record
            $codeRecord->refresh();
            if ($codeRecord->status !== 'unused' || Carbon::now()->gt($codeRecord->expires_at)) {
                throw new \Exception('Activation code is invalid or has expired.');
            }

            $codeRecord->update([
                'status' => 'used',
                'used_at' => Carbon::now(),
            ]);

            // 2. Check Device Policy Invariants
            $allowMultiple = (bool) SecurityConfiguration::getVal('allow_multiple_devices', false);
            $existingEmpBinding = DeviceBinding::where('employee_id', $employee->id)
                ->where('status', 'active')
                ->first();
            $existingEmpMobileDevice = \App\Models\MobileDevice::where('employee_id', $employee->id)
                ->where('platform_device_id', '!=', $deviceDetails['platform_device_id'])
                ->where('status', 'approved')
                ->first();

            if (($existingEmpBinding || $existingEmpMobileDevice) && !$allowMultiple) {
                throw new \Exception('Employee already has an active bound device under the configured policy.', 409);
            }

            $existingDeviceBinding = DeviceBinding::where('platform_device_id', $deviceDetails['platform_device_id'])
                ->where('status', 'active')
                ->where('employee_id', '!=', $employee->id)
                ->first();
            $existingDeviceMobile = \App\Models\MobileDevice::where('platform_device_id', $deviceDetails['platform_device_id'])
                ->where('status', 'approved')
                ->where('employee_id', '!=', $employee->id)
                ->first();

            if ($existingDeviceBinding || $existingDeviceMobile) {
                throw new \Exception('This device is already bound to another employee.', 409);
            }

            // 3. User Resolution & Password Update
            $systemEmail = !empty($employee->email)
                ? $employee->email
                : (($employee->employee_code ?? $employee->employee_number ?? 'emp_' . $employee->id) . '@system.local');

            $user = User::where('email', $systemEmail)->first();
            if (!$user && !empty($employee->email)) {
                $user = User::where('email', $employee->email)->first();
            }

            if (!$user) {
                $user = User::create([
                    'name' => $employee->full_name,
                    'email' => $systemEmail,
                    'password' => Hash::make($password),
                ]);

                if (SchemaHasRole()) {
                    $role = \App\Models\Role::where('slug', 'employee')
                        ->orWhere('name', 'LIKE', '%Employee%')
                        ->first();
                    if ($role && method_exists($user, 'roles')) {
                        try { $user->roles()->attach($role->id); } catch (\Throwable $e) {}
                    }
                }
            } else {
                $user->update(['password' => Hash::make($password)]);
            }

            $employee->update(['user_id' => $user->id, 'device_registration_status' => true]);

            // 4. Create Device Binding
            $binding = DeviceBinding::create([
                'employee_id' => $employee->id,
                'platform_device_id' => $deviceDetails['platform_device_id'],
                'device_name' => $deviceDetails['device_name'] ?? 'Mobile Device',
                'platform' => $deviceDetails['platform'] ?? null,
                'os_version' => $deviceDetails['os_version'] ?? null,
                'app_version' => $deviceDetails['app_version'] ?? null,
                'public_key' => $deviceDetails['public_key'] ?? null,
                'status' => 'active',
                'bound_at' => Carbon::now(),
                'audit_correlation_id' => $correlationId,
            ]);

            // Also update legacy mobile_devices table for backward compatibility if present
            if (\Schema::hasTable('mobile_devices')) {
                DB::table('mobile_devices')->updateOrInsert(
                    ['platform_device_id' => $deviceDetails['platform_device_id']],
                    [
                        'uuid' => (string) Str::uuid(),
                        'employee_id' => $employee->id,
                        'device_name' => $deviceDetails['device_name'] ?? 'Mobile Device',
                        'device_model' => $deviceDetails['device_name'] ?? 'Mobile Device',
                        'os_version' => $deviceDetails['os_version'] ?? null,
                        'app_version' => $deviceDetails['app_version'] ?? null,
                        'status' => 'approved',
                        'registered_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]
                );
            }

            // 5. Revoke any other unused codes for this employee
            ActivationCode::where('employee_id', $employee->id)
                ->where('status', 'unused')
                ->update(['status' => 'revoked', 'revoked_at' => Carbon::now()]);

            // 6. Audit Log
            OtpMailAuditLog::logEvent([
                'event_type' => 'activation_success',
                'employee_id' => $employee->id,
                'identifier' => $employee->email ?? $employee->employee_number,
                'device_instance_id' => $deviceDetails['platform_device_id'],
                'ip' => $ip,
                'result' => 'success',
                'audit_correlation_id' => $correlationId,
            ]);

            // 7. Issue Sanctum Tokens
            $accessToken = $user->createToken('MobileDevice')->plainTextToken;
            $rawRefreshToken = Str::random(64);
            $tokenHash = hash('sha256', $rawRefreshToken);

            RefreshToken::create([
                'user_id' => $user->id,
                'token_hash' => $tokenHash,
                'expires_at' => Carbon::now()->addDays(30),
            ]);

            return [
                'token' => $accessToken,
                'refresh_token' => $rawRefreshToken,
                'user' => $user,
                'employee' => $employee,
                'binding' => $binding,
            ];
        });
    }

    /**
     * Unbind device for an employee (Authorized HR Admin action).
     */
    public function unbindDevice(Employee $employee, int $adminUserId, ?string $ip = null): void
    {
        DB::transaction(function () use ($employee, $adminUserId, $ip) {
            $correlationId = (string) Str::uuid();

            // 1. Revoke active device bindings
            DeviceBinding::where('employee_id', $employee->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => Carbon::now(),
                ]);

            if (\Schema::hasTable('mobile_devices')) {
                DB::table('mobile_devices')
                    ->where('employee_id', $employee->id)
                    ->update(['status' => 'revoked']);
            }

            // 2. Revoke active user tokens & sessions
            $user = $employee->user;
            if (!$user && !empty($employee->email)) {
                $user = User::where('email', $employee->email)->first();
            }

            if ($user) {
                $user->tokens()->delete();
                RefreshToken::where('user_id', $user->id)->delete();
            }

            // 3. Revoke unused activation codes
            ActivationCode::where('employee_id', $employee->id)
                ->where('status', 'unused')
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => Carbon::now(),
                ]);

            // 4. Update employee registration status
            DB::table('employees')->where('id', $employee->id)->update(['device_registration_status' => 0]);
            $employee->device_registration_status = 0;

            // 5. Audit Event
            OtpMailAuditLog::logEvent([
                'event_type' => 'device_unbound',
                'employee_id' => $employee->id,
                'identifier' => $employee->email ?? $employee->employee_number,
                'administrator_id' => $adminUserId,
                'ip' => $ip,
                'result' => 'success',
                'audit_correlation_id' => $correlationId,
            ]);

            // 6. Send Email Notification
            if (!empty($employee->email) && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $senderEmail = SecurityConfiguration::getVal('sender_email', 'support@example.com');
                    Mail::raw(
                        "Hello {$employee->full_name},\n\nYour mobile device binding has been unbound by HR. Your active mobile sessions have been terminated. If you need to re-register a new device, please request a new activation code.",
                        function ($message) use ($employee, $senderEmail) {
                            $message->from($senderEmail, 'Your Company HR')
                                ->to($employee->email)
                                ->subject('Notice: Mobile Device Unbound');
                        }
                    );
                } catch (\Throwable $e) {}
            }
        });
    }
}

function SchemaHasRole(): bool
{
    return \Schema::hasTable('roles');
}
