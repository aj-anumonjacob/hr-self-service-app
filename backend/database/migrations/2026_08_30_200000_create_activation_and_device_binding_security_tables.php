<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Activation Codes
        if (!Schema::hasTable('activation_codes')) {
            Schema::create('activation_codes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('employee_id');
                $table->string('verifier_hash');
                $table->string('status')->default('unused'); // unused, used, expired, revoked
                $table->dateTime('expires_at');
                $table->dateTime('used_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $table->string('request_source')->default('mobile_request'); // mobile_request, hr_portal
                $table->unsignedBigInteger('administrator_id')->nullable();
                $table->string('audit_correlation_id');
                $table->timestamps();

                $table->index(['employee_id', 'status']);
                $table->index('verifier_hash');
            });
        }

        // 2. Security Configurations
        if (!Schema::hasTable('security_configurations')) {
            Schema::create('security_configurations', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value');
                $table->unsignedBigInteger('updated_by_user_id')->nullable();
                $table->timestamps();
            });

            // Seed default values
            $defaults = [
                'code_expiry_minutes' => 10,
                'max_attempts_employee' => 5,
                'max_attempts_ip' => 10,
                'max_attempts_device' => 5,
                'rate_limit_window_minutes' => 15,
                'cooldown_minutes' => 15,
                'mobile_request_limit_30m' => 3,
                'resend_countdown_seconds' => 60,
                'allow_multiple_devices' => false,
                'notification_recipients' => ['support@example.com'],
                'sender_email' => 'support@example.com'
            ];

            foreach ($defaults as $key => $val) {
                \DB::table('security_configurations')->insertOrIgnore([
                    'key' => $key,
                    'value' => json_encode($val),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 3. Device Bindings
        if (!Schema::hasTable('device_bindings')) {
            Schema::create('device_bindings', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('employee_id');
                $table->string('platform_device_id');
                $table->string('device_name')->nullable();
                $table->string('platform')->nullable(); // ios, android
                $table->string('os_version')->nullable();
                $table->string('app_version')->nullable();
                $table->text('public_key')->nullable();
                $table->string('status')->default('active'); // active, revoked
                $table->dateTime('bound_at');
                $table->dateTime('revoked_at')->nullable();
                $table->string('audit_correlation_id');
                $table->timestamps();

                $table->index(['employee_id', 'status']);
                $table->index(['platform_device_id', 'status']);
            });
        }

        // 4. OTP & Mail Audit Logs
        if (!Schema::hasTable('otp_mail_audit_logs')) {
            Schema::create('otp_mail_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('event_type'); // code_generated, code_requested, mail_sent, mail_failed, activation_attempt, activation_success, activation_failure, device_unbound, security_config_changed
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->string('masked_identifier')->nullable();
                $table->unsignedBigInteger('administrator_id')->nullable();
                $table->string('device_instance_id')->nullable();
                $table->string('masked_ip')->nullable();
                $table->string('result')->default('success'); // success, failure, throttled
                $table->string('reason_category')->nullable();
                $table->string('delivery_status')->nullable();
                $table->string('provider_message_id')->nullable();
                $table->string('audit_correlation_id');
                $table->timestamps();

                $table->index('event_type');
                $table->index('employee_id');
                $table->index('audit_correlation_id');
            });
        }

        // 5. Ensure audit_log table exists for legacy calls in tests
        if (!Schema::hasTable('audit_log')) {
            Schema::create('audit_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_role')->nullable();
                $table->string('action');
                $table->string('object_type')->nullable();
                $table->string('object_id')->nullable();
                $table->text('summary')->nullable();
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
        Schema::dropIfExists('otp_mail_audit_logs');
        Schema::dropIfExists('device_bindings');
        Schema::dropIfExists('security_configurations');
        Schema::dropIfExists('activation_codes');
    }
};
