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
        if (!Schema::hasTable('employee_shift_allocations')) {
            Schema::create('employee_shift_allocations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->integer('employee_id');
                $table->unsignedBigInteger('shift_id');
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('day_of_week', 20)->default('all'); // all, monday, tuesday, etc.
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('employee_id');
                $table->index('shift_id');
                $table->index(['employee_id', 'is_active', 'effective_from', 'effective_to'], 'emp_shift_alloc_idx');
            });
        }

        if (Schema::hasTable('employees') && !Schema::hasColumn('employees', 'shift_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->unsignedBigInteger('shift_id')->nullable()->after('work_schedule_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_shift_allocations');
        if (Schema::hasColumn('employees', 'shift_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('shift_id');
            });
        }
    }
};
