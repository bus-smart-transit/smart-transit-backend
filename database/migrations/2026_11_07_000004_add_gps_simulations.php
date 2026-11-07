<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-07 (Batch 49 item 4, user: "Take control of GPS location" in the Super Admin portal, for the defense demo).
 * - fleet_locations.is_simulated marks a position written by the simulator, so every screen can say "Simulated" and
 *   no decision (booking from a later stop, a Chauffeur's GPS boarding) uses it. Simulated points never go to the
 *   location history the reports read.
 * - gps_simulations holds one simulation: which trip, who started it, its speed, where it is along the route, and
 *   when it stops by itself. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_locations', function (Blueprint $table) {
            $table->boolean('is_simulated')->default(false);
        });

        Schema::create('gps_simulations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->index();
            $table->unsignedBigInteger('fleet_id');
            $table->unsignedBigInteger('started_by_user_id');
            $table->unsignedSmallInteger('speed');
            $table->string('status', 16)->default('running'); // running | paused | stopped
            $table->double('along_m')->default(0);
            $table->timestamp('last_tick_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gps_simulations');
        Schema::table('fleet_locations', function (Blueprint $table) {
            $table->dropColumn('is_simulated');
        });
    }
};
