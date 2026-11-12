<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $employeeUser;
    protected User $managerUser;
    protected User $otherUser;
    protected Employee $employee;
    protected Employee $manager;
    protected Company $company;
    protected InAppNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Corp',
            'code' => 'TESTCORP',
            'timezone' => 'Asia/Dubai',
        ]);

        $this->managerUser = User::factory()->create([
            'email' => 'manager@testcorp.com',
        ]);

        $this->manager = Employee::create([
            'user_id' => $this->managerUser->id,
            'company_id' => $this->company->id,
            'full_name' => 'Manager Boss',
            'employee_number' => 'EMP-001',
            'email' => 'manager@testcorp.com',
            'joining_date' => '2026-01-01',
            'status' => 'active',
        ]);

        $this->employeeUser = User::factory()->create([
            'email' => 'employee@testcorp.com',
        ]);

        $this->employee = Employee::create([
            'user_id' => $this->employeeUser->id,
            'company_id' => $this->company->id,
            'full_name' => 'John Doe',
            'employee_number' => 'EMP-002',
            'email' => 'employee@testcorp.com',
            'reporting_manager_id' => $this->manager->id,
            'joining_date' => '2026-01-01',
            'status' => 'active',
        ]);

        $this->otherUser = User::factory()->create([
            'email' => 'other@testcorp.com',
        ]);

        $this->service = app(InAppNotificationService::class);
    }

    /** @test */
    public function it_creates_persistent_notification_without_push_token_or_permissions()
    {
        $notification = $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_EXPIRED,
            'Break time expired',
            'Your break time has ended. Please resume work.',
            ['navigation' => ['type' => 'activeBreak']],
            'idempotent:test:1'
        );

        $this->assertDatabaseHas('in_app_notifications', [
            'id' => $notification->id,
            'user_id' => $this->employeeUser->id,
            'recipient_role' => 'employee',
            'type' => 'BREAK_EXPIRED',
            'is_read' => false,
            'idempotency_key' => 'idempotent:test:1',
        ]);
    }

    /** @test */
    public function it_enforces_idempotency_on_duplicate_creation_attempts()
    {
        $first = $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_EXPIRED,
            'Break time expired',
            'First attempt',
            [],
            'idempotent:test:duplicate'
        );

        $second = $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_EXPIRED,
            'Break time expired',
            'Second attempt',
            [],
            'idempotent:test:duplicate'
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, InAppNotification::where('idempotency_key', 'idempotent:test:duplicate')->count());
    }

    /** @test */
    public function api_returns_unread_count_and_paginated_notifications_for_authenticated_user()
    {
        $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_EXPIRED,
            'Break Expired',
            'Message 1',
            [],
            'idempotent:api:1'
        );

        $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_OVERDUE_EMPLOYEE,
            'Break Overdue',
            'Message 2',
            [],
            'idempotent:api:2'
        );

        // Fetch unread count
        $countResponse = $this->actingAs($this->employeeUser, 'sanctum')
            ->getJson('/api/v1/me/notifications/unread-count');

        $countResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['unread_count' => 2],
            ]);

        // Fetch notifications list
        $listResponse = $this->actingAs($this->employeeUser, 'sanctum')
            ->getJson('/api/v1/me/notifications');

        $listResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'total' => 2,
                ],
            ]);

        $this->assertCount(2, $listResponse->json('data.items'));
    }

    /** @test */
    public function user_cannot_access_another_users_notifications()
    {
        $notification = $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_EXPIRED,
            'Secret Notification',
            'Confidential message',
            [],
            'idempotent:secret:1'
        );

        // Other user attempts to fetch list -> should see 0 items
        $response = $this->actingAs($this->otherUser, 'sanctum')
            ->getJson('/api/v1/me/notifications');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => ['total' => 0],
            ]);

        // Other user attempts to mark as read -> 404 Not Found
        $readResponse = $this->actingAs($this->otherUser, 'sanctum')
            ->postJson("/api/v1/me/notifications/{$notification->uuid}/read");

        $readResponse->assertStatus(404);
    }

    /** @test */
    public function marking_single_and_all_notifications_as_read_updates_status()
    {
        $n1 = $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_EXPIRED,
            'N1',
            'Msg 1',
            [],
            'idempotent:read:1'
        );

        $n2 = $this->service->createNotification(
            $this->employeeUser,
            'employee',
            InAppNotificationService::TYPE_BREAK_EXPIRED,
            'N2',
            'Msg 2',
            [],
            'idempotent:read:2'
        );

        // Mark N1 read
        $markResponse = $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson("/api/v1/me/notifications/{$n1->uuid}/read");

        $markResponse->assertStatus(200)
            ->assertJsonPath('data.is_read', true);

        $this->assertEquals(1, $this->service->unreadCount($this->employeeUser->id));

        // Mark all read
        $markAllResponse = $this->actingAs($this->employeeUser, 'sanctum')
            ->postJson('/api/v1/me/notifications/read-all');

        $markAllResponse->assertStatus(200)
            ->assertJsonPath('data.unread_count', 0);

        $this->assertEquals(0, $this->service->unreadCount($this->employeeUser->id));
    }
}
