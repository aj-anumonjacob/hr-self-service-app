<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The live `company_break_settings` table only ever had the 4 columns the HR
 * portal's Break Policies tab writes to (max_breaks_per_day,
 * max_break_duration_minutes, overdue_alert_threshold_minutes,
 * is_paid_default) — the escalation-timing columns the original migration
 * (2026_08_01_100000_create_break_management_tables.php) and the
 * CompanyBreakSetting model/OverdueBreakProcessorService expect were never
 * actually added. Reading them returned NULL, and `$nextSeq > null` (PHP
 * treats null as 0) was silently true on the very first pass, so overdue
 * break escalation has been exiting immediately without ever notifying
 * anyone. This restores the missing columns without touching the 4 that
 * already exist and are in active use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_break_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('company_break_settings', 'initial_overdue_enabled')) {
                $table->boolean('initial_overdue_enabled')->default(true)->after('company_id');
            }
            if (!Schema::hasColumn('company_break_settings', 'initial_delay_minutes')) {
                $table->integer('initial_delay_minutes')->default(0)->after('initial_overdue_enabled');
            }
            if (!Schema::hasColumn('company_break_settings', 'repeat_interval_minutes')) {
                $table->integer('repeat_interval_minutes')->default(10)->after('initial_delay_minutes');
            }
            if (!Schema::hasColumn('company_break_settings', 'employee_repeat_enabled')) {
                $table->boolean('employee_repeat_enabled')->default(true)->after('repeat_interval_minutes');
            }
            if (!Schema::hasColumn('company_break_settings', 'manager_escalation_delay_minutes')) {
                $table->integer('manager_escalation_delay_minutes')->default(10)->after('employee_repeat_enabled');
            }
            if (!Schema::hasColumn('company_break_settings', 'admin_escalation_delay_minutes')) {
                $table->integer('admin_escalation_delay_minutes')->default(10)->after('manager_escalation_delay_minutes');
            }
            if (!Schema::hasColumn('company_break_settings', 'repeat_manager_escalation')) {
                $table->boolean('repeat_manager_escalation')->default(true)->after('admin_escalation_delay_minutes');
            }
            if (!Schema::hasColumn('company_break_settings', 'max_repeat_notifications')) {
                $table->integer('max_repeat_notifications')->default(12)->after('repeat_manager_escalation');
            }
            if (!Schema::hasColumn('company_break_settings', 'push_enabled')) {
                $table->boolean('push_enabled')->default(true)->after('max_repeat_notifications');
            }
            if (!Schema::hasColumn('company_break_settings', 'in_app_enabled')) {
                $table->boolean('in_app_enabled')->default(true)->after('push_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_break_settings', function (Blueprint $table) {
            $cols = [
                'initial_overdue_enabled', 'initial_delay_minutes', 'repeat_interval_minutes',
                'employee_repeat_enabled', 'manager_escalation_delay_minutes', 'admin_escalation_delay_minutes',
                'repeat_manager_escalation', 'max_repeat_notifications', 'push_enabled', 'in_app_enabled',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('company_break_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
