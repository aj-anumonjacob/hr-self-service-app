<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\Shift;
use App\Models\Company;
use App\Models\WeeklyOffRule;
use App\Models\Role;
use App\Services\GeofenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected $employee;
    protected $location;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Setup Role
        $role = Role::create([
            'name' => 'Employee',
            'slug' => 'employee'
        ]);

        // 2. Setup User
        $this->user = User::create([
            'name' => 'John Doe',
            'email' => 'john@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->user->roles()->attach($role->id);

        // 3. Setup Company & Off Rule
        $company = Company::create([
            'name' => 'Demo Company',
            'code' => 'ARP'
        ]);

        $weeklyOff = WeeklyOffRule::create([
            'company_id' => $company->id,
            'name' => 'Sunday Saturday',
            'days' => [0, 6]
        ]);

        $shift = Shift::create([
            'company_id' => $company->id,
            'name' => 'Standard Shift',
            'code' => 'STD'
        ]);

        // 4. Setup Work Location ( Burj Khalifa )
        $this->location = WorkLocation::create([
            'company_id' => $company->id,
            'code' => 'HQ',
            'name' => 'HQ Office',
            'latitude' => 25.204849,
            'longitude' => 55.270782,
            'check_in_radius' => 100,
            'checkout_radius' => 100,
            'default_shift_id' => $shift->id,
            'status' => 'active'
        ]);

        // 5. Setup Employee
        $this->employee = Employee::create([
            'user_id' => $this->user->id,
            'employee_number' => 'EMP101',
            'full_name' => 'John Doe',
            'email' => 'john@company.com',
            'company_id' => $company->id,
            'joining_date' => '2026-01-01',
            'status' => 'active',
            'default_location_id' => $this->location->id,
            'assigned_shift_id' => $shift->id,
            'weekly_off_rule_id' => $weeklyOff->id
        ]);
    }

    /**
     * Test successful check-in inside the geofence
     */
    public function test_check_in_successful_inside_geofence()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-in', [
                'latitude' => 25.204849, // Exact HQ latitude
                'longitude' => 55.270782, // Exact HQ longitude
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEVICE_UUID_123',
                'idempotency_key' => 'idemp_success_key_123'
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Check-in completed successfully.'
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $this->employee->id,
            'status' => 'present'
        ]);
    }

    /**
     * Test check-in fails outside the geofence
     */
    public function test_check_in_fails_outside_geofence()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-in', [
                'latitude' => 25.100000, // Very far away coords
                'longitude' => 55.100000,
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEVICE_UUID_123',
                'idempotency_key' => 'idemp_fail_key_456'
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'message' => 'You are outside the permitted work location.',
            'error' => [
                'code' => 'OUTSIDE_GEOFENCE'
            ]
        ]);
    }
}
