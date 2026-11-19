<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 27 Part 2, Task 4: extend the existing fare rule (no second calculator).
     *
     * - fare_rules.base_distance_km: nullable. Null keeps today's formula (base + distance x per-km).
     *   When set: distance <= base_distance_km costs base_fare; beyond it, base_fare + the extra km x per-km.
     * - fare_rules.effective_from / effective_to: nullable dates (Manila calendar dates). A rule applies
     *   to a trip dated inside its range. Null = open ended, so every existing rule keeps applying.
     * - tickets.boarding_distance_m / alighting_distance_m: the snapshot of the two points the fare was
     *   priced between (metres along the route from its first stop, the same frame as
     *   route_stop.distance_from_origin_m). fare_rule_id and amount already exist. Old tickets stay null.
     *
     * Rounding mode, vehicle class and service type are NOT added here (Batch 28, item 5).
     */
    public function up(): void
    {
        Schema::table('fare_rules', function (Blueprint $table) {
            $table->decimal('base_distance_km', 8, 3)->nullable()->after('fare_per_km');
            $table->date('effective_from')->nullable()->after('base_distance_km');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('boarding_distance_m')->nullable();
            $table->unsignedInteger('alighting_distance_m')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['boarding_distance_m', 'alighting_distance_m']);
        });

        Schema::table('fare_rules', function (Blueprint $table) {
            $table->dropColumn(['base_distance_km', 'effective_from', 'effective_to']);
        });
    }
};
