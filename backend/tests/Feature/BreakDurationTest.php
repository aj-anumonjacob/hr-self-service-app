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

class BreakDurationTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $employee;
    protected $company;
    protected $location;
    protected $shift;
    protected $policy60;
    protected $policy30;

    protected function setUp(): void
    {
        parent::setUp();

        $roleEmp = Role::create(['name' => 'Employee', 'slug' => 'employee']);

        $this->user = User::create([
            'name' => 'Duration Test User',
            'email' => 'duration.test@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->user->roles()->attach($roleEmp->id);

        $this->company = Company::create([
            'name' => 'Demo Company Test',
            'code' => 'ARPT'
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
            'employee_number' => 'EMP-DUR-101',
            'full_name' => 'Duration Tester',
            'email' => 'duration.test@company.com',
            'assigned_shift_id' => $this->shift->id,
            'weekly_off_rule_id' => $weeklyOff->id,
            'default_location_id' => $this->location->id,
            'status' => 'active'
        ]);

        $this->policy60 = BreakPolicy::create([
            'company_id' => $this->company->id,
            'name' => '60 Min Flexible Policy',
            'policy_type' => 'flexible',
            'allowed_duration_minutes' => 60,
            'max_breaks_per_shift' => 2,
            'is_paid' => false,
            'requires_approval' => false,
            'is_active' => true
        ]);

        $this->policy30 = BreakPolicy::create([
            'company_id' => $this->company->id,
            'name' => '30 Min Policy',
            'policy_type' => 'flexible',
            'allowed_duration_minutes' => 30,
            'max_breaks_per_shift' => 2,
            'is_paid' => false,
            'requires_approval' => false,
            'is_active' => true
        ]);
    }

    /**
     * Test the exact 1:52 PM (13:52) to 3:43 PM (15:43) break duration scenario.
     * Actual = 6,660 seconds (1h 51m).
     * Allowed = 3,600 seconds (60m).
     * Overdue = 3,060 seconds (51m).
     */
    public function test_152_to_343_break_duration_calculation()
    {
        $startedAt = Carbon::parse('2026-08-01 13:52:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-01 15:43:00', 'UTC');

        Carbon::setTestNow($startedAt);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => Carbon::parse('2026-08-01 09:00:00', 'UTC'),
            'status' => 'present'
        ]);

        $break = AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy60->id,
            'start_time_server' => $startedAt,
            'expected_end_at' => $startedAt->copy()->addMinutes(60),
            'status' => 'active'
        ]);

        Carbon::setTestNow($endedAt);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/end-break', [
                'idempotency_key' => 'test_152_end_key'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'actualDurationSeconds' => 6660,
                    'allowedDurationSeconds' => 3600,
                    'overdueDurationSeconds' => 3060,
                    'wasOverdue' => true,
                ]
            ]);

        $break->refresh();
        $this->assertEquals(6660, $break->duration_seconds);
        $this->assertEquals(3060, $break->overdue_duration_seconds);
        $this->assertEquals('completed', $break->status);

        // Verify BREAK_ENDED event
        $evt = AttendanceEvent::where('attendance_break_id', $break->id)
            ->where('event_type', 'BREAK_ENDED')
            ->first();

        $this->assertNotNull($evt);
        $this->assertEquals(6660, $evt->metadata['actualDurationSeconds']);
        $this->assertEquals(3600, $evt->metadata['allowedDurationSeconds']);
        $this->assertEquals(3060, $evt->metadata['overdueDurationSeconds']);
        $this->assertTrue($evt->metadata['wasOverdue']);

        // Verify Recents API payload
        $recentsResp = $this->actingAs($this->user)
            ->getJson('/api/v1/me/attendance-events?filter=breaks');

        $recentsResp->assertStatus(200);
        $items = $recentsResp->json('data.items');
        $this->assertNotEmpty($items);
        $endedEvt = collect($items)->firstWhere('type', 'BREAK_ENDED');
        $this->assertNotNull($endedEvt);
        $this->assertEquals(6660, $endedEvt['details']['actualDurationSeconds']);
        $this->assertEquals(3600, $endedEvt['details']['allowedDurationSeconds']);
        $this->assertEquals(3060, $endedEvt['details']['overdueDurationSeconds']);
        $this->assertTrue($endedEvt['details']['wasOverdue']);
    }

    /**
     * Test a custom 30-minute policy produces correct overdue duration (4,860s / 81m).
     */
    public function test_custom_policy_allowed_duration()
    {
        $startedAt = Carbon::parse('2026-08-01 13:52:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-01 15:43:00', 'UTC');

        Carbon::setTestNow($startedAt);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => Carbon::parse('2026-08-01 09:00:00', 'UTC'),
            'status' => 'present'
        ]);

        $break = AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy30->id,
            'start_time_server' => $startedAt,
            'expected_end_at' => $startedAt->copy()->addMinutes(30),
            'status' => 'active'
        ]);

        Carbon::setTestNow($endedAt);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/end-break', [
                'idempotency_key' => 'test_30m_key'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'actualDurationSeconds' => 6660,
                    'allowedDurationSeconds' => 1800,
                    'overdueDurationSeconds' => 4860,
                    'wasOverdue' => true,
                ]
            ]);
    }

    /**
     * Test break duration calculation crossing midnight (23:30 to 01:15 = 6,300 seconds / 1h 45m).
     */
    public function test_break_crossing_midnight()
    {
        $startedAt = Carbon::parse('2026-08-01 23:30:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-02 01:15:00', 'UTC');

        Carbon::setTestNow($startedAt);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => Carbon::parse('2026-08-01 18:00:00', 'UTC'),
            'status' => 'present'
        ]);

        $break = AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy60->id,
            'start_time_server' => $startedAt,
            'expected_end_at' => $startedAt->copy()->addMinutes(60),
            'status' => 'active'
        ]);

        Carbon::setTestNow($endedAt);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/end-break', [
                'idempotency_key' => 'test_midnight_key'
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'actualDurationSeconds' => 6300,
                    'allowedDurationSeconds' => 3600,
                    'overdueDurationSeconds' => 2700,
                    'wasOverdue' => true,
                ]
            ]);
    }

    /**
     * Test duplicate end break request returns idempotent response and avoids duplicate events.
     */
    public function test_duplicate_end_break_request_idempotency()
    {
        $startedAt = Carbon::parse('2026-08-01 14:00:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-01 14:45:00', 'UTC');

        Carbon::setTestNow($startedAt);

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => Carbon::parse('2026-08-01 09:00:00', 'UTC'),
            'status' => 'present'
        ]);

        AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy60->id,
            'start_time_server' => $startedAt,
            'expected_end_at' => $startedAt->copy()->addMinutes(60),
            'status' => 'active'
        ]);

        Carbon::setTestNow($endedAt);

        $resp1 = $this->actingAs($this->user)->postJson('/api/v1/me/attendance/end-break', [
            'idempotency_key' => 'dup_test_key'
        ]);
        $resp1->assertStatus(200);

        $eventCount1 = AttendanceEvent::where('event_type', 'BREAK_ENDED')->count();
        $this->assertEquals(1, $eventCount1);

        // Second duplicate request
        $resp2 = $this->actingAs($this->user)->postJson('/api/v1/me/attendance/end-break', [
            'idempotency_key' => 'dup_test_key'
        ]);
        $resp2->assertStatus(200);

        $eventCount2 = AttendanceEvent::where('event_type', 'BREAK_ENDED')->count();
        $this->assertEquals(1, $eventCount2);
    }

    /**
     * Test historical repair command app:repair-break-events.
     */
    public function test_historical_repair_command()
    {
        $startedAt = Carbon::parse('2026-08-01 12:00:00', 'UTC');
        $endedAt   = Carbon::parse('2026-08-01 13:50:00', 'UTC'); // 1h 50m = 6600s

        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => Carbon::parse('2026-08-01 09:00:00', 'UTC'),
            'status' => 'present'
        ]);

        $break = AttendanceBreak::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'break_policy_id' => $this->policy60->id,
            'start_time_server' => $startedAt,
            'end_time_server' => $endedAt,
            'duration_seconds' => 60, // wrong historical value!
            'duration_minutes' => 1,
            'overdue_duration_seconds' => 0,
            'status' => 'completed'
        ]);

        // Historical event with wrong/missing duration fields
        AttendanceEvent::create([
            'attendance_record_id' => $record->id,
            'attendance_break_id' => $break->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'BREAK_ENDED',
            'event_time_server' => $endedAt,
            'event_source' => 'historical',
            'metadata' => [
                'duration_minutes' => 1
            ]
        ]);

        $this->artisan('app:repair-break-events')
            ->assertExitCode(0);

        $break->refresh();
        $this->assertEquals(6600, $break->duration_seconds);
        $this->assertEquals(3000, $break->overdue_duration_seconds); // 6600 - 3600 = 3000s (50m)

        $event = AttendanceEvent::where('attendance_break_id', $break->id)
            ->where('event_type', 'BREAK_ENDED')
            ->first();

        $this->assertEquals(6600, $event->metadata['actualDurationSeconds']);
        $this->assertEquals(3600, $event->metadata['allowedDurationSeconds']);
        $this->assertEquals(3000, $event->metadata['overdueDurationSeconds']);
        $this->assertTrue($event->metadata['wasOverdue']);
        $this->assertNotNull($event->metadata['repaired_at']);
    }
}
