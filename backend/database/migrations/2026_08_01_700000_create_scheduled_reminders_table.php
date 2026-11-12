<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('scheduled_reminders')) {
        Schema::create('scheduled_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id');
            $table->foreignId('shift_id')->nullable();
            $table->date('shift_date');
            
            $table->string('reminder_type'); // checkin_upcoming, checkin_due, checkin_late, checkout_upcoming, checkout_due, checkout_overdue
            $table->timestamp('scheduled_for');
            $table->enum('status', ['pending', 'sent', 'cancelled', 'failed'])->default('pending');
            $table->string('idempotency_key')->unique();
            
            $table->integer('attempt_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status', 'scheduled_for']);
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reminders');
    }
};
