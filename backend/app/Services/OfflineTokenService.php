<?php

namespace App\Services;

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;

class OfflineTokenService
{
    /**
     * Generate signed offline authorization token for employee
     */
    public function generateAuthorization(Employee $employee, string $deviceId, array $policy): array
    {
        $validFrom = Carbon::now();
        $validTo = Carbon::now()->addHours(24); // Token valid for 24 hours
        
        $payload = [
            'employee_uuid' => $employee->uuid,
            'device_id' => $deviceId,
            'valid_from' => $validFrom->toIso8601String(),
            'valid_to' => $validTo->toIso8601String(),
            'policy' => $policy
        ];
        
        // Encrypt and sign using Laravel Crypt (uses AES-256-CBC with HMAC signature automatically)
        $signature = Crypt::encryptString(json_encode($payload));
        
        return [
            'valid_from' => $payload['valid_from'],
            'valid_to' => $payload['valid_to'],
            'policy_data' => $payload['policy'],
            'signature' => $signature
        ];
    }

    /**
     * Parse and verify a signed token signature
     */
    public function verifyToken(string $signature, Employee $employee, string $deviceId): array
    {
        try {
            $decrypted = Crypt::decryptString($signature);
            $payload = json_decode($decrypted, true);
            
            if ($payload['employee_uuid'] !== $employee->uuid) {
                return ['success' => false, 'message' => 'Employee mismatch in signed token.'];
            }
            
            if ($payload['device_id'] !== $deviceId) {
                return ['success' => false, 'message' => 'Device ID mismatch.'];
            }
            
            $now = Carbon::now();
            $validFrom = Carbon::parse($payload['valid_from']);
            $validTo = Carbon::parse($payload['valid_to']);
            
            if ($now->lt($validFrom) || $now->gt($validTo)) {
                return ['success' => false, 'message' => 'Token has expired or is not yet valid.'];
            }
            
            return [
                'success' => true,
                'payload' => $payload
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Cryptographic verification failed: ' . $e->getMessage()];
        }
    }
}
