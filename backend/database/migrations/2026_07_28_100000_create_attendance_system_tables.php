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
        // 1. Roles
        if (!Schema::hasTable('roles')) {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        }

        // 2. Permissions
        if (!Schema::hasTable('permissions')) {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });
        }

        // 3. Role Permissions
        if (!Schema::hasTable('role_permissions')) {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id');
            $table->foreignId('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });
        }

        // 4. User Roles
        if (!Schema::hasTable('user_roles')) {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id');
            $table->foreignId('role_id');
            $table->primary(['user_id', 'role_id']);
        });
        }

        // 5. Companies
        if (!Schema::hasTable('companies')) {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('logo_url')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('timezone')->default('Asia/Dubai');
            $table->timestamps();
        });
        }

        // 6. Departments
        if (!Schema::hasTable('departments')) {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('name');
            $table->string('code');
            $table->unsignedBigInteger('manager_id')->nullable(); // resolved to employee_id later
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        }

        // 7. Designations
        if (!Schema::hasTable('designations')) {
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('name');
            $table->string('code');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        }

        // 8. Weekly Off Rules
        if (!Schema::hasTable('weekly_off_rules')) {
        Schema::create('weekly_off_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('name');
            $table->json('days'); // e.g., [5, 6] (Friday, Saturday) or [0, 6] (Sunday, Saturday)
            $table->timestamps();
        });
        }

        // 9. Shifts
        if (!Schema::hasTable('shifts')) {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('name');
            $table->string('code');
            $table->string('type')->default('fixed'); // fixed, flexible, rotating, overnight, split
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->integer('flexible_minutes')->default(0);
            $table->integer('grace_period_minutes')->default(0);
            $table->integer('late_threshold_minutes')->default(0);
            $table->integer('early_departure_minutes')->default(0);
            $table->integer('min_working_minutes')->default(480);
            $table->json('break_rules')->nullable(); // rules regarding break durations
            $table->boolean('overtime_calc_enabled')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        }

        // 10. Work Locations
        if (!Schema::hasTable('work_locations')) {
        Schema::create('work_locations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->integer('check_in_radius')->default(100); // meters
            $table->integer('checkout_radius')->default(100); // meters
            $table->foreignId('default_shift_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable(); // employee_id
            $table->string('status')->default('active'); // active, inactive
            $table->string('background_tracking_policy')->default('disabled');
            $table->string('offline_attendance_policy')->default('enabled');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        }

        // 11. Employees
        if (!Schema::hasTable('employees')) {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable();
            $table->string('employee_number')->unique();
            $table->string('full_name');
            $table->string('preferred_name')->nullable();
            $table->string('email')->unique();
            $table->string('mobile_number')->nullable();
            $table->string('photo_url')->nullable();
            $table->foreignId('company_id');
            $table->foreignId('department_id')->nullable();
            $table->foreignId('designation_id')->nullable();
            $table->unsignedBigInteger('reporting_manager_id')->nullable();
            $table->date('joining_date')->nullable();
            $table->string('status')->default('active'); // active, inactive, suspended
            $table->foreignId('default_location_id')->nullable();
            $table->foreignId('assigned_shift_id')->nullable();
            $table->foreignId('weekly_off_rule_id')->nullable();
            $table->boolean('device_registration_status')->default(false);
            $table->string('activation_token')->nullable();
            $table->dateTime('activation_token_expires_at')->nullable();
            
            // External HR sync references
            $table->string('external_system')->nullable();
            $table->string('external_id')->nullable();
            $table->string('external_reference')->nullable();
            $table->string('source_system')->default('local');
            $table->string('sync_status')->default('synced'); // synced, pending, conflict
            $table->timestamps();
        });
        }



        // 12. Holidays
        if (!Schema::hasTable('holidays')) {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('holiday_type')->default('public'); // public, company, optional
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        }

        // 13. Holiday Scopes
        if (!Schema::hasTable('holiday_scopes')) {
        Schema::create('holiday_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_id');
            $table->string('scope_type'); // system, company, location, department
            $table->unsignedBigInteger('scope_id')->nullable(); // matches company_id, location_id, department_id
            $table->timestamps();
        });
        }

        // 14. Temporary Location Assignments
        if (!Schema::hasTable('employee_location_assignments')) {
        Schema::create('employee_location_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->foreignId('original_location_id')->nullable();
            $table->foreignId('assigned_location_id');
            $table->foreignId('assigned_shift_id')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('assignment_type')->default('temporary'); // temporary, field
            $table->string('reason')->nullable();
            $table->text('instructions')->nullable();
            $table->foreignId('manager_id')->nullable();
            $table->string('status')->default('active'); // active, cancelled, expired
            $table->string('tracking_policy')->default('disabled');
            $table->string('offline_policy')->default('enabled');
            $table->boolean('employee_acknowledged')->default(false);
            $table->timestamps();
        });
        }



        // 19. Offline Attendance Authorizations
        if (!Schema::hasTable('offline_attendance_authorizations')) {
        Schema::create('offline_attendance_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->string('device_id');
            $table->dateTime('valid_from');
            $table->dateTime('valid_to');
            $table->json('policy_data');
            $table->text('cryptographic_signature');
            $table->timestamps();
        });
        }

        // 20. Attendance Records
        if (!Schema::hasTable('attendance_records')) {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->date('date');
            $table->foreignId('shift_id')->nullable();
            $table->foreignId('location_id')->nullable();
            $table->foreignId('assignment_id')->nullable();
            $table->integer('total_duration_minutes')->default(0);
            $table->integer('net_duration_minutes')->default(0);
            $table->integer('break_duration_minutes')->default(0);
            $table->integer('late_minutes')->default(0);
            $table->integer('early_departure_minutes')->default(0);
            $table->integer('potential_overtime_minutes')->default(0);
            $table->string('status')->default('absent'); // present, late, half_day, absent, holiday, weekly_off
            $table->string('security_risk_level')->default('low'); // low, medium, high, manual_review
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
        });
        }

        // 21. Attendance Events
        if (!Schema::hasTable('attendance_events')) {
        Schema::create('attendance_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('attendance_record_id');
            $table->foreignId('employee_id');
            $table->string('event_type'); // check_in, check_out
            $table->dateTime('event_time_server')->nullable();
            $table->dateTime('event_time_device')->nullable();
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy', 5, 2)->nullable();
            $table->integer('distance_meters')->nullable();
            $table->string('device_id')->nullable();
            $table->string('app_version')->nullable();
            $table->string('os_version')->nullable();
            $table->string('ip_address')->nullable();
            $table->foreignId('offline_authorization_id')->nullable();
            $table->boolean('is_offline')->default(false);
            $table->string('sync_status')->default('synced'); // synced, pending_review, rejected, duplicate
            $table->json('security_flags')->nullable();
            $table->string('idempotency_key')->unique()->nullable();
            $table->timestamps();
        });
        }

        Schema::table('attendance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_records', 'check_in_event_id')) {
                $table->unsignedBigInteger('check_in_event_id')->nullable();
            }
            if (!Schema::hasColumn('attendance_records', 'check_out_event_id')) {
                $table->unsignedBigInteger('check_out_event_id')->nullable();
            }
        });

        // 22. Attendance Breaks
        if (!Schema::hasTable('attendance_breaks')) {
        Schema::create('attendance_breaks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('attendance_record_id');
            $table->dateTime('start_time_server')->nullable();
            $table->dateTime('start_time_device')->nullable();
            $table->dateTime('end_time_server')->nullable();
            $table->dateTime('end_time_device')->nullable();
            $table->integer('duration_minutes')->default(0);
            $table->boolean('is_paid')->default(false);
            $table->string('reason')->nullable();
            $table->timestamps();
        });
        }

        // 23. Attendance Corrections
        if (!Schema::hasTable('attendance_corrections')) {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->date('date');
            $table->string('correction_type'); // missed_check_in, missed_check_out, wrong_time, wrong_location, other
            $table->dateTime('requested_time')->nullable();
            $table->text('reason');
            $table->string('evidence_file_url')->nullable();
            $table->foreignId('manager_id')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected, info_requested
            $table->text('manager_notes')->nullable();
            $table->timestamps();
        });
        }

        // 24. Location Tracking Sessions
        if (!Schema::hasTable('location_tracking_sessions')) {
        Schema::create('location_tracking_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->foreignId('attendance_record_id');
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();
            $table->string('tracking_mode')->default('fixed_interval');
            $table->integer('tracking_interval_seconds')->default(300);
            $table->string('status')->default('active'); // active, completed, tracking_disabled
            $table->timestamps();
        });
        }

        // 25. Employee Location Logs
        if (!Schema::hasTable('employee_location_logs')) {
        Schema::create('employee_location_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tracking_session_id');
            $table->foreignId('employee_id');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy', 5, 2)->nullable();
            $table->decimal('speed', 5, 2)->nullable();
            $table->decimal('heading', 5, 2)->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->integer('battery_level')->nullable();
            $table->string('network_status')->nullable();
            $table->string('device_id')->nullable();
            $table->json('security_indicators')->nullable();
            $table->boolean('is_offline')->default(false);
            $table->timestamps();
        });
        }

        // 26. Location Geofence Events
        if (!Schema::hasTable('location_geofence_events')) {
        Schema::create('location_geofence_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->foreignId('tracking_session_id');
            $table->string('event_type'); // enter, exit
            $table->foreignId('geofence_location_id');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy', 5, 2)->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->timestamps();
        });
        }

        // 27. Mobile Devices
        if (!Schema::hasTable('mobile_devices')) {
        Schema::create('mobile_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->string('device_name')->nullable();
            $table->string('device_model')->nullable();
            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();
            $table->string('platform_device_id')->unique(); // hardware UUID or unique app fingerprint
            $table->string('status')->default('pending'); // pending, approved, revoked
            $table->dateTime('registered_at')->nullable();
            $table->timestamps();
        });
        }

        // 28. Push Notification Tokens
        if (!Schema::hasTable('push_notification_tokens')) {
        Schema::create('push_notification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id');
            $table->foreignId('device_id');
            $table->text('fcm_token');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        }

        // 29. Notifications
        if (!Schema::hasTable('notifications')) {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->string('title');
            $table->text('body');
            $table->json('payload')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
        }

        // 30. Announcements
        if (!Schema::hasTable('announcements')) {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id');
            $table->string('title');
            $table->text('body');
            $table->string('target_scope')->default('company'); // company, department, location
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();
        });
        }

        // 31. Audit Logs
        if (!Schema::hasTable('audit_logs')) {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable();
            $table->string('action'); // create, update, delete, login, verify_fail, etc.
            $table->string('model_type')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Disable foreign keys check for clean drops
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('push_notification_tokens');
        Schema::dropIfExists('mobile_devices');
        Schema::dropIfExists('location_geofence_events');
        Schema::dropIfExists('employee_location_logs');
        Schema::dropIfExists('location_tracking_sessions');
        Schema::dropIfExists('attendance_corrections');
        Schema::dropIfExists('attendance_breaks');
        Schema::dropIfExists('attendance_events');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('offline_attendance_authorizations');
        Schema::dropIfExists('employee_location_assignments');
        Schema::dropIfExists('holiday_scopes');
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('work_locations');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('weekly_off_rules');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');

        Schema::enableForeignKeyConstraints();
    }
};
