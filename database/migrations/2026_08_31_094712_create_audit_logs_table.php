<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id('audit_id');
            $table->string('actor_type', 30)->nullable();   // 'passenger'|'staff'|'system'
            $table->unsignedBigInteger('actor_id')->nullable(); // user id (nullable for system)
            $table->string('action', 80);                   // e.g. 'trip.depart', 'fare.update'
            $table->string('subject_type', 60)->nullable(); // e.g. 'Trip', 'FareRule'
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();            // extra key/value payload
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_type', 'actor_id']);
            $table->index(['action']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
