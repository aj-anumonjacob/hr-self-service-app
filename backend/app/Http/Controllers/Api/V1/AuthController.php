<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\MobileDevice;
use App\Models\User;
use App\Models\RefreshToken;
use App\Models\AuditLog;
use App\Services\DeviceCertificateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    protected DeviceCertificateService $deviceCertificateService;

    public function __construct(DeviceCertificateService $deviceCertificateService)
    {
        $this->deviceCertificateService = $deviceCertificateService;
    }

    /**
     * Helper to generate both access and refresh tokens for a user
     */
    protected function generateTokens(User $user)
    {
        // Sanctum token (access token)
        $token = $user->createToken('MobileDevice')->plainTextToken;

        // Long-lived refresh token
        $rawRefreshToken = Str::random(64);
        $tokenHash = hash('sha256', $rawRefreshToken);

        RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => Carbon::now()->addDays(30),
        ]);

        return [
            'token' => $token,
            'refresh_token' => $rawRefreshToken
        ];
    }

    /**
     * Mobile Activation Code Request (Self-request via Email)
     */
    public function requestActivationCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_number' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $empNum = trim($request->employee_number);

        $employee = Employee::where(function($query) use ($empNum) {
            if (\Schema::hasColumn('employees', 'employee_number')) {
                $query->where('employee_number', $empNum)
                      ->orWhereRaw('LOWER(employee_number) = ?', [strtolower($empNum)]);
            }
            if (\Schema::hasColumn('employees', 'employee_code')) {
                $query->orWhere('employee_code', $empNum)
                      ->orWhereRaw('LOWER(employee_code) = ?', [strtolower($empNum)]);
            }
        })->first();

        $genericMessage = 'If the provided employee details are eligible, an activation code has been sent to the registered email address.';

        if (!$employee || $employee->status !== 'active') {
            \App\Models\OtpMailAuditLog::logEvent([
                'event_type' => 'code_requested',
                'identifier' => $empNum,
                'ip' => $request->ip(),
                'result' => 'failure',
                'reason_category' => 'employee_not_found_or_inactive',
            ]);

            return response()->json([
                'success' => true,
                'requires_email' => false,
                'message' => $genericMessage,
            ], 200);
        }

        $abuseService = app(\App\Services\AbuseProtectionService::class);
        if ($abuseService->isCodeRequestThrottled($employee->id, $request->ip())) {
            \App\Models\OtpMailAuditLog::logEvent([
                'event_type' => 'code_requested',
                'employee_id' => $employee->id,
                'identifier' => $employee->email ?? $empNum,
                'ip' => $request->ip(),
                'result' => 'throttled',
                'reason_category' => 'rate_limit_exceeded',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Too many activation code requests. Please try again later.',
                'error' => ['code' => 'RATE_LIMIT_EXCEEDED']
            ], 429);
        }

        // No usable email on file yet — the app must collect one before a code can be sent.
        $existingEmail = $this->resolveUsableEmail($employee);
        if (empty($existingEmail)) {
            \App\Models\OtpMailAuditLog::logEvent([
                'event_type' => 'code_requested',
                'employee_id' => $employee->id,
                'identifier' => $empNum,
                'ip' => $request->ip(),
                'result' => 'requires_email',
                'reason_category' => 'no_email_on_file',
            ]);

            return response()->json([
                'success' => true,
                'requires_email' => true,
                'message' => 'No registered email address found for this employee code. Please enter your email address to receive the activation code.',
            ], 200);
        }

        $abuseService->recordCodeRequest($employee->id, $request->ip());

        $codeService = app(\App\Services\ActivationCodeService::class);
        $result = $codeService->generateCode($employee, 'mobile_request', null, $request->ip(), $request->input('platform_device_id'));

        return response()->json([
            'success' => true,
            'requires_email' => false,
            'message' => "Activation code sent to {$this->maskEmailTwoTwo($existingEmail)}.",
            'email_masked' => $this->maskEmailTwoTwo($existingEmail),
        ], 200);
    }

    /**
     * Employee self-service: set the missing email address for their own record
     * (matched by employee code), then immediately dispatch the activation code to it.
     */
    public function setActivationEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_number' => 'required|string',
            'employee_code' => 'required|string',
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide a valid employee code and email address.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $empNum = trim($request->employee_number);
        $empCodeConfirm = trim($request->employee_code);
        $newEmail = strtolower(trim($request->email));

        $employee = Employee::where(function($query) use ($empNum) {
            if (\Schema::hasColumn('employees', 'employee_number')) {
                $query->where('employee_number', $empNum)
                      ->orWhereRaw('LOWER(employee_number) = ?', [strtolower($empNum)]);
            }
            if (\Schema::hasColumn('employees', 'employee_code')) {
                $query->orWhere('employee_code', $empNum)
                      ->orWhereRaw('LOWER(employee_code) = ?', [strtolower($empNum)]);
            }
        })->first();

        if (!$employee || $employee->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Employee record not found or inactive.',
                'error' => ['code' => 'EMPLOYEE_NOT_FOUND']
            ], 404);
        }

        // The employee code entered in the popup must match this employee's own record.
        $ownCode = strtolower(trim($employee->employee_code ?? $employee->employee_number ?? ''));
        if ($ownCode === '' || $ownCode !== strtolower($empCodeConfirm)) {
            return response()->json([
                'success' => false,
                'message' => 'The employee code you entered does not match our records.',
                'error' => ['code' => 'EMPLOYEE_CODE_MISMATCH']
            ], 422);
        }

        if (!empty($this->resolveUsableEmail($employee))) {
            return response()->json([
                'success' => false,
                'message' => 'An email address is already registered for this employee code.',
                'error' => ['code' => 'EMAIL_ALREADY_SET']
            ], 409);
        }

        $emailTaken = Employee::where('id', '!=', $employee->id)->where('email', $newEmail)->exists();
        if ($emailTaken) {
            return response()->json([
                'success' => false,
                'message' => 'This email address is already registered to another employee.',
                'error' => ['code' => 'EMAIL_TAKEN']
            ], 409);
        }

        $previousEmail = $employee->email;

        $employee->update(['email' => $newEmail]);
        if ($employee->user) {
            $employee->user->update(['email' => $newEmail]);
        }
        if (\Schema::hasTable('user')) {
            \DB::table('user')->where('employee_id', $employee->id)->update(['email' => $newEmail, 'updated_at' => now()]);
        }

        $this->sendEmailUpdateNotification($employee, $previousEmail, $newEmail, $request);

        \App\Models\OtpMailAuditLog::logEvent([
            'event_type' => 'email_updated_self_service',
            'employee_id' => $employee->id,
            'identifier' => $newEmail,
            'ip' => $request->ip(),
            'device_instance_id' => $request->input('platform_device_id'),
            'result' => 'success',
        ]);

        // Immediately dispatch the activation code to the newly set email.
        $codeService = app(\App\Services\ActivationCodeService::class);
        $codeService->generateCode($employee, 'mobile_request', null, $request->ip(), $request->input('platform_device_id'));

        $masked = $this->maskEmailTwoTwo($newEmail);

        return response()->json([
            'success' => true,
            'message' => "Email address updated. Activation code sent to {$masked}.",
            'email_masked' => $masked,
        ], 200);
    }

    /**
     * Resolve an employee's currently usable email address, treating auto-generated
     * system placeholder addresses (@system.local) as "no email on file".
     */
    private function resolveUsableEmail(Employee $employee): ?string
    {
        $email = $employee->email ?? null;
        if (empty($email) || str_contains($email, '@system.local')) {
            return null;
        }
        return $email;
    }

    /**
     * Mask an email for display in the app's toast/snackbar: first two and last two
     * characters of the local part, plus the full domain, e.g. "jo**th@gmail.com".
     */
    private function maskEmailTwoTwo(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }
        [$name, $domain] = $parts;
        $length = strlen($name);

        if ($length <= 4) {
            $masked = str_repeat('*', max(1, $length));
        } else {
            $masked = substr($name, 0, 2) . str_repeat('*', $length - 4) . substr($name, -2);
        }

        return $masked . '@' . $domain;
    }

    /**
     * Notify Support/IT that an employee updated their own email address via the
     * self-service activation flow, including device details and exact location.
     */
    private function sendEmailUpdateNotification(Employee $employee, ?string $previousEmail, string $newEmail, Request $request): void
    {
        $senderEmail = env('MAIL_FROM_ADDRESS', 'support@example.com');
        $recipients = \App\Models\SecurityConfiguration::getVal('notification_recipients', ['support@example.com']);
        if (!is_array($recipients) || empty($recipients)) {
            $recipients = ['support@example.com'];
        }

        $employeeCode = $employee->employee_code ?? $employee->employee_number ?? (string) $employee->id;

        $mail = \App\Support\EmailTemplates::emailUpdateNotification([
            'employee_name' => $employee->full_name ?? 'Employee',
            'employee_code' => $employeeCode,
            'previous_email' => $previousEmail,
            'new_email' => $newEmail,
            'ip' => $request->ip(),
            'timestamp' => now()->toDayDateTimeString(),
            'device' => [
                'device_name' => $request->input('device_name'),
                'device_model' => $request->input('device_model'),
                'platform' => $request->input('platform'),
                'os_version' => $request->input('os_version'),
                'app_version' => $request->input('app_version'),
                'device_id' => $request->input('platform_device_id'),
            ],
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
            'accuracy' => $request->input('accuracy'),
        ], $senderEmail);

        $isSent = false;
        $failureReason = null;

        try {
            Mail::html($mail['html'], function ($message) use ($recipients, $senderEmail, $mail) {
                $message->to($recipients)
                        ->from($senderEmail, 'Your Company Attendance App')
                        ->subject($mail['subject']);
            });
            $isSent = true;
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
            \Log::error('Failed to send email-update notification: ' . $failureReason);
        }

        try {
            \DB::table('email_notification_logs')->insert([
                'recipient_email' => implode(',', $recipients),
                'recipient_name' => 'Support / IT',
                'template_name' => 'employee_email_self_update_notice',
                'related_type' => 'employee',
                'related_id' => $employee->id,
                'subject' => $mail['subject'],
                'body_text' => "Employee {$employee->full_name} ({$employeeCode}) set their email to {$newEmail} (previous: " . ($previousEmail ?: 'none') . ") via the mobile activation flow. Device: " . ($request->input('device_name') ?: 'n/a') . ", IP: {$request->ip()}, Location: " . ($request->input('latitude') && $request->input('longitude') ? $request->input('latitude') . ',' . $request->input('longitude') : 'unavailable'),
                'delivery_status' => $isSent ? 'sent' : 'failed',
                'attempted_at' => now(),
                'sent_at' => $isSent ? now() : null,
                'failure_reason' => $failureReason,
                'idempotency_key' => 'email_update_notice_' . $employee->id . '_' . time(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to insert email_notification_logs for email update notice: ' . $e->getMessage());
        }

        try {
            \DB::table('audit_log')->insert([
                'user_name' => $employee->full_name,
                'user_role' => 'Employee',
                'module' => 'Attendance App',
                'source' => 'mobile',
                'action' => 'employee_email_self_update',
                'action_label' => 'Employee Updated Own Email',
                'object_type' => 'employee',
                'object_id' => $employeeCode,
                'summary' => "{$employee->full_name} ({$employeeCode}) updated their email from " . ($previousEmail ?: 'none') . " to {$newEmail} via mobile activation.",
                'new_data' => json_encode([
                    'previous_email' => $previousEmail,
                    'new_email' => $newEmail,
                    'device' => [
                        'device_name' => $request->input('device_name'),
                        'device_model' => $request->input('device_model'),
                        'platform' => $request->input('platform'),
                        'os_version' => $request->input('os_version'),
                        'app_version' => $request->input('app_version'),
                        'device_id' => $request->input('platform_device_id'),
                    ],
                    'location' => [
                        'latitude' => $request->input('latitude'),
                        'longitude' => $request->input('longitude'),
                        'accuracy' => $request->input('accuracy'),
                    ],
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'success' => $isSent ? 1 : 0,
                'error_message' => $failureReason,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to insert audit_log for email update notice: ' . $e->getMessage());
        }
    }

    /**
     * Activate employee account with 6-digit code securely
     */
    public function activate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_number' => 'required|string',
            'activation_code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
            'device_name' => 'required|string',
            'platform_device_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $empNum = trim($request->employee_number);
        $actCode = trim($request->activation_code);
        $deviceId = trim($request->platform_device_id);
        $ip = $request->ip();

        if (!ctype_digit($actCode) || strlen($actCode) !== 6) {
            return response()->json([
                'success' => false,
                'message' => 'The activation details are invalid or have expired.',
                'error' => ['code' => 'INVALID_ACTIVATION_CODE']
            ], 400);
        }

        $employee = Employee::where(function($query) use ($empNum) {
            if (\Schema::hasColumn('employees', 'employee_number')) {
                $query->where('employee_number', $empNum)
                      ->orWhereRaw('LOWER(employee_number) = ?', [strtolower($empNum)]);
            }
            if (\Schema::hasColumn('employees', 'employee_code')) {
                $query->orWhere('employee_code', $empNum)
                      ->orWhereRaw('LOWER(employee_code) = ?', [strtolower($empNum)]);
            }
        })->first();

        $genericInvalidResponse = response()->json([
            'success' => false,
            'message' => 'The activation details are invalid or have expired.',
            'error' => ['code' => 'INVALID_ACTIVATION_CODE']
        ], 400);

        if (!$employee || $employee->status !== 'active') {
            \App\Models\OtpMailAuditLog::logEvent([
                'event_type' => 'activation_failure',
                'identifier' => $empNum,
                'device_instance_id' => $deviceId,
                'ip' => $ip,
                'result' => 'failure',
                'reason_category' => 'employee_not_found_or_inactive',
            ]);
            return $genericInvalidResponse;
        }

        $abuseService = app(\App\Services\AbuseProtectionService::class);
        if ($abuseService->isActivationThrottled($employee->id, $ip, $deviceId)) {
            \App\Models\OtpMailAuditLog::logEvent([
                'event_type' => 'activation_failure',
                'employee_id' => $employee->id,
                'identifier' => $employee->email ?? $empNum,
                'device_instance_id' => $deviceId,
                'ip' => $ip,
                'result' => 'throttled',
                'reason_category' => 'rate_limit_exceeded',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Too many failed activation attempts. Please try again later.',
                'error' => ['code' => 'RATE_LIMIT_EXCEEDED']
            ], 429);
        }

        // Verify activation code
        $codeService = app(\App\Services\ActivationCodeService::class);
        $codeRecord = $codeService->verifyCode($employee, $actCode);

        // FALLBACK BINDING FOR PRE-EXISTING LEGACY TOKENS (If activation_token is stored on employee directly)
        if (!$codeRecord && !empty($employee->activation_token)) {
            $legacyToken = trim($employee->activation_token);
            if ($legacyToken === $actCode) {
                if (!$employee->activation_token_expires_at || \Carbon\Carbon::now()->lte(\Carbon\Carbon::parse($employee->activation_token_expires_at))) {
                    // Create an ActivationCode model record on the fly for legacy tokens
                    $codeRecord = \App\Models\ActivationCode::create([
                        'employee_id' => $employee->id,
                        'verifier_hash' => hash_hmac('sha256', $actCode, config('app.key', 'secret_key')),
                        'status' => 'unused',
                        'expires_at' => $employee->activation_token_expires_at ?? \Carbon\Carbon::now()->addMinutes(10),
                        'request_source' => 'hr_portal',
                        'audit_correlation_id' => (string) \Illuminate\Support\Str::uuid(),
                    ]);
                }
            }
        }

        if (!$codeRecord) {
            $abuseService->recordFailedActivation($employee->id, $ip, $deviceId);

            \App\Models\OtpMailAuditLog::logEvent([
                'event_type' => 'activation_failure',
                'employee_id' => $employee->id,
                'identifier' => $employee->email ?? $empNum,
                'device_instance_id' => $deviceId,
                'ip' => $ip,
                'result' => 'failure',
                'reason_category' => 'invalid_or_expired_code',
            ]);

            return $genericInvalidResponse;
        }

        // Execute activation and binding inside database transaction
        try {
            $bindingService = app(\App\Services\DeviceBindingService::class);
            $result = $bindingService->activateAccountAndBindDevice(
                $employee,
                $codeRecord,
                $request->password,
                [
                    'platform_device_id' => $deviceId,
                    'device_name' => $request->input('device_name', 'Mobile Device'),
                    'platform' => $request->input('platform'),
                    'os_version' => $request->input('os_version'),
                    'app_version' => $request->input('app_version'),
                    'public_key' => $request->input('public_key'),
                ],
                $ip
            );

            $certificate = $this->deviceCertificateService->issueCertificate($employee, $deviceId);

            return response()->json([
                'success' => true,
                'message' => 'Account activated successfully.',
                'data' => [
                    'token' => $result['token'],
                    'refresh_token' => $result['refresh_token'],
                    'certificate' => $certificate['certificate'],
                    'certificate_expires_at' => $certificate['certificate_expires_at'],
                    'employee' => [
                        'uuid' => !empty($employee->uuid) ? $employee->uuid : (string) $employee->id,
                        'employee_number' => !empty($employee->employee_code) ? $employee->employee_code : ($employee->employee_number ?? ''),
                        'full_name' => $employee->full_name ?? 'Employee',
                        'email' => $employee->email ?? '',
                        'designation' => $employee->designation ? $employee->designation->name : ($employee->position_title ?? null),
                        'department' => $employee->department ? $employee->department->name : null,
                        'company' => $employee->company ? $employee->company->name : null,
                        'mobile_number' => $employee->mobile_number ?? $employee->phone ?? $employee->phone_work ?? $employee->phone_personal ?? null,
                        'photo_url' => $employee->photo_url ?? null,
                    ],
                    'device' => [
                        'uuid' => (string) $result['binding']->uuid,
                        'status' => $result['binding']->status
                    ]
                ]
            ]);
        } catch (\Throwable $e) {
            $code = $e->getCode() === 409 ? 409 : 400;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Activation failed.',
                'error' => ['code' => 'ACTIVATION_FAILED']
            ], $code);
        }
    }

    /**
     * Login via mobile app
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'required|string', // email or employee_number
            'password' => 'required|string',
            'platform_device_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $loginInput = trim($request->login);
        $employee = Employee::where(function($query) use ($loginInput) {
            if (\Schema::hasColumn('employees', 'employee_number')) {
                $query->where('employee_number', $loginInput)
                      ->orWhereRaw('LOWER(employee_number) = ?', [strtolower($loginInput)]);
            }
            if (\Schema::hasColumn('employees', 'employee_code')) {
                $query->orWhere('employee_code', $loginInput)
                      ->orWhereRaw('LOWER(employee_code) = ?', [strtolower($loginInput)]);
            }
        })
        ->first();

        $user = null;
        if ($employee) {
            $user = $employee->user;
            if (!$user && !empty($employee->email)) {
                $user = User::where('email', $employee->email)->first();
            }
            if (!$user) {
                $systemEmail = ($employee->employee_code ?? $employee->employee_number ?? 'emp_' . $employee->id) . '@system.local';
                $user = User::where('email', $systemEmail)->first();
            }
        }

        if (!$employee || !$user) {
            AuditLog::create([
                'action' => 'login_failed',
                'new_values' => ['login' => $request->login, 'reason' => 'user_not_found'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid employee code or password.',
                'error' => ['code' => 'INVALID_CREDENTIALS']
            ], 401);
        }

        if ($employee->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is disabled. Please contact your administrator.',
                'error' => ['code' => 'ACCOUNT_DISABLED']
            ], 403);
        }

        $passwordMatches = Hash::check($request->password, $user->password);
        if (!$passwordMatches && \Schema::hasTable('user') && !empty($employee->email)) {
            try {
                $legacyUser = \DB::table('user')->where('email', $employee->email)->first();
                if ($legacyUser && Hash::check($request->password, $legacyUser->password)) {
                    $passwordMatches = true;
                    $user->update(['password' => $request->password]);
                }
            } catch (\Throwable $e) {}
        }

        if (!$passwordMatches) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'login_failed',
                'new_values' => ['reason' => 'invalid_password'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Invalid email/employee number or password.',
                'error' => ['code' => 'INVALID_CREDENTIALS']
            ], 401);
        }

        // A device already bound to a DIFFERENT employee may never be reused — one device, one employee.
        $deviceHeldByAnother = MobileDevice::where('platform_device_id', $request->platform_device_id)
            ->where('employee_id', '!=', $employee->id)
            ->where('status', 'approved')
            ->exists();

        if ($deviceHeldByAnother) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'login_failed',
                'module' => 'Attendance App',
                'summary' => "Login blocked: device {$request->platform_device_id} is already bound to another employee.",
                'object_type' => 'employees',
                'object_id' => $employee->employee_code ?? (string) $employee->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'success' => 0,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'This device is already registered to another employee. Please contact HR.',
                'error' => ['code' => 'DEVICE_BOUND_TO_ANOTHER_EMPLOYEE']
            ], 403);
        }

        // Verify device binding or auto-bind
        $device = $employee->devices()->where(function($q) use ($request) {
            $q->where('platform_device_id', $request->platform_device_id);
            if (\Schema::hasColumn('mobile_devices', 'uuid')) {
                $q->orWhere('uuid', $request->platform_device_id);
            }
        })->first();

        // One employee may only have one bound device at a time; a new (unrecognized) device
        // is rejected while a different device is still approved for this employee.
        if (!$device) {
            $allowMultiple = (bool) \App\Models\SecurityConfiguration::getVal('allow_multiple_devices', false);
            $hasOtherApprovedDevice = !$allowMultiple && $employee->devices()
                ->where('platform_device_id', '!=', $request->platform_device_id)
                ->where('status', 'approved')
                ->exists();

            if ($hasOtherApprovedDevice) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'login_failed',
                    'module' => 'Attendance App',
                    'summary' => "Login blocked: employee already has a different device bound (attempted new device {$request->platform_device_id}).",
                    'object_type' => 'employees',
                    'object_id' => $employee->employee_code ?? (string) $employee->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'success' => 0,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'You already have another device registered. Please ask HR to unbind it before signing in from a new device.',
                    'error' => ['code' => 'ANOTHER_DEVICE_ALREADY_BOUND']
                ], 403);
            }
        }

        $deviceName = $request->input('device_name') ?: 'Mobile Device';
        $deviceModel = $request->input('device_model') ?: $deviceName;
        $loginLocation = [
            'last_login_at' => Carbon::now(),
            'last_login_latitude' => $request->input('latitude'),
            'last_login_longitude' => $request->input('longitude'),
            'last_login_accuracy' => $request->input('accuracy'),
            'last_login_ip' => $request->ip(),
        ];

        if (!$device) {
            $device = $employee->devices()->create(array_merge([
                'platform_device_id' => $request->platform_device_id,
                'device_name' => $deviceName,
                'device_model' => $deviceModel,
                'os_version' => $request->input('os_version'),
                'app_version' => $request->input('app_version'),
                'status' => 'approved',
                'registered_at' => Carbon::now(),
            ], $loginLocation));
        } elseif ($device->status === 'revoked') {
            // HR explicitly unbound this device — do not silently resurrect it.
            // The employee must be issued a new activation code before signing in again.
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'login_failed',
                'module' => 'Attendance App',
                'summary' => "Login blocked: device {$request->platform_device_id} was revoked and requires re-activation.",
                'object_type' => 'employees',
                'object_id' => $employee->employee_code ?? (string) $employee->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'success' => 0,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Your device access was revoked by HR. Please request a new activation code to sign in again.',
                'error' => ['code' => 'DEVICE_REVOKED']
            ], 403);
        } else {
            $device->update(array_merge([
                'status' => 'approved',
                'device_name' => $deviceName,
                'device_model' => $deviceModel,
                'os_version' => $request->input('os_version') ?: $device->os_version,
                'app_version' => $request->input('app_version') ?: $device->app_version,
            ], $loginLocation));
        }

        // Keep the legacy `device_registration_status` flag (used by the HR portal's
        // status badge) in sync with the actual mobile_devices binding state.
        if ((int) $employee->device_registration_status !== 1) {
            $employee->update(['device_registration_status' => 1]);
        }

        $tokens = $this->generateTokens($user);
        $certificate = $this->deviceCertificateService->issueCertificate($employee, $request->platform_device_id);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'token' => $tokens['token'],
                'refresh_token' => $tokens['refresh_token'],
                'certificate' => $certificate['certificate'],
                'certificate_expires_at' => $certificate['certificate_expires_at'],
                'employee' => [
                    'uuid' => !empty($employee->uuid) ? $employee->uuid : (string) $employee->id,
                    'employee_number' => !empty($employee->employee_code) ? $employee->employee_code : ($employee->employee_number ?? ''),
                    'full_name' => $employee->full_name ?? 'Employee',
                    'nickname' => $employee->nickname ?? null,
                    'display_name' => !empty($employee->nickname)
                        ? trim($employee->nickname)
                        : (!empty($employee->first_name) ? trim($employee->first_name) : explode(' ', trim($employee->full_name ?? 'Employee'))[0]),
                    'email' => $employee->email ?? '',
                    'designation' => $employee->designation ? $employee->designation->name : ($employee->position_title ?? null),
                    'department' => $employee->department ? $employee->department->name : null,
                    'company' => $employee->company ? $employee->company->name : null,
                    'location' => $employee->location ? $employee->location->name : ($employee->defaultLocation ? $employee->defaultLocation->name : null),
                    'location_name' => $employee->location ? $employee->location->name : ($employee->defaultLocation ? $employee->defaultLocation->name : null),
                    'mobile_number' => $employee->mobile_number ?? $employee->phone ?? $employee->phone_work ?? $employee->phone_personal ?? null,
                    'photo_url' => $employee->photo_url ?? null,
                    'is_maintenance_staff' => (function($emp) {
                        $t = strtolower(trim($emp->position_title ?? ($emp->designation ? $emp->designation->name : '')));
                        if (preg_match('/\b(engineer|engr|eng|supervisor|foreman|lead|technician|tech|plumber|electrician|hvac|mechanic|carpenter|painter|mason|helper|assistant|laborer|handyman|maintenance|facility|facilities)\b/i', $t)) return true;
                        if (\Schema::hasTable('re_maintenance_schedule_assignees')) {
                            return \DB::table('re_maintenance_schedule_assignees')->where('employee_id', $emp->id)->exists();
                        }
                        return false;
                    })($employee),
                    'maintenance_role' => 'technician',
                ]
            ]
        ]);
    }

    /**
     * Redeem a long-lived device trust certificate for a fresh access +
     * refresh token — no password required. This is how a device that's
     * already been vetted (successfully logged in/activated before, even if
     * since logged out) silently reconnects once it's back online, after the
     * user unlocked the app offline via Quick Unlock using the phone's own
     * biometric/PIN. The certificate itself only proves "this device was
     * previously trusted" — the real, current authority is the live
     * MobileDevice.status check below, so an HR-side unbind takes effect the
     * next time this is called even though the certificate blob is unaware
     * of it.
     */
    public function redeemCertificate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'certificate' => 'required|string',
            'platform_device_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $verify = $this->deviceCertificateService->verifyCertificate($request->certificate, $request->platform_device_id);
        if (!$verify['success']) {
            return response()->json([
                'success' => false,
                'message' => $verify['message'] ?? 'Certificate is invalid or expired.',
                'error' => ['code' => 'CERTIFICATE_INVALID']
            ], 401);
        }

        $employee = Employee::find($verify['employee_id']);
        if (!$employee || $employee->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'This account is no longer active.',
                'error' => ['code' => 'ACCOUNT_DISABLED']
            ], 403);
        }

        $deviceStillApproved = MobileDevice::where('employee_id', $employee->id)
            ->where('platform_device_id', $request->platform_device_id)
            ->where('status', 'approved')
            ->exists();

        if (!$deviceStillApproved) {
            return response()->json([
                'success' => false,
                'message' => 'This device is no longer authorized. Please contact your administrator.',
                'error' => ['code' => 'DEVICE_REVOKED']
            ], 403);
        }

        $user = $employee->user;
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No linked user account found.',
                'error' => ['code' => 'NO_USER_ACCOUNT']
            ], 403);
        }

        $tokens = $this->generateTokens($user);
        $certificate = $this->deviceCertificateService->issueCertificate($employee, $request->platform_device_id);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'certificate_redeemed',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session restored.',
            'data' => [
                'token' => $tokens['token'],
                'refresh_token' => $tokens['refresh_token'],
                'certificate' => $certificate['certificate'],
                'certificate_expires_at' => $certificate['certificate_expires_at'],
                'employee' => [
                    'uuid' => !empty($employee->uuid) ? $employee->uuid : (string) $employee->id,
                    'employee_number' => !empty($employee->employee_code) ? $employee->employee_code : ($employee->employee_number ?? ''),
                    'full_name' => $employee->full_name ?? 'Employee',
                    'email' => $employee->email ?? '',
                ]
            ]
        ]);
    }

    /**
     * Refresh access and refresh tokens
     */
    public function refresh(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Refresh token validation error.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $tokenHash = hash('sha256', $request->refresh_token);
        
        $refreshToken = RefreshToken::where('token_hash', $tokenHash)
            ->where('expires_at', '>', Carbon::now())
            ->whereNull('revoked_at')
            ->first();

        if (!$refreshToken) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired refresh token.',
                'error' => ['code' => 'INVALID_REFRESH_TOKEN']
            ], 401);
        }

        $user = $refreshToken->user;
        if ($user->employee && $user->employee->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Account is inactive.',
                'error' => ['code' => 'ACCOUNT_INACTIVE']
            ], 403);
        }

        // Revoke the used refresh token
        $refreshToken->update(['revoked_at' => Carbon::now()]);

        // Generate new tokens
        $tokens = $this->generateTokens($user);

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed successfully.',
            'data' => [
                'token' => $tokens['token'],
                'refresh_token' => $tokens['refresh_token']
            ]
        ]);
    }

    /**
     * Logout and revoke active tokens
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            // Delete all personal access tokens for the user
            $user->tokens()->delete();

            // Revoke all refresh tokens for the user
            RefreshToken::where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => Carbon::now()]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'logout',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.'
        ]);
    }

    /**
     * Request OTP for Forgot Password
     */
    public function requestPasswordResetOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Employee code is required.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $code = trim($request->employee_code);
        $employee = Employee::where(function($query) use ($code) {
            if (\Schema::hasColumn('employees', 'employee_number')) {
                $query->where('employee_number', $code)
                      ->orWhereRaw('LOWER(employee_number) = ?', [strtolower($code)]);
            }
            if (\Schema::hasColumn('employees', 'employee_code')) {
                $query->orWhere('employee_code', $code)
                      ->orWhereRaw('LOWER(employee_code) = ?', [strtolower($code)]);
            }
        })->first();

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee code not found. Please check and try again.',
                'error' => ['code' => 'EMPLOYEE_NOT_FOUND']
            ], 404);
        }

        // Check for registered email
        $existingEmail = $employee->email ?? $employee->email_work ?? $employee->email_personal ?? null;
        if (empty($existingEmail) && \Schema::hasTable('user')) {
            $legacy = \DB::table('user')->where('employee_id', $employee->id)->first();
            $existingEmail = $legacy->email ?? null;
        }
        if (!empty($existingEmail) && str_contains($existingEmail, '@system.local')) {
            $existingEmail = null;
        }

        $inputEmail = $request->email ? trim($request->email) : null;
        $isNewEmail = false;
        $targetEmail = null;

        if (empty($existingEmail)) {
            if (empty($inputEmail)) {
                return response()->json([
                    'success' => false,
                    'requires_email' => true,
                    'message' => 'No registered email address found for this employee code. Please enter your email address to receive the verification OTP.',
                    'error' => ['code' => 'EMAIL_REQUIRED']
                ], 200);
            }

            if (!filter_var($inputEmail, FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'success' => false,
                    'requires_email' => true,
                    'message' => 'Please enter a valid email address.',
                    'error' => ['code' => 'INVALID_EMAIL']
                ], 422);
            }

            $targetEmail = strtolower($inputEmail);
            $isNewEmail = true;
        } else {
            $targetEmail = strtolower(trim($existingEmail));
            $isNewEmail = false;
        }

        // Cryptographically secure 6-digit OTP
        $otp = sprintf('%06d', random_int(100000, 999999));
        $hashedOtp = hash('sha256', $otp);
        $cacheKey = 'pw_reset_otp_' . strtolower($code);

        // Cache OTP with SHA-256 encryption and 10-minute expiry
        \Illuminate\Support\Facades\Cache::put($cacheKey, [
            'hashed_otp' => $hashedOtp,
            'employee_code' => $code,
            'employee_id' => $employee->id,
            'target_email' => $targetEmail,
            'is_new_email' => $isNewEmail,
            'verified' => false,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], now()->addMinutes(10));

        // Dispatch Email from it@example.com
        $this->sendOtpEmail($targetEmail, $employee->full_name, $code, $otp);

        // Audit Log
        AuditLog::create([
            'user_id' => $employee->user_id,
            'action' => 'password_reset_otp_email_sent',
            'new_values' => [
                'employee_code' => $code,
                'email' => $targetEmail,
                'is_new_email' => $isNewEmail
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        $maskedEmail = $this->maskEmailAddress($targetEmail);

        return response()->json([
            'success' => true,
            'requires_email' => false,
            'message' => "Verification OTP sent successfully to {$maskedEmail}.",
            'email_masked' => $maskedEmail,
            'expires_in_seconds' => 600
        ]);
    }

    /**
     * Verify Password Reset OTP
     */
    public function verifyPasswordResetOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_code' => 'required|string',
            'otp' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Employee code and 6-digit OTP are required.',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $code = trim($request->employee_code);
        $otp = trim($request->otp);
        $cacheKey = 'pw_reset_otp_' . strtolower($code);
        $data = \Illuminate\Support\Facades\Cache::get($cacheKey);

        if (!$data || !isset($data['expires_at']) || $data['expires_at'] < now()->timestamp) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            return response()->json([
                'success' => false,
                'message' => 'OTP has expired or is invalid. Please request a new OTP.',
                'error' => ['code' => 'OTP_EXPIRED']
            ], 400);
        }

        // Throttling failed attempts (Max 5 attempts)
        if (($data['attempts'] ?? 0) >= 5) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
            return response()->json([
                'success' => false,
                'message' => 'Too many failed verification attempts. Please request a new OTP.',
                'error' => ['code' => 'MAX_ATTEMPTS_EXCEEDED']
            ], 429);
        }

        // Timing-attack safe SHA-256 OTP verification
        $inputHash = hash('sha256', $otp);
        if (!hash_equals($data['hashed_otp'], $inputHash)) {
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;
            $remainingSeconds = max(0, $data['expires_at'] - now()->timestamp);
            if ($remainingSeconds > 0) {
                \Illuminate\Support\Facades\Cache::put($cacheKey, $data, now()->addSeconds($remainingSeconds));
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP code. Please check your email and try again.',
                'error' => ['code' => 'INVALID_OTP']
            ], 400);
        }

        // UPDATE DB EMAIL RECORD IF NEW EMAIL WAS PROVIDED
        $emailUpdated = false;
        if (!empty($data['is_new_email']) && !empty($data['target_email'])) {
            $emp = Employee::find($data['employee_id']);
            if ($emp) {
                $emp->update(['email' => $data['target_email']]);
                if ($emp->user) {
                    $emp->user->update(['email' => $data['target_email']]);
                }
                if (\Schema::hasTable('user')) {
                    \DB::table('user')
                        ->where('employee_id', $emp->id)
                        ->update(['email' => $data['target_email'], 'updated_at' => now()]);
                }
                $emailUpdated = true;
            }
        }

        // Single-use: Invalidate OTP and generate 64-character encrypted reset token valid for 15 minutes
        $rawResetToken = bin2hex(random_bytes(32));
        $hashedResetToken = hash('sha256', $rawResetToken);

        $resetTokenKey = 'pw_reset_token_' . strtolower($code);
        \Illuminate\Support\Facades\Cache::put($resetTokenKey, [
            'hashed_reset_token' => $hashedResetToken,
            'employee_id' => $data['employee_id'],
            'employee_code' => $code,
            'target_email' => $data['target_email'],
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], now()->addMinutes(15));

        // Purge OTP key to prevent replay attacks
        \Illuminate\Support\Facades\Cache::forget($cacheKey);

        return response()->json([
            'success' => true,
            'message' => 'OTP verified successfully.',
            'reset_token' => $rawResetToken,
            'email_updated' => $emailUpdated
        ]);
    }

    /**
     * Reset Password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'employee_code' => 'required|string',
            'reset_token' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            $errors = implode(' ', $validator->errors()->all());
            return response()->json([
                'success' => false,
                'message' => $errors ?: 'Validation error',
                'error' => ['code' => 'VALIDATION_FAILED', 'details' => $validator->errors()]
            ], 422);
        }

        $code = trim($request->employee_code);
        $rawResetToken = trim($request->reset_token);
        $resetTokenKey = 'pw_reset_token_' . strtolower($code);
        $tokenData = \Illuminate\Support\Facades\Cache::get($resetTokenKey);

        if (!$tokenData || !isset($tokenData['expires_at']) || $tokenData['expires_at'] < now()->timestamp) {
            \Illuminate\Support\Facades\Cache::forget($resetTokenKey);
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired password reset session. Please request a new OTP.',
                'error' => ['code' => 'INVALID_RESET_SESSION']
            ], 400);
        }

        $inputTokenHash = hash('sha256', $rawResetToken);
        if (!hash_equals($tokenData['hashed_reset_token'], $inputTokenHash)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid password reset token. Please request a new OTP.',
                'error' => ['code' => 'INVALID_TOKEN']
            ], 400);
        }

        $employee = Employee::find($tokenData['employee_id']);
        if (!$employee) {
            $employee = Employee::where(function($query) use ($code) {
                if (\Schema::hasColumn('employees', 'employee_number')) {
                    $query->where('employee_number', $code)
                          ->orWhereRaw('LOWER(employee_number) = ?', [strtolower($code)]);
                }
                if (\Schema::hasColumn('employees', 'employee_code')) {
                    $query->orWhere('employee_code', $code)
                          ->orWhereRaw('LOWER(employee_code) = ?', [strtolower($code)]);
                }
            })->first();
        }

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee not found.',
                'error' => ['code' => 'EMPLOYEE_NOT_FOUND']
            ], 404);
        }

        // Locate User account
        $user = $employee->user;
        if (!$user && !empty($employee->email)) {
            $user = User::where('email', $employee->email)->first();
        }
        if (!$user && !empty($tokenData['target_email'])) {
            $user = User::where('email', $tokenData['target_email'])->first();
        }

        if (!$user) {
            $user = User::create([
                'name' => $employee->full_name,
                'email' => $employee->email ?: ($tokenData['target_email'] ?? ($code . '@system.local')),
                'password' => Hash::make($request->password),
            ]);
            $employee->update(['user_id' => $user->id]);
        } else {
            $user->update([
                'password' => Hash::make($request->password)
            ]);
        }

        if (\Schema::hasTable('user')) {
            $legacyUser = \DB::table('user')
                ->where('email', $user->email)
                ->orWhere('employee_id', $employee->id)
                ->first();
            if ($legacyUser) {
                \DB::table('user')
                    ->where('id', $legacyUser->id)
                    ->update([
                        'password' => Hash::make($request->password),
                        'updated_at' => now(),
                    ]);
            }
        }

        // Purge single-use token immediately
        \Illuminate\Support\Facades\Cache::forget($resetTokenKey);

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'password_reset_completed',
            'new_values' => ['employee_code' => $code, 'email' => $user->email],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now log in with your new password.'
        ]);
    }

    private function maskEmailAddress(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }
        $name = $parts[0];
        $domain = $parts[1];
        $length = strlen($name);

        if ($length <= 2) {
            $maskedName = substr($name, 0, 1) . '***';
        } else {
            $maskedName = substr($name, 0, 1) . '***' . substr($name, -1);
        }

        return $maskedName . '@' . $domain;
    }

    private function sendOtpEmail(string $targetEmail, string $employeeName, string $code, string $otp): bool
    {
        $fromAddress = env('MAIL_FROM_ADDRESS', 'support@example.com');
        $fromName = env('MAIL_FROM_NAME', 'Your Company LLC');
        $subject = "Your Company LLC - Password Reset OTP Code [{$code}]";

        $htmlContent = "
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset='utf-8'>
          <title>Password Reset OTP</title>
        </head>
        <body style='font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px;'>
          <div style='max-width: 550px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.08);'>
            <div style='background-color: #0F172A; padding: 24px; text-align: center;'>
              <h2 style='color: #D4AF37; margin: 0; font-size: 20px; letter-spacing: 1.5px; text-transform: uppercase;'>Your Company PROPERTIES LLC</h2>
              <p style='color: #94A3B8; margin: 6px 0 0 0; font-size: 13px;'>Workforce Security Management</p>
            </div>
            <div style='padding: 30px 24px; text-align: center;'>
              <p style='color: #334155; font-size: 16px; margin-top: 0;'>Dear <strong>" . htmlspecialchars($employeeName) . "</strong>,</p>
              <p style='color: #64748B; font-size: 14px; line-height: 1.5;'>We received a request to reset your workforce portal password for Employee Code <strong>" . htmlspecialchars($code) . "</strong>.</p>
              
              <div style='margin: 28px 0; background-color: #F8FAFC; border: 2px dashed #0F5A6E; border-radius: 12px; padding: 18px;'>
                <span style='display: block; font-size: 12px; text-transform: uppercase; color: #64748B; font-weight: bold; letter-spacing: 1px; margin-bottom: 8px;'>Your 6-Digit OTP Code</span>
                <span style='font-size: 36px; font-weight: bold; color: #0F5A6E; letter-spacing: 10px;'>" . htmlspecialchars($otp) . "</span>
              </div>

              <p style='color: #EF4444; font-size: 13px; font-weight: bold;'>⚠️ This code is valid for 10 minutes only.</p>
              <p style='color: #64748B; font-size: 13px; line-height: 1.5;'>If you did not request a password reset, please ignore this email or contact the IT Department immediately.</p>
            </div>
            <div style='background-color: #F1F5F9; padding: 16px 24px; text-align: center; border-top: 1px solid #E2E8F0;'>
              <p style='color: #94A3B8; font-size: 12px; margin: 0;'>Sent securely from <strong>" . htmlspecialchars($fromAddress) . "</strong></p>
            </div>
          </div>
        </body>
        </html>
        ";

        $idempotencyKey = "otp_mail_" . strtolower($code) . "_" . time();
        $isSent = false;
        $failureReason = null;

        try {
            \Illuminate\Support\Facades\Mail::html($htmlContent, function ($message) use ($targetEmail, $fromAddress, $fromName, $subject) {
                $message->to($targetEmail)
                        ->from($fromAddress, $fromName)
                        ->subject($subject);
            });
            $isSent = true;
        } catch (\Throwable $e) {
            $failureReason = $e->getMessage();
            \Log::error("Failed to send OTP email to {$targetEmail}: " . $failureReason);
        }

        // Log into email_notification_logs
        try {
            \DB::table('email_notification_logs')->insert([
                'recipient_email' => $targetEmail,
                'recipient_name' => $employeeName,
                'template_name' => 'password_reset_otp',
                'related_type' => 'employee_otp',
                'subject' => $subject,
                'body_text' => "OTP code for employee {$code} sent to {$targetEmail}",
                'delivery_status' => $isSent ? 'sent' : 'failed',
                'attempted_at' => now(),
                'sent_at' => $isSent ? now() : null,
                'failure_reason' => $failureReason,
                'idempotency_key' => $idempotencyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning("Failed to insert email_notification_logs: " . $e->getMessage());
        }

        // Log into audit_log
        try {
            \DB::table('audit_log')->insert([
                'user_name' => $employeeName,
                'user_role' => 'Employee',
                'module' => 'Attendance App',
                'source' => 'system',
                'action' => 'otp_email_dispatch',
                'action_label' => 'Password Reset OTP Dispatch',
                'object_type' => 'employee',
                'object_id' => $code,
                'summary' => "Password Reset OTP " . ($isSent ? "successfully sent to {$targetEmail}" : "failed sending to {$targetEmail} (Error: {$failureReason})"),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'success' => $isSent ? 1 : 0,
                'error_message' => $failureReason,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning("Failed to insert audit_log: " . $e->getMessage());
        }

        return $isSent;
    }

    private function formatUaePhoneNumber(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        $digits = preg_replace('/[^\d]/', '', $phone);

        if (str_starts_with($digits, '00971')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '971')) {
            $digits = substr($digits, 3);
        } elseif (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        if (strlen($digits) === 9) {
            return '+971' . $digits;
        }

        return !empty($digits) ? '+' . $digits : $phone;
    }

    private function maskPhoneNumber(string $phone): string
    {
        $formatted = $this->formatUaePhoneNumber($phone);
        if (empty($formatted)) {
            return '+971 58 *** 0429';
        }

        $digits = preg_replace('/[^\d+]/', '', $formatted);
        $length = strlen($digits);
        if ($length < 7) {
            return $formatted;
        }

        if (str_starts_with($digits, '+971') && strlen($digits) === 13) {
            $operator = substr($digits, 4, 2);
            $last3 = substr($digits, -3);
            return "+971 {$operator} *** {$last3}";
        }

        $prefix = substr($digits, 0, 6);
        $suffix = substr($digits, -3);
        return "{$prefix} *** {$suffix}";
    }
}
