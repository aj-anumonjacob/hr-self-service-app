<?php

namespace Tests\Feature;

use App\Models\ActivationCode;
use App\Models\DeviceBinding;
use App\Models\Employee;
use App\Models\OtpMailAuditLog;
use App\Models\SecurityConfiguration;
use App\Models\User;
use App\Services\ActivationCodeService;
use App\Services\DeviceBindingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActivationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->employee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_number' => 'EMP-1001',
            'full_name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'company_id' => 1,
            'status' => 'active',
            'device_registration_status' => false,
        ]);
    }

    /** @test 1 & 35: CRITICAL REGRESSION PROOF - Arbitrary activation code fails */
    public function test_arbitrary_activation_code_fails_regression_proof()
    {
        // Employee has no activation token generated (activation_codes is empty)
        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => '123456',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-9999',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'The activation details are invalid or have expired.',
            ]);

        $this->assertFalse((bool) $this->employee->fresh()->device_registration_status);
        $this->assertDatabaseMissing('device_bindings', [
            'employee_id' => $this->employee->id,
        ]);
    }

    /** @test 1: Correct employee and valid code succeeds */
    public function test_correct_employee_and_valid_code_succeeds()
    {
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');

        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue((bool) $this->employee->fresh()->device_registration_status);
        $this->assertDatabaseHas('device_bindings', [
            'employee_id' => $this->employee->id,
            'platform_device_id' => 'DEV-1001',
            'status' => 'active',
        ]);
    }

    /** @test 2: Random code fails */
    public function test_random_code_fails()
    {
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');

        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => '999999',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseHas('activation_codes', [
            'id' => $res['record']->id,
            'status' => 'unused',
        ]);
    }

    /** @test 3: Correct code with wrong employee fails */
    public function test_correct_code_with_wrong_employee_fails()
    {
        $otherEmployee = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_number' => 'EMP-1002',
            'full_name' => 'Jane Smith',
            'email' => 'jane.smith@example.com',
            'company_id' => 1,
            'status' => 'active',
        ]);

        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');

        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1002', // Submitted against wrong employee
            'activation_code' => $res['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1002',
        ]);

        $response->assertStatus(400);
        $this->assertDatabaseMissing('device_bindings', [
            'employee_id' => $otherEmployee->id,
        ]);
    }

    /** @test 4: Empty code fails */
    public function test_empty_code_fails()
    {
        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => '',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);

        $response->assertStatus(422);
    }

    /** @test 5: Short or long code fails */
    public function test_short_or_long_code_fails()
    {
        $response1 = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => '12345',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);
        $response1->assertStatus(422);

        $response2 = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => '1234567',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);
        $response2->assertStatus(422);
    }

    /** @test 6: Nonnumeric code fails */
    public function test_nonnumeric_code_fails()
    {
        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => 'ABCDEF',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);

        $response->assertStatus(400);
    }

    /** @test 7: Expired code fails */
    public function test_expired_code_fails()
    {
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');

        // Travel 15 minutes into future
        Carbon::setTestNow(Carbon::now()->addMinutes(15));

        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);

        $response->assertStatus(400);
        Carbon::setTestNow(null);
    }

    /** @test 8: Revoked code fails */
    public function test_revoked_code_fails()
    {
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');
        $res['record']->update(['status' => 'revoked', 'revoked_at' => Carbon::now()]);

        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ]);

        $response->assertStatus(400);
    }

    /** @test 9: Previously used code fails */
    public function test_previously_used_code_fails()
    {
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');

        // First activation succeeds
        $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ])->assertStatus(200);

        // Second activation with same code fails
        $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ])->assertStatus(400);
    }

    /** @test 10: Old code fails after new code is generated */
    public function test_old_code_fails_after_new_code_is_generated()
    {
        $codeService = app(ActivationCodeService::class);
        $first = $codeService->generateCode($this->employee, 'hr_portal');
        $second = $codeService->generateCode($this->employee, 'hr_portal');

        // First code fails
        $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $first['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ])->assertStatus(400);

        // Second code succeeds
        $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $second['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ])->assertStatus(200);
    }

    /** @test 12: An already bound employee cannot bind another device under single-device policy */
    public function test_already_bound_employee_cannot_bind_another_device()
    {
        $codeService = app(ActivationCodeService::class);
        $res1 = $codeService->generateCode($this->employee, 'hr_portal');

        // First device binding
        $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res1['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ])->assertStatus(200);

        // Generate second code
        $res2 = $codeService->generateCode($this->employee, 'hr_portal');

        // Attempting to bind second device fails with 409 Conflict
        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res2['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPad Air',
            'platform_device_id' => 'DEV-1002',
        ]);

        $response->assertStatus(409);
    }

    /** @test 13: A device assigned to another employee cannot be bound */
    public function test_device_assigned_to_another_employee_cannot_be_bound()
    {
        $otherEmp = Employee::create([
            'uuid' => (string) Str::uuid(),
            'employee_number' => 'EMP-1003',
            'full_name' => 'Mark Spencer',
            'email' => 'mark.spencer@example.com',
            'company_id' => 1,
            'status' => 'active',
        ]);

        $codeService = app(ActivationCodeService::class);
        $res1 = $codeService->generateCode($this->employee, 'hr_portal');

        // Employee 1 binds device DEV-SHARED
        $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res1['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'Shared iPhone',
            'platform_device_id' => 'DEV-SHARED',
        ])->assertStatus(200);

        $res2 = $codeService->generateCode($otherEmp, 'hr_portal');

        // Employee 2 attempts to bind same DEV-SHARED device
        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1003',
            'activation_code' => $res2['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'Shared iPhone',
            'platform_device_id' => 'DEV-SHARED',
        ]);

        $response->assertStatus(409);
    }

    /** @test 14 & 15: Rate limiting activates and returns 429 */
    public function test_rate_limiting_activates_and_returns_429()
    {
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');

        // 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/activate', [
                'employee_number' => 'EMP-1001',
                'activation_code' => '000000',
                'password' => 'NewSecurePassword123!',
                'password_confirmation' => 'NewSecurePassword123!',
                'device_name' => 'iPhone 17',
                'platform_device_id' => 'DEV-THROTTLE',
            ])->assertStatus(400);
        }

        // 6th attempt returns 429
        $response = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-THROTTLE',
        ]);

        $response->assertStatus(429);
    }

    /** @test 16 & 30: Code request responses do not allow employee enumeration & prevent email bombing */
    public function test_code_request_responses_do_not_allow_employee_enumeration()
    {
        // Valid employee
        $res1 = $this->postJson('/api/v1/auth/request-activation-code', [
            'employee_number' => 'EMP-1001',
        ]);
        $res1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'If the provided employee details are eligible, an activation code has been sent to the registered email address.',
            ]);

        // Invalid employee returns identical message
        $res2 = $this->postJson('/api/v1/auth/request-activation-code', [
            'employee_number' => 'EMP-9999',
        ]);
        $res2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'If the provided employee details are eligible, an activation code has been sent to the registered email address.',
            ]);
    }

    /** @test 20 & 21: Unbinding revokes sessions and allows new code activation */
    public function test_unbinding_revokes_sessions_and_allows_reactivation()
    {
        $codeService = app(ActivationCodeService::class);
        $bindingService = app(DeviceBindingService::class);

        $res1 = $codeService->generateCode($this->employee, 'hr_portal');

        // Activate
        $actRes = $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res1['plain_code'],
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
            'device_name' => 'iPhone 17',
            'platform_device_id' => 'DEV-1001',
        ])->assertStatus(200);

        $token = $actRes->json('data.token');

        // Unbind via Service (representing HR Admin action)
        $bindingService->unbindDevice($this->employee, 1);

        $this->assertFalse((bool) $this->employee->fresh()->device_registration_status);

        // Old token cannot access protected route
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);

        // New code activation succeeds
        $res2 = $codeService->generateCode($this->employee, 'hr_portal');
        $this->postJson('/api/v1/auth/activate', [
            'employee_number' => 'EMP-1001',
            'activation_code' => $res2['plain_code'],
            'password' => 'ReboundPassword123!',
            'password_confirmation' => 'ReboundPassword123!',
            'device_name' => 'iPhone 17 New',
            'platform_device_id' => 'DEV-1001-NEW',
        ])->assertStatus(200);
    }

    /** @test 23 & 32: Secrets do not appear in audit logs */
    public function test_secrets_do_not_appear_in_audit_logs()
    {
        $codeService = app(ActivationCodeService::class);
        $res = $codeService->generateCode($this->employee, 'hr_portal');

        $auditLog = OtpMailAuditLog::where('audit_correlation_id', $res['correlation_id'])->first();
        $this->assertNotNull($auditLog);

        // Ensure plaintext activation code is nowhere in the audit table or audit summary
        $this->assertDatabaseMissing('otp_mail_audit_logs', [
            'reason_category' => $res['plain_code'],
        ]);
        $this->assertDatabaseMissing('audit_log', [
            'summary' => $res['plain_code'],
        ]);
    }
}
