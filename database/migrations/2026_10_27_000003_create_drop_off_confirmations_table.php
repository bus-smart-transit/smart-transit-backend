<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-27 (smarttransit_prompt.md §7): one row per destination-group drop-off the Chauffeur confirmed, with the
 * exact tickets it included. The tickets themselves keep their own alighted status and time; this is the record of
 * which ones were confirmed together, by whom and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_off_confirmations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips', 'trip_id')->cascadeOnDelete();
            $table->unsignedBigInteger('conductor_company_user_id')->nullable();
            $table->string('destination_key', 64);
            $table->string('destination_label')->nullable();
            $table->unsignedBigInteger('destination_stop_id')->nullable();
            $table->json('ticket_ids');
            $table->unsignedInteger('ticket_count');
            $table->timestamp('confirmed_at');
            $table->timestamps();
            $table->index(['trip_id', 'destination_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_off_confirmations');
    }
};
