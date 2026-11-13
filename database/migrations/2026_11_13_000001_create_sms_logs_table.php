<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 64 (user, 2026-11-13): every text message SmartTransit sends, with what happened to it. Read by the Super
 * Admin (php artisan sms log) to see where the credits went and why a text did not arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 40);            // config('sms.events') key
            $table->string('phone', 20)->nullable(); // +639XXXXXXXXX the text went to (after any intercept)
            $table->string('message', 200);
            $table->string('status', 20);           // queued, sent, failed, skipped
            $table->string('provider_message_id', 100)->nullable();
            $table->string('error', 255)->nullable();
            $table->string('subject_type', 40)->nullable(); // what it was about: Payment, Trip, User
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
