<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_policy_settings')) {
        Schema::create('attendance_policy_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id');
            
            // Check-in reminder settings
            $table->integer('checkin_reminder_upcoming_minutes')->default(-5); // 5 min before
            $table->boolean('checkin_reminder_due_enabled')->default(true);   // at shift start
            $table->integer('checkin_reminder_late_escalation_minutes')->default(10); // 10 min late
            
            // Check-out reminder settings
            $table->integer('checkout_reminder_upcoming_minutes')->default(-5);
            $table->boolean('checkout_reminder_due_enabled')->default(true);
            $table->integer('checkout_reminder_overdue_minutes')->default(10);
            
            // Auto checkout settings
            $table->boolean('auto_checkout_enabled')->default(true);
            $table->time('auto_checkout_cutoff_time')->default('23:59:00');
            
            // Request & Claim settings
            $table->integer('max_late_delay_minutes')->default(240); // 4 hours max delay
            $table->integer('claim_description_max_chars')->default(500);
            $table->json('late_reason_options')->nullable();
            $table->json('claim_reason_options')->nullable();
            
            // Half day settings
            $table->float('half_day_threshold_percent')->default(50.0);
            $table->integer('half_day_min_minutes')->default(240);
            
            $table->timestamps();

            $table->unique('company_id');
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policy_settings');
    }
};
