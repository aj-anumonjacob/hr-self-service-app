<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Break Policies Table
        if (!Schema::hasTable('break_policies')) {
        Schema::create('break_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('name');
            $table->string('policy_type')->default('flexible'); // flexible, fixed, window
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->integer('allowed_duration_minutes')->default(60);
            $table->integer('max_breaks_per_shift')->default(1);
            $table->boolean('is_paid')->default(false);
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });
        }

        // 2. Break Policy Assignments Table
        if (!Schema::hasTable('break_policy_assignments')) {
        Schema::create('break_policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('break_policy_id');
            $table->string('assignable_type'); // company, department, shift, employee
            $table->unsignedBigInteger('assignable_id');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('assigned_by')->nullable();
            $table->timestamps();
        });
        }

        // 3. Company Break Settings Table
        if (!Schema::hasTable('company_break_settings')) {
        Schema::create('company_break_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique();
            $table->boolean('initial_overdue_enabled')->default(true);
            $table->integer('initial_delay_minutes')->default(0);
            $table->integer('repeat_interval_minutes')->default(10);
            $table->boolean('employee_repeat_enabled')->default(true);
            $table->integer('manager_escalation_delay_minutes')->default(10);
            $table->integer('admin_escalation_delay_minutes')->default(10);
            $table->boolean('repeat_manager_escalation')->default(true);
            $table->integer('max_repeat_notifications')->default(12);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->timestamps();
        });
        }

        // 4. Update attendance_breaks table
        Schema::table('attendance_breaks', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_breaks', 'employee_id')) {
                $table->foreignId('employee_id')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'break_policy_id')) {
                $table->foreignId('break_policy_id')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'status')) {
                $table->string('status')->default('active'); // active, completed, cancelled
            }
            if (!Schema::hasColumn('attendance_breaks', 'expected_end_at')) {
                $table->timestamp('expected_end_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'became_overdue_at')) {
                $table->timestamp('became_overdue_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'duration_seconds')) {
                $table->integer('duration_seconds')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'overdue_duration_seconds')) {
                $table->integer('overdue_duration_seconds')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'next_notification_at')) {
                $table->timestamp('next_notification_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'overdue_notification_count')) {
                $table->integer('overdue_notification_count')->default(0);
            }
            if (!Schema::hasColumn('attendance_breaks', 'escalation_level')) {
                $table->integer('escalation_level')->default(0);
            }
            if (!Schema::hasColumn('attendance_breaks', 'notification_series_status')) {
                $table->string('notification_series_status')->default('pending'); // pending, active, stopped, limit_reached
            }
            if (!Schema::hasColumn('attendance_breaks', 'notification_series_stopped_at')) {
                $table->timestamp('notification_series_stopped_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'notification_series_stop_reason')) {
                $table->string('notification_series_stop_reason')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'violation_code')) {
                $table->string('violation_code')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'approval_status')) {
                $table->string('approval_status')->default('approved'); // pending, approved, rejected
            }
            if (!Schema::hasColumn('attendance_breaks', 'approved_by')) {
                $table->foreignId('approved_by')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('attendance_breaks', 'idempotency_key')) {
                $table->string('idempotency_key')->nullable()->unique();
            }
        });

        // 5. Break Notification Logs Table
        if (!Schema::hasTable('break_notification_logs')) {
        Schema::create('break_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('break_id');
            $table->foreignId('attendance_record_id');
            $table->foreignId('employee_id');
            $table->foreignId('recipient_user_id')->nullable();
            $table->string('recipient_role'); // employee, manager, administrator
            $table->string('notification_type'); // due, overdue_10m, repeat_overdue, manager_escalation, admin_escalation
            $table->integer('escalation_sequence_number')->default(1);
            $table->timestamp('scheduled_at');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery_provider_message_id')->nullable();
            $table->string('delivery_status')->default('scheduled'); // scheduled, processing, sent, failed, skipped
            $table->text('failure_reason')->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('break_notification_logs');
        Schema::dropIfExists('company_break_settings');
        Schema::dropIfExists('break_policy_assignments');
        Schema::dropIfExists('break_policies');

        Schema::enableForeignKeyConstraints();
    }
};
