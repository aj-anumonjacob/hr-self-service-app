<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\Shift;
use App\Models\Department;
use App\Models\Company;
use App\Models\WeeklyOffRule;
use App\Models\Role;
use App\Models\AttendanceRecord;
use App\Models\AttendanceBreak;
use App\Models\BreakPolicy;
use App\Models\BreakPolicyAssignment;
use App\Services\BreakPolicyService;
use App\Services\OverdueBreakProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Tests\TestCase;

class BreakManagementTest extends TestCase
{
    use RefreshDatabase;

    protected $employee;
    protected $managerEmployee;
    protected $user;
    protected $managerUser;
    protected $company;
    protected $location;
    protected $shift;
    protected $department;

    protected function setUp(): void
    {
        parent::setUp();

        $roleEmp = Role::create(['name' => 'Employee', 'slug' => 'employee']);
        $roleMgr = Role::create(['name' => 'Manager', 'slug' => 'manager']);
        $roleAdmin = Role::create(['name' => 'Super Admin', 'slug' => 'super_admin']);

        $this->user = User::create([
            'name' => 'Employee User',
            'email' => 'employee@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->user->roles()->attach($roleEmp->id);

        $this->managerUser = User::create([
            'name' => 'Manager User',
            'email' => 'manager@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->managerUser->roles()->attach([$roleMgr->id, $roleAdmin->id]);

        $this->company = Company::create([
            'name' => 'Demo Company',
            'code' => 'ARP'
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id,
            'name' => 'Sales',
            'code' => 'SALES'
        ]);

        $weeklyOff = WeeklyOffRule::create([
            'company_id' => $this->company->id,
            'name' => 'Sunday Off',
            'days' => [0]
        ]);

        $this->shift = Shift::create([
            'company_id' => $this->company->id,
            'name' => 'Standard Shift',
            'code' => 'STD'
        ]);

        $this->location = WorkLocation::create([
            'company_id' => $this->company->id,
            'code' => 'HQ',
            'name' => 'HQ Office',
            'latitude' => 25.204849,
            'longitude' => 55.270782,
            'check_in_radius' => 100,
            'checkout_radius' => 100,
            'default_shift_id' => $this->shift->id,
            'status' => 'active'
        ]);

        $this->managerEmployee = Employee::create([
            'user_id' => $this->managerUser->id,
            'employee_number' => 'MGR001',
            'full_name' => 'Manager User',
            'email' => 'manager@company.com',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'joining_date' => '2026-01-01',
            'status' => 'active'
        ]);

        $this->employee = Employee::create([
            'user_id' => $this->user->id,
            'employee_number' => 'EMP101',
            'full_name' => 'John Doe',
            'email' => 'employee@company.com',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'joining_date' => '2026-01-01',
            'status' => 'active',
            'default_location_id' => $this->location->id,
            'assigned_shift_id' => $this->shift->id,
            'weekly_off_rule_id' => $weeklyOff->id,
            'reporting_manager_id' => $this->managerEmployee->id
        ]);
    }

    /**
     * Test default flexible policy creation and resolution hierarchy
     */
    public function test_break_policy_resolution_hierarchy()
    {
        $service = new BreakPolicyService();

        // 1. Company Default Policy
        $policy = $service->resolveEffectivePolicy($this->employee);
        $this->assertEquals('flexible', $policy->policy_type);
        $this->assertEquals(60, $policy->allowed_duration_minutes);

        // 2. Department Override
        $deptPolicy = BreakPolicy::create([
            'company_id' => $this->company->id,
            'name' => 'Dept Policy',
            'policy_type' => 'flexible',
            'allowed_duration_minutes' => 45,
            'max_breaks_per_shift' => 2
        ]);
        BreakPolicyAssignment::create([
            'break_policy_id' => $deptPolicy->id,
            'assignable_type' => 'department',
            'assignable_id' => $this->department->id,
            'effective_from' => now()->subDay()->toDateString()
        ]);

        $policy2 = $service->resolveEffectivePolicy($this->employee);
        $this->assertEquals('Dept Policy', $policy2->name);
        $this->assertEquals(45, $policy2->allowed_duration_minutes);

        // 3. Employee Specific Override (Highest Priority)
        $empPolicy = BreakPolicy::create([
            'company_id' => $this->company->id,
            'name' => 'VIP Emp Policy',
            'policy_type' => 'flexible',
            'allowed_duration_minutes' => 30,
            'max_breaks_per_shift' => 3
        ]);
        BreakPolicyAssignment::create([
            'break_policy_id' => $empPolicy->id,
            'assignable_type' => 'employee',
            'assignable_id' => $this->employee->id,
            'effective_from' => now()->subDay()->toDateString()
        ]);

        $policy3 = $service->resolveEffectivePolicy($this->employee);
        $this->assertEquals('VIP Emp Policy', $policy3->name);
        $this->assertEquals(30, $policy3->allowed_duration_minutes);
    }

    /**
     * Test starting break requires checked-in working status
     */
    public function test_cannot_start_break_without_check_in()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'idempotency_key' => 'start_key_test_1'
            ]);

        $response->assertStatus(400);
        $response->assertJson([
            'success' => false,
            'error' => ['code' => 'NOT_CHECKED_IN']
        ]);
    }

    /**
     * Test successful break start and single active break enforcement
     */
    public function test_start_break_success_and_active_break_limit()
    {
        // 1. Perform Check-in
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-in', [
                'latitude' => 25.204849,
                'longitude' => 55.270782,
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEVICE_123',
                'idempotency_key' => 'idemp_checkin_123'
            ]);

        // 2. Start Break
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'reason' => 'Lunch Break',
                'idempotency_key' => 'idemp_start_break_1'
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('attendance_breaks', [
            'employee_id' => $this->employee->id,
            'status' => 'active'
        ]);

        // 3. Duplicate start break request fails
        $dupResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'reason' => 'Second Break Attempt'
            ]);

        $dupResponse->assertStatus(400);
        $dupResponse->assertJson([
            'success' => false,
            'error' => ['code' => 'BREAK_ALREADY_ACTIVE']
        ]);
    }

    /**
     * Test checkout is blocked while break is active
     */
    public function test_checkout_blocked_while_break_active()
    {
        // Check-in
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-in', [
                'latitude' => 25.204849,
                'longitude' => 55.270782,
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEVICE_123',
                'idempotency_key' => 'idemp_checkin_123'
            ]);

        // Start Break
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'idempotency_key' => 'idemp_start_break_1'
            ]);

        // Attempt Checkout -> Must fail with BREAK_ACTIVE
        $checkoutResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-out', [
                'latitude' => 25.204849,
                'longitude' => 55.270782,
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEVICE_123',
                'idempotency_key' => 'idemp_checkout_fail'
            ]);

        $checkoutResponse->assertStatus(400);
        $checkoutResponse->assertJson([
            'success' => false,
            'error' => ['code' => 'BREAK_ACTIVE']
        ]);
    }

    /**
     * Test end break and max break count limit
     */
    public function test_end_break_and_max_breaks_limit()
    {
        // Check-in
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-in', [
                'latitude' => 25.204849,
                'longitude' => 55.270782,
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEVICE_123',
                'idempotency_key' => 'idemp_checkin_123'
            ]);

        // Start Break 1
        $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'idempotency_key' => 'idemp_start_break_1'
            ]);

        // End Break 1
        $endResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/end-break', [
                'idempotency_key' => 'idemp_end_break_1'
            ]);

        $endResponse->assertStatus(200);
        $endResponse->assertJson(['success' => true]);

        // Attempt Start Break 2 (Default max breaks = 1) -> Must fail with MAX_BREAKS_REACHED
        $break2Response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'idempotency_key' => 'idemp_start_break_2'
            ]);

        $break2Response->assertStatus(400);
        $break2Response->assertJson([
            'success' => false,
            'error' => ['code' => 'MAX_BREAKS_REACHED']
        ]);
    }

    /**
     * Test overdue break background processor and notification audit logging
     */
    public function test_overdue_break_processor_and_manager_correction()
    {
        $today = now()->toDateString();
        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'date' => $today,
            'status' => 'present'
        ]);

        $event = \App\Models\AttendanceEvent::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'check_in',
            'event_time_server' => now(),
            'event_time_device' => now(),
            'latitude' => 25.204849,
            'longitude' => 55.270782,
            'idempotency_key' => 'event_key_test_123'
        ]);

        $record->update(['check_in_event_id' => $event->id]);

        // Create active break started 70 minutes ago (expected duration: 60 mins -> 10 mins overdue)
        $startedAt = now()->subMinutes(70);
        $expectedEnd = now()->subMinutes(10);

        $break = AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'start_time_server' => $startedAt,
            'expected_end_at' => $expectedEnd,
            'status' => 'active',
            'notification_series_status' => 'pending'
        ]);

        // Run overdue break command
        $this->artisan('app:process-overdue-breaks')->assertExitCode(0);

        $break->refresh();
        $this->assertEquals(1, $break->overdue_notification_count);
        $this->assertEquals('active', $break->notification_series_status);

        $this->assertDatabaseHas('break_notification_logs', [
            'break_id' => $break->id,
            'employee_id' => $this->employee->id,
            'recipient_role' => 'employee'
        ]);

        // Manager corrects break
        $mgrResponse = $this->actingAs($this->managerUser)
            ->postJson("/api/v1/manager/breaks/{$break->uuid}/correct", [
                'action' => 'close',
                'reason' => 'Approved extended break for medical reasons'
            ]);

        $mgrResponse->assertStatus(200);

        $break->refresh();
        $this->assertEquals('completed', $break->status);
        $this->assertEquals('stopped', $break->notification_series_status);
    }
}
