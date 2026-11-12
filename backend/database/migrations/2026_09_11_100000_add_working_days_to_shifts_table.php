<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shift working days (checkboxes in the HR portal's Shift Management
 * screen). Stored as a JSON array of day-of-week integers that ARE worked
 * (0 = Sunday .. 6 = Saturday, matching weekly_off_rules.days' convention).
 * Any day NOT in this list is an off day for employees on this shift, and
 * feeds directly into AttendancePolicyService's weekly_off_days resolution
 * (consumed by the mobile app's Calendar screen).
 *
 * NULL means "not configured" — falls back to the company's WeeklyOffRule,
 * then to Sunday-only, so existing shifts keep working unchanged until an
 * admin explicitly sets working days for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'working_days')) {
                $table->json('working_days')->nullable()->after('end_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'working_days')) {
                $table->dropColumn('working_days');
            }
        });
    }
};
