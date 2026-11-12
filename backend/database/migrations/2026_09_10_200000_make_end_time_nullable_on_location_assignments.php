<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A NULL end_time means "secondary" (ongoing, no expiry) rather than
        // "temporary" (time-bounded). Same table, distinguished by assignment_type.
        Schema::table('employee_location_assignments', function (Blueprint $table) {
            $table->dateTime('end_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_location_assignments', function (Blueprint $table) {
            $table->dateTime('end_time')->nullable(false)->change();
        });
    }
};
