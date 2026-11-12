<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_requests')) {
        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->foreignId('reporting_manager_id')->nullable();
            $table->foreignId('company_id');
            $table->foreignId('shift_id')->nullable();
            
            $table->date('date');
            $table->enum('request_type', ['late_arrival', 'morning_half_day']);
            $table->integer('delay_minutes')->default(0);
            $table->timestamp('expected_arrival_at')->nullable();
            $table->string('reason_code');
            $table->text('description')->nullable();
            
            $table->enum('status', [
                'draft',
                'submitted',
                'pending',
                'approved',
                'rejected',
                'explanation_requested',
                'employee_responded',
                'cancelled',
                'expired'
            ])->default('pending');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->text('manager_comment')->nullable();
            $table->text('explanation_request')->nullable();
            $table->text('employee_response')->nullable();
            
            $table->timestamps();

            $table->index(['employee_id', 'date']);
            $table->index(['reporting_manager_id', 'status']);
        });
        }

        if (!Schema::hasTable('attendance_request_actions')) {
        Schema::create('attendance_request_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_request_id');
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_role'); // employee, manager, admin
            $table->text('comment')->nullable();
            $table->timestamps();
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_request_actions');
        Schema::dropIfExists('attendance_requests');
    }
};
