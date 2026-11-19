<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 28 Stage 3, item 7: peak-hour duration factors.
 *
 * route_duration_factors: for one route, a time window (Manila wall-clock, start inclusive, end exclusive, within one
 * day), optionally for one direction and one day type (weekday | weekend), with a factor (> 0) that scales the route's
 * scheduled stop offsets for a leg whose scheduled departure falls inside the window. `source` says where the number
 * came from: `manual` (configured by a person) or `measured` (a person copied it from the read-only duration report).
 * Nothing is ever applied automatically. No window = factor 1.0.
 *
 * trips.duration_factor: the factor a leg used at the moment it started boarding or departed, so that editing a window
 * later never moves the times of a leg that is already under way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_duration_factors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id');
            $table->string('direction', 12)->nullable(); // outbound | reverse | null = both
            $table->string('day_type', 12)->nullable();  // weekday | weekend | null = every day
            $table->time('start_time');                  // Manila local
            $table->time('end_time');                    // Manila local
            $table->decimal('factor', 6, 3);
            $table->string('source', 12)->default('manual'); // manual | measured
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('route_id')->references('route_id')->on('routes')->cascadeOnDelete();
            $table->index(['route_id', 'start_time']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->decimal('duration_factor', 6, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('duration_factor');
        });
        Schema::dropIfExists('route_duration_factors');
    }
};
