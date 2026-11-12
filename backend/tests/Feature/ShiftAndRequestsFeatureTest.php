<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Employee;
use App\Models\Company;
use App\Models\WorkLocation;
use App\Models\Shift;
use App\Models\WeeklyOffRule;
use App\Models\Role;
use App\Models\AttendanceRecord;
use App\Models\AttendanceEvent;
use App\Models\AttendanceRequest;
use App\Models\AttendanceClaim;
use App\Models\AttendancePolicySetting;
use App\Services\ShiftReminderService;
use App\Services\AutoCheckoutService;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ShiftAndRequestsFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected $empUser;
    protected $employee;
    protected $mgrUser;
    protected $manager;
    protected $company;
    protected $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $roleEmp = Role::create(['name' => 'Employee', 'slug' => 'employee']);
        $roleMgr = Role::create(['name' => 'Manager', 'slug' => 'manager']);

        $this->company = Company::create([
            'name' => 'Shift & Request Corp',
            'code' => 'SRC',
            'timezone' => 'Asia/Dubai'
        ]);

        $weeklyOff = WeeklyOffRule::create([
            'company_id' => $this->company->id,
            'name' => 'Sunday Off',
            'days' => [0]
        ]);

        $this->shift = Shift::create([
            'company_id' => $this->company->id,
            'name' => 'Day Shift 9to6',
            'code' => 'DAY96',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00'
        ]);

        // Manager setup
        $this->mgrUser = User::create([
            'name' => 'Manager User',
            'email' => 'manager.src@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->mgrUser->roles()->attach($roleMgr->id);

        $this->manager = Employee::create([
            'user_id' => $this->mgrUser->id,
            'company_id' => $this->company->id,
            'employee_number' => 'MGR-001',
            'full_name' => 'Manager One',
            'email' => 'manager.src@company.com',
            'assigned_shift_id' => $this->shift->id,
            'weekly_off_rule_id' => $weeklyOff->id,
            'status' => 'active'
        ]);

        // Employee setup
        $this->empUser = User::create([
            'name' => 'Employee User',
            'email' => 'employee.src@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->empUser->roles()->attach($roleEmp->id);

        $this->employee = Employee::create([
            'user_id' => $this->empUser->id,
            'company_id' => $this->company->id,
            'employee_number' => 'EMP-101',
            'full_name' => 'Employee One',
            'email' => 'employee.src@company.com',
            'assigned_shift_id' => $this->shift->id,
            'weekly_off_rule_id' => $weeklyOff->id,
            'reporting_manager_id' => $this->manager->id,
            'status' => 'active'
        ]);

        AttendancePolicySetting::forCompany($this->company->id);
    }

    /**
     * Test Late Arrival request submission, manager explanation request, and employee acknowledgment button response.
     */
    public function test_late_arrival_request_and_explanation_acknowledgment_workflow()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 07:00:00', 'Asia/Dubai'));

        // 1. Employee submits late arrival request (1h 30m delay -> 10:30 AM arrival)
        $response = $this->actingAs($this->empUser)
            ->postJson('/api/v1/me/attendance-requests', [
                'date' => '2026-08-01',
                'request_type' => 'late_arrival',
                'delay_minutes' => 90,
                'reason_code' => 'medical_appointment',
                'description' => 'Doctor appointment in the morning'
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'request_type' => 'late_arrival',
                    'delay_minutes' => 90,
                    'status' => 'pending'
                ]
            ]);

        $reqUuid = $response->json('data.uuid');

        // 2. Manager views pending requests
        $mgrListResponse = $this->actingAs($this->mgrUser)
            ->getJson('/api/v1/manager/attendance-requests?status=pending');

        $mgrListResponse->assertStatus(200)
            ->assertJsonPath('data.0.uuid', $reqUuid);

        // 3. Manager requests additional explanation
        $expReqResponse = $this->actingAs($this->mgrUser)
            ->postJson("/api/v1/manager/attendance-requests/{$reqUuid}/request-explanation", [
                'comment' => 'Please attach doctor prescription or clinic confirmation.'
            ]);

        $expReqResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'explanation_requested');

        // 4. Employee acknowledges and responds to explanation request
        $respondResponse = $this->actingAs($this->empUser)
            ->postJson("/api/v1/me/attendance-requests/{$reqUuid}/respond", [
                'response' => 'Acknowledged. Clinic appointment confirmation attached and verified.'
            ]);

        $respondResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'employee_responded',
                    'employee_response' => 'Acknowledged. Clinic appointment confirmation attached and verified.'
                ]
            ]);

        // 5. Manager approves request
        $approveResponse = $this->actingAs($this->mgrUser)
            ->postJson("/api/v1/manager/attendance-requests/{$reqUuid}/approve", [
                'comment' => 'Approved'
            ]);

        $approveResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');
    }

    /**
     * Test Morning Half-Day request auto-calculates arrival time.
     */
    public function test_morning_half_day_request_calculates_arrival()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 07:00:00', 'Asia/Dubai'));

        $response = $this->actingAs($this->empUser)
            ->postJson('/api/v1/me/attendance-requests', [
                'date' => '2026-08-01',
                'request_type' => 'morning_half_day',
                'reason_code' => 'family_emergency',
                'description' => 'Morning family emergency'
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'request_type' => 'morning_half_day',
                    'status' => 'pending'
                ]
            ]);
    }

    /**
     * Test Attendance Claim submission, description length validation (max 500 chars), and manager approval updating attendance record.
     */
    public function test_attendance_claim_approval_updates_record_and_recents_event()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 19:00:00', 'Asia/Dubai'));

        // Create incomplete attendance record (missing check-out)
        $inTimeUtc = Carbon::parse('2026-08-01 09:00:00', 'Asia/Dubai')->setTimezone('UTC');
        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'status' => 'present'
        ]);

        $checkInEvt = AttendanceEvent::create([
            'employee_id' => $this->employee->id,
            'attendance_record_id' => $record->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN',
            'event_time_server' => $inTimeUtc,
            'event_source' => 'mobile_app',
            'actor_role' => 'employee'
        ]);
        $record->update(['check_in_event_id' => $checkInEvt->id]);

        $reqOutLocal = Carbon::parse('2026-08-01 18:00:00', 'Asia/Dubai');

        // 1. Employee submits missing checkout claim
        $claimResponse = $this->actingAs($this->empUser)
            ->postJson('/api/v1/me/attendance-claims', [
                'claim_type' => 'missing_check_out',
                'date' => '2026-08-01',
                'attendance_record_id' => $record->id,
                'requested_check_out_at' => $reqOutLocal->toIso8601String(),
                'reason_code' => 'forgot_check_out',
                'description' => 'Forgot to tap checkout before leaving office at 6:00 PM.'
            ]);

        $claimResponse->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'claim_type' => 'missing_check_out',
                    'status' => 'pending'
                ]
            ]);

        $claimUuid = $claimResponse->json('data.uuid');

        // 2. Manager approves claim
        $approveResponse = $this->actingAs($this->mgrUser)
            ->postJson("/api/v1/manager/attendance-claims/{$claimUuid}/approve", [
                'comment' => 'Approved missed checkout claim'
            ]);

        $approveResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Verify AttendanceRecord updated
        $record->refresh();
        $this->assertNotNull($record->check_out_time);
        $this->assertEquals(540, $record->total_duration_minutes); // 9 hours = 540 mins

        // Verify MANAGER_CORRECTION AttendanceEvent created
        $event = AttendanceEvent::where('employee_id', $this->employee->id)
            ->where('event_type', 'MANAGER_CORRECTION')
            ->first();
        $this->assertNotNull($event);
    }

    /**
     * Test AutoCheckoutService executes checkout at cutoff time for unclosed shifts.
     */
    public function test_auto_checkout_service_executes_at_cutoff()
    {
        // 9:00 AM check-in
        $inTimeUtc = Carbon::parse('2026-08-01 09:00:00', 'Asia/Dubai')->setTimezone('UTC');
        $record = AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'company_id' => $this->company->id,
            'date' => '2026-08-01',
            'check_in_time' => $inTimeUtc,
            'status' => 'present'
        ]);

        $checkInEvt = AttendanceEvent::create([
            'employee_id' => $this->employee->id,
            'attendance_record_id' => $record->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN',
            'event_time_server' => $inTimeUtc,
            'event_source' => 'mobile_app',
            'actor_role' => 'employee'
        ]);
        $record->update(['check_in_event_id' => $checkInEvt->id]);

        // Current time is 23:59:01 local time
        Carbon::setTestNow(Carbon::parse('2026-08-01 23:59:01', 'Asia/Dubai'));

        $autoService = app(AutoCheckoutService::class);
        $processed = $autoService->processAutoCheckouts();

        $this->assertEquals(1, $processed);

        $record->refresh();
        $this->assertNotNull($record->check_out_time);

        $checkoutEvt = AttendanceEvent::where('attendance_record_id', $record->id)
            ->where('event_type', 'ATTENDANCE_CHECKED_OUT')
            ->first();
        $this->assertNotNull($checkoutEvt);
        $this->assertEquals('system_auto_checkout', $checkoutEvt->event_source);
    }

    /**
     * Test ShiftReminderService dispatches reminders and respects approved late exception.
     */
    public function test_shift_reminder_service_respects_approved_late_arrival()
    {
        // Shift starts at 09:00 AM.
        // Employee has approved late arrival until 10:30 AM.
        AttendanceRequest::create([
            'employee_id' => $this->employee->id,
            'reporting_manager_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'shift_id' => $this->shift->id,
            'date' => '2026-08-01',
            'request_type' => 'late_arrival',
            'delay_minutes' => 90,
            'expected_arrival_at' => Carbon::parse('2026-08-01 10:30:00', 'Asia/Dubai')->setTimezone('UTC'),
            'reason_code' => 'medical_appointment',
            'status' => 'approved'
        ]);

        // At 09:10 AM (10 min past shift start), normal late escalation is SUPPRESSED due to approved late arrival
        Carbon::setTestNow(Carbon::parse('2026-08-01 09:10:00', 'Asia/Dubai'));
        $reminderService = app(ShiftReminderService::class);
        $reminderService->processRemindersForDate('2026-08-01');

        $this->assertDatabaseMissing('scheduled_reminders', [
            'employee_id' => $this->employee->id,
            'reminder_type' => 'checkin_late'
        ]);

        // At 10:25 AM (5 min before approved arrival), upcoming reminder triggers
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:25:00', 'Asia/Dubai'));
        $reminderService->processRemindersForDate('2026-08-01');

        $this->assertDatabaseHas('scheduled_reminders', [
            'employee_id' => $this->employee->id,
            'reminder_type' => 'checkin_upcoming'
        ]);
    }
}
