<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Employee;
use App\Models\WorkLocation;
use App\Models\Shift;
use App\Models\Company;
use App\Models\WeeklyOffRule;
use App\Models\Role;
use App\Models\AttendanceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RecentsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $employee;
    protected $company;
    protected $location;
    protected $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $roleEmp = Role::create(['name' => 'Employee', 'slug' => 'employee']);

        $this->user = User::create([
            'name' => 'John Recents',
            'email' => 'john.recents@company.com',
            'password' => Hash::make('Password123')
        ]);
        $this->user->roles()->attach($roleEmp->id);

        $this->company = Company::create([
            'name' => 'Demo Company',
            'code' => 'ARP'
        ]);

        $weeklyOff = WeeklyOffRule::create([
            'company_id' => $this->company->id,
            'name' => 'Sunday Off',
            'days' => [0]
        ]);

        $this->shift = Shift::create([
            'company_id' => $this->company->id,
            'name' => 'Standard Shift',
            'code' => 'STD',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00'
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

        $this->employee = Employee::create([
            'user_id' => $this->user->id,
            'employee_number' => 'EMP999',
            'full_name' => 'John Recents',
            'email' => 'john.recents@company.com',
            'company_id' => $this->company->id,
            'joining_date' => '2026-01-01',
            'status' => 'active',
            'default_location_id' => $this->location->id,
            'assigned_shift_id' => $this->shift->id,
            'weekly_off_rule_id' => $weeklyOff->id
        ]);
    }

    /**
     * Test check-in, start break, end break, and check-out create structured AttendanceEvents
     */
    public function test_full_attendance_lifecycle_creates_attendance_events()
    {
        // 1. Check in
        $resCheckIn = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-in', [
                'latitude' => 25.204849,
                'longitude' => 55.270782,
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEV123',
                'idempotency_key' => 'idemp_checkin_recents_1'
            ]);

        $resCheckIn->assertStatus(200);
        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employee->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN'
        ]);

        // 2. Start Break
        $resStartBreak = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/start-break', [
                'reason' => 'Coffee Break',
                'idempotency_key' => 'idemp_start_break_recents_1'
            ]);

        $resStartBreak->assertStatus(200);
        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employee->id,
            'event_type' => 'BREAK_STARTED'
        ]);

        // 3. End Break
        $resEndBreak = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/end-break', [
                'idempotency_key' => 'idemp_end_break_recents_1'
            ]);

        $resEndBreak->assertStatus(200);
        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employee->id,
            'event_type' => 'BREAK_ENDED'
        ]);

        // 4. Check out
        $resCheckOut = $this->actingAs($this->user)
            ->postJson('/api/v1/me/attendance/check-out', [
                'latitude' => 25.204849,
                'longitude' => 55.270782,
                'accuracy' => 10.0,
                'device_time' => now()->toIso8601String(),
                'location_timestamp' => now()->toIso8601String(),
                'device_id' => 'DEV123',
                'idempotency_key' => 'idemp_checkout_recents_1'
            ]);

        $resCheckOut->assertStatus(200);
        $this->assertDatabaseHas('attendance_events', [
            'employee_id' => $this->employee->id,
            'event_type' => 'ATTENDANCE_CHECKED_OUT'
        ]);
    }

    /**
     * Test GET /v1/me/attendance-events endpoint returns chronological activity Timeline
     */
    public function test_recents_api_returns_paginated_events()
    {
        $record = \App\Models\AttendanceRecord::create([
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'status' => 'present'
        ]);

        // Create 2 events
        AttendanceEvent::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'ATTENDANCE_CHECKED_IN',
            'event_time_server' => now()->subHours(4),
            'event_source' => 'mobile_app'
        ]);

        AttendanceEvent::create([
            'attendance_record_id' => $record->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'BREAK_STARTED',
            'event_time_server' => now()->subHours(2),
            'event_source' => 'mobile_app'
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/me/attendance-events');

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $items = $response->json('data.items');
        $this->assertCount(2, $items);
        $this->assertEquals('BREAK_STARTED', $items[0]['type']); // Newest first
        $this->assertEquals('ATTENDANCE_CHECKED_IN', $items[1]['type']);
    }

    /**
     * Test backfill command runs idempotently
     */
    public function test_backfill_artisan_command_is_idempotent()
    {
        $this->artisan('app:backfill-attendance-events')->assertExitCode(0);
        $count1 = AttendanceEvent::count();

        // Run second time -> no duplicate events
        $this->artisan('app:backfill-attendance-events')->assertExitCode(0);
        $count2 = AttendanceEvent::count();

        $this->assertEquals($count1, $count2);
    }
}
