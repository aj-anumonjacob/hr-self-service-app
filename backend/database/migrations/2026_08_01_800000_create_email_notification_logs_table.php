<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_notification_logs')) {
        Schema::create('email_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('template_name');
            $table->string('related_type')->nullable(); // attendance_request, attendance_claim, shift_reminder
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('subject');
            $table->text('body_text')->nullable();
            $table->enum('delivery_status', ['pending', 'processing', 'sent', 'failed'])->default('pending');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notification_logs');
    }
};
