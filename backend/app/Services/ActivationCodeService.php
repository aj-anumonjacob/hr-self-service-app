<?php

namespace App\Services;

use App\Models\ActivationCode;
use App\Models\Employee;
use App\Models\OtpMailAuditLog;
use App\Models\SecurityConfiguration;
use App\Support\EmailTemplates;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ActivationCodeService
{
    /**
     * Generate a new activation code for an employee and send via email if requested.
     */
    public function generateCode(
        Employee $employee,
        string $source = 'mobile_request',
        ?int $adminId = null,
        ?string $ip = null,
        ?string $deviceId = null
    ): array {
        $correlationId = (string) Str::uuid();

        // 1. Revoke all previous unused codes for this employee
        ActivationCode::where('employee_id', $employee->id)
            ->where('status', 'unused')
            ->update([
                'status' => 'revoked',
                'revoked_at' => Carbon::now(),
            ]);

        // 2. Generate 6-digit cryptographically secure numeric code
        $plainCode = sprintf("%06d", random_int(100000, 999999));
        $verifierHash = hash_hmac('sha256', $plainCode, config('app.key', 'secret_key'));

        $expiryMinutes = (int) SecurityConfiguration::getVal('code_expiry_minutes', 10);
        $expiresAt = Carbon::now()->addMinutes($expiryMinutes);

        // 3. Create activation record
        $record = ActivationCode::create([
            'employee_id' => $employee->id,
            'verifier_hash' => $verifierHash,
            'status' => 'unused',
            'expires_at' => $expiresAt,
            'request_source' => $source,
            'administrator_id' => $adminId,
            'audit_correlation_id' => $correlationId,
        ]);

        $mailStatus = 'not_sent';
        $failureReason = null;
        $employeeCode = $employee->employee_code ?? $employee->employee_number ?? (string) $employee->id;
        $senderEmail = SecurityConfiguration::getVal('sender_email', 'support@example.com');

        // 4. Send email if employee has an official stored email
        if (!empty($employee->email) && filter_var($employee->email, FILTER_VALIDATE_EMAIL)) {
            $mail = EmailTemplates::activationCode($employee->full_name ?? 'Employee', $employeeCode, $plainCode, $expiryMinutes, $senderEmail);

            try {
                Mail::html($mail['html'], function ($message) use ($employee, $senderEmail, $mail) {
                    $message->from($senderEmail, 'Your Company Support')
                        ->to($employee->email)
                        ->subject($mail['subject']);
                });
                $mailStatus = 'sent';
            } catch (\Throwable $e) {
                $mailStatus = 'failed';
                $failureReason = $e->getMessage();
                // If mail delivery fails, revoke the generated code immediately so user isn't stuck with an unreceived active code
                $record->update([
                    'status' => 'revoked',
                    'revoked_at' => Carbon::now(),
                ]);
            }

            // Surface in the HR "OTP & Mail Audit History" tab
            try {
                \DB::table('email_notification_logs')->insert([
                    'recipient_email' => $employee->email,
                    'recipient_name' => $employee->full_name,
                    'template_name' => 'activation_code',
                    'related_type' => 'employee_activation',
                    'related_id' => $employee->id,
                    'subject' => $mail['subject'],
                    'body_text' => "Device activation code sent to {$employee->email} for employee {$employeeCode} (source: {$source}).",
                    'delivery_status' => $mailStatus === 'sent' ? 'sent' : 'failed',
                    'attempted_at' => now(),
                    'sent_at' => $mailStatus === 'sent' ? now() : null,
                    'failure_reason' => $failureReason,
                    'idempotency_key' => 'activation_mail_' . $correlationId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Failed to insert email_notification_logs for activation code: ' . $e->getMessage());
            }
        }

        // 5. Audit log (never storing plaintext code)
        OtpMailAuditLog::logEvent([
            'event_type' => $source === 'hr_portal' ? 'code_generated' : 'code_requested',
            'employee_id' => $employee->id,
            'identifier' => $employee->email ?? $employee->employee_number,
            'administrator_id' => $adminId,
            'device_instance_id' => $deviceId,
            'ip' => $ip,
            'result' => $mailStatus === 'failed' ? 'failure' : 'success',
            'delivery_status' => $mailStatus,
            'audit_correlation_id' => $correlationId,
        ]);

        return [
            'record' => $record,
            'plain_code' => $plainCode, // Exposed ONCE during generation call only
            'expires_at' => $expiresAt,
            'mail_status' => $mailStatus,
            'correlation_id' => $correlationId,
            'target_email' => $employee->email,
        ];
    }

    /**
     * Verify a submitted 6-digit code against stored verifiers for an employee.
     */
    public function verifyCode(Employee $employee, string $submittedCode): ?ActivationCode
    {
        $submittedCode = trim($submittedCode);
        if (strlen($submittedCode) !== 6 || !ctype_digit($submittedCode)) {
            return null;
        }

        $verifierHash = hash_hmac('sha256', $submittedCode, config('app.key', 'secret_key'));

        $codeRecord = ActivationCode::where('employee_id', $employee->id)
            ->where('verifier_hash', $verifierHash)
            ->where('status', 'unused')
            ->first();

        if (!$codeRecord) {
            return null;
        }

        if (Carbon::now()->gt($codeRecord->expires_at)) {
            $codeRecord->update([
                'status' => 'expired',
            ]);
            return null;
        }

        return $codeRecord;
    }
}
