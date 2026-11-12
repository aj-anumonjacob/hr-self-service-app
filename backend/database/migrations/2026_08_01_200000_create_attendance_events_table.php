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
        Schema::table('attendance_events', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_events', 'attendance_break_id')) {
                $table->foreignId('attendance_break_id')->nullable();
            }
            if (!Schema::hasColumn('attendance_events', 'event_source')) {
                $table->string('event_source')->default('mobile_app');
            }
            if (!Schema::hasColumn('attendance_events', 'actor_user_id')) {
                $table->foreignId('actor_user_id')->nullable();
            }
            if (!Schema::hasColumn('attendance_events', 'actor_role')) {
                $table->string('actor_role')->default('employee');
            }
            if (!Schema::hasColumn('attendance_events', 'metadata')) {
                $table->json('metadata')->nullable();
            }
        });

        // Make latitude and longitude nullable if needed
        try {
            Schema::table('attendance_events', function (Blueprint $table) {
                $table->decimal('latitude', 10, 8)->nullable()->change();
                $table->decimal('longitude', 11, 8)->nullable()->change();
                $table->timestamp('event_time_device')->nullable()->change();
            });
        } catch (\Throwable $e) {
            // Ignore SQLite change column limitations if already handled
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_events', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_events', 'attendance_break_id')) {
                $table->dropForeign(['attendance_break_id']);
                $table->dropColumn('attendance_break_id');
            }
            if (Schema::hasColumn('attendance_events', 'actor_user_id')) {
                $table->dropForeign(['actor_user_id']);
                $table->dropColumn('actor_user_id');
            }
            $table->dropColumn(['event_source', 'actor_role', 'metadata']);
        });
    }
};
