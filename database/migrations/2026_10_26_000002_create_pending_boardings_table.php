<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passenger In (2026-10-26): the Chauffeur taps when someone boards between stops; the bus position at that moment is
 * snapped back to the last stop the leg has passed and kept here until the fare is collected. A cash sale claims one
 * check-in (status sold, with its ticket). At End Trip a check-in still waiting becomes `unpaid`, the leakage check
 * shown on the Shift Summary and the Operator's remittances. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_boardings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id');
            $table->unsignedBigInteger('conductor_company_user_id');
            $table->unsignedBigInteger('conductor_shift_log_id')->nullable();
            $table->unsignedBigInteger('stop_id');              // the snapped-back stop the fare is counted from
            $table->integer('raw_distance_m');                   // where the bus actually was, metres from the route's first stop
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('checked_in_at');
            $table->string('status', 16)->default('pending');   // pending | sold | unpaid | cancelled
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->onDelete('cascade');
            $table->foreign('conductor_company_user_id')->references('company_user_id')->on('company_users')->onDelete('cascade');
            $table->foreign('stop_id')->references('stop_id')->on('stops');
            $table->index(['trip_id', 'status']);
            $table->index(['conductor_shift_log_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_boardings');
    }
};
