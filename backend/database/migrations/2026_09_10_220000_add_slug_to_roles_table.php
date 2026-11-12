<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The `Role` model, User::hasRole()/hasPermission(), and three notification
 * services (EmailNotificationService, ShiftReminderService,
 * OverdueBreakProcessorService) all query roles.slug — but the live `roles`
 * table never actually had that column, only `name`. Every one of those
 * admin/manager-fallback lookups has been throwing "Unknown column 'slug'"
 * whenever it was reached. This adds the column and backfills it from `name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('roles', 'slug')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->string('slug', 60)->nullable()->after('name');
            });
        }

        foreach (DB::table('roles')->get() as $role) {
            DB::table('roles')->where('id', $role->id)->update([
                'slug' => Str::slug($role->name, '_'),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('roles', 'slug')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->dropColumn('slug');
            });
        }
    }
};
