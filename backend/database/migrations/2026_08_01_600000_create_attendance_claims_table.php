<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_claims')) {
        Schema::create('attendance_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('employee_id');
            $table->foreignId('reporting_manager_id')->nullable();
            $table->foreignId('company_id');
            $table->foreignId('attendance_record_id')->nullable();
            
            $table->enum('claim_type', [
                'missing_check_in',
                'incorrect_check_in',
                'missing_check_out',
                'incorrect_check_out',
                'accidental_check_in',
                'accidental_check_out',
                'auto_checkout_correction',
                'other_attendance_issue'
            ]);

            $table->date('date');
            $table->timestamp('recorded_check_in_at')->nullable();
            $table->timestamp('recorded_check_out_at')->nullable();
            $table->timestamp('requested_check_in_at')->nullable();
            $table->timestamp('requested_check_out_at')->nullable();
            $table->string('reason_code');
            $table->text('description');

            $table->enum('status', [
                'submitted',
                'pending',
                'approved',
                'rejected',
                'explanation_requested',
                'employee_responded',
                'cancelled'
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

        if (!Schema::hasTable('attendance_claim_actions')) {
        Schema::create('attendance_claim_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_claim_id');
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->foreignId('actor_id')->nullable();
            $table->string('actor_role');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_claim_actions');
        Schema::dropIfExists('attendance_claims');
    }
};
