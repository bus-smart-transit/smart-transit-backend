<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-21 (user): every stop marked as reached on a trip, when, by whom, and whether the Driver tapped it or the
 * Super Admin's GPS simulator marked it (demo). trips.last_acknowledged_stop_id still holds the latest one; this log
 * is what the route adherence report reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_stop_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips', 'trip_id')->cascadeOnDelete();
            $table->foreignId('stop_id')->constrained('stops', 'stop_id')->cascadeOnDelete();
            $table->string('source', 16); // driver | simulator
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users', 'user_id')->nullOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->index(['trip_id', 'stop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_stop_acknowledgements');
    }
};
