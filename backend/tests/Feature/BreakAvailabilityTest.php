<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Employee;
use App\Models\Company;
use App\Models\WorkLocation;
use App\Models\Shift;
use App\Models\WeeklyOffRule;
use App\Models\Role;
use App\Models\BreakPolicy;
use App\Models\AttendanceRecord;
use App\Models\AttendanceBreak;
use App\Models\AttendanceEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BreakAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $employee;
    protected $company;
    protected $location;
    protected $shift;
    protected $policy1;

    protected function setUp(): void
    {
        parent::setUp();

        $roleEmp = Role::create(['name' => 'Employee', 'slug' => 'employee']);

        $this->user = User::create([
            'name' => 'Availability Test User',
            'email' => 'avail.test@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->user->roles()->attach($roleEmp->id);

        $this->company = Company::create([
            'name' => 'AR Properties Avail',
            'code' => 'ARPA'
        ]);

        $weeklyOff = WeeklyOffRule::create([
            'company_id' => $this->company->id,
            'name' => 'Sunday Off',
            'days' => [0]
        ]);

        $this->shift = Shift::create([
            'company_id' => $this->company->id,
            'name' => 'Day Shift',
            'code' => 'DAY',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00'
        ]);

        $this->location = WorkLocation::create([
            'company_id' => $this->company->id,
            'name' => 'Main Office',
            'code' => 'HQ',
            'latitude' => 25.2048,
            'longitude' => 55.2708,
            'radius_meters' => 500,
            'is_active' => true
        ]);

        $this->employee = Employee::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'employee_number' => 'EMP-AVL-101',
            'full_name' => 'Avail Tester',
            'email' => 'avail.test@company.com',
            'assigned_shift_id' => $this->shift->id,
            'weekly_off_rule_id' => $weeklyOff->id,
            'default_location_id' => $this->location->id,
            'status' => 'active'
        ]);

        // Max 1 break per shift policy
        $this->policy1 = BreakPolicy::create([
            'company_id' => $this->company->id,
            'name' => '1 Break Flexible Policy',
            'policy_type' => 'flexible',
            'allowed_duration_minutes' => 60,
            'max_breaks_per_shift' => 1,
            'is_paid' => false,
            'requires_approval' => false,
            'is_active' => true
        ]);
    }

    /**
     * Active policy with remaining breaks returns availabilityStatus = available.
     */
    public function test_active_policy_with_remaining_breaks_returns_available()
    {
        $now = Carbon::parse('2026-08-01 10:00:00', 'UTC');
        Carbon::setTestNow($now);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => $now,
            'status' => 'present'
        ]);

        $checkInEvt = AttendanceEvent::create([
            'employee_id' => $this->employee->id,
            'attendance_record_id' => $record->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN',
            'event_time_server' => $now,
            'event_source' => 'mobile_app',
            'actor_role' => 'employee'
        ]);

        $record->update(['check_in_event_id' => $checkInEvt->id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/break/state');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'usedBreakCount' => 0,
                    'remainingBreakCount' => 1,
                    'canStartBreak' => true,
                    'availabilityStatus' => 'available',
                    'policy' => [
                        'maximumBreaksPerShift' => 1,
                        'active' => true,
                    ]
                ]
            ]);
    }

    /**
     * Active policy with zero remaining breaks returns limit_reached and canStartBreak = false.
     * Also asserts policy.active == true does NOT override reached limit.
     */
    public function test_zero_remaining_breaks_returns_limit_reached()
    {
        $startedAt = Carbon::parse('2026-08-01 11:00:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-01 12:00:00', 'UTC');
        $now       = Carbon::parse('2026-08-01 13:00:00', 'UTC');

        Carbon::setTestNow($now);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => $startedAt,
            'status' => 'present'
        ]);

        $checkInEvt = AttendanceEvent::create([
            'employee_id' => $this->employee->id,
            'attendance_record_id' => $record->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN',
            'event_time_server' => $startedAt,
            'event_source' => 'mobile_app',
            'actor_role' => 'employee'
        ]);

        $record->update(['check_in_event_id' => $checkInEvt->id]);

        // Completed 1 break (equal to max_breaks_per_shift = 1)
        AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy1->id,
            'start_time_server' => $startedAt,
            'end_time_server' => $endedAt,
            'duration_seconds' => 3600,
            'duration_minutes' => 60,
            'status' => 'completed'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/break/state');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'usedBreakCount' => 1,
                    'remainingBreakCount' => 0,
                    'canStartBreak' => false,
                    'availabilityStatus' => 'limit_reached',
                    'restrictionCode' => 'MAX_BREAKS_REACHED',
                    'restrictionMessage' => 'Maximum break count reached for this shift.',
                    'policy' => [
                        'maximumBreaksPerShift' => 1,
                        'active' => true, // Active policy does NOT override reached limit
                    ]
                ]
            ]);
    }

    /**
     * Start Break endpoint rejects request exceeding max break limit with MAX_BREAKS_REACHED.
     */
    public function test_start_break_rejects_when_limit_reached()
    {
        $startedAt = Carbon::parse('2026-08-01 11:00:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-01 12:00:00', 'UTC');
        $now       = Carbon::parse('2026-08-01 13:00:00', 'UTC');

        Carbon::setTestNow($now);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => $startedAt,
            'status' => 'present'
        ]);

        $checkInEvt = AttendanceEvent::create([
            'employee_id' => $this->employee->id,
            'attendance_record_id' => $record->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN',
            'event_time_server' => $startedAt,
            'event_source' => 'mobile_app',
            'actor_role' => 'employee'
        ]);

        $record->update(['check_in_event_id' => $checkInEvt->id]);

        AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy1->id,
            'start_time_server' => $startedAt,
            'end_time_server' => $endedAt,
            'duration_seconds' => 3600,
            'duration_minutes' => 60,
            'status' => 'completed'
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'idempotency_key' => 'attempt_extra_break'
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Maximum break count reached for this shift.',
                'error' => [
                    'code' => 'MAX_BREAKS_REACHED',
                    'message' => 'Maximum break count reached for this shift.'
                ]
            ]);
    }

    /**
     * Cancelled breaks do not consume break limit.
     */
    public function test_cancelled_breaks_do_not_consume_limit()
    {
        $startedAt = Carbon::parse('2026-08-01 11:00:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-01 11:10:00', 'UTC');
        $now       = Carbon::parse('2026-08-01 12:00:00', 'UTC');

        Carbon::setTestNow($now);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => $startedAt,
            'status' => 'present'
        ]);

        $checkInEvt = AttendanceEvent::create([
            'employee_id' => $this->employee->id,
            'attendance_record_id' => $record->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN',
            'event_time_server' => $startedAt,
            'event_source' => 'mobile_app',
            'actor_role' => 'employee'
        ]);

        $record->update(['check_in_event_id' => $checkInEvt->id]);

        // Cancelled break
        AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy1->id,
            'start_time_server' => $startedAt,
            'end_time_server' => $endedAt,
            'duration_seconds' => 600,
            'duration_minutes' => 10,
            'status' => 'cancelled'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/break/state');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'usedBreakCount' => 0,
                    'remainingBreakCount' => 1,
                    'canStartBreak' => true,
                    'availabilityStatus' => 'available',
                ]
            ]);
    }
}
