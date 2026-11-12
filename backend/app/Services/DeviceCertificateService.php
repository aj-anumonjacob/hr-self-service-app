<?php

namespace App\Services;

use App\Models\DeviceCertificate;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Long-lived, signed "device trust certificate" — offline-redeemable proof
 * that this employee successfully activated/logged into THIS specific
 * device, issued once at every successful login/activation and re-issued on
 * every successful redemption. Deliberately survives a mobile-side logout
 * (only an explicit "Forget this device" action or an HR unbind removes it)
 * so a device that's already been vetted doesn't need a live network call
 * just to get back in — the phone's own biometric/PIN (see QuickUnlockScreen)
 * is the re-entry gate, and this certificate is what it's redeemable for.
 *
 * Verification is intentionally stateless — same design as
 * OfflineTokenService — the signature + expiry check is everything; the
 * `device_certificates` table is audit-only. Actual revocation happens via
 * the live MobileDevice.status check performed by the redemption endpoint,
 * not by invalidating the certificate blob itself.
 */
class DeviceCertificateService
{
    private const TTL_DAYS = 90;

    public function issueCertificate(Employee $employee, string $platformDeviceId): array
    {
        $issuedAt = Carbon::now();
        $expiresAt = $issuedAt->copy()->addDays(self::TTL_DAYS);

        $payload = [
            // The `employees` table has no uuid column — the numeric primary
            // key is the only reliably-populated stable identifier.
            'employee_id' => $employee->id,
            'platform_device_id' => $platformDeviceId,
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $certificate = Crypt::encryptString(json_encode($payload));

        DeviceCertificate::create([
            'employee_id' => $employee->id,
            'platform_device_id' => $platformDeviceId,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ]);

        return [
            'certificate' => $certificate,
            'certificate_expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{success: bool, employee_id?: int, message?: string}
     */
    public function verifyCertificate(string $certificate, string $platformDeviceId): array
    {
        try {
            $decrypted = Crypt::decryptString($certificate);
            $payload = json_decode($decrypted, true);

            if (!is_array($payload) || empty($payload['employee_id']) || empty($payload['platform_device_id'])) {
                return ['success' => false, 'message' => 'Malformed certificate.'];
            }

            if ($payload['platform_device_id'] !== $platformDeviceId) {
                return ['success' => false, 'message' => 'Certificate device mismatch.'];
            }

            $expiresAt = Carbon::parse($payload['expires_at']);
            if (Carbon::now()->gt($expiresAt)) {
                return ['success' => false, 'message' => 'Certificate has expired.'];
            }

            return ['success' => true, 'employee_id' => (int) $payload['employee_id']];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Certificate verification failed.'];
        }
    }
}
