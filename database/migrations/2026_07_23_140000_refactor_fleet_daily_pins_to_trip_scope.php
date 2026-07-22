<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            if (!Schema::hasColumn('fleet_daily_pins', 'trip_id')) {
                $table->unsignedBigInteger('trip_id')->nullable()->after('id');
            }
        });

        // Backfill trip_id from previously stored fleet_route_id and pin_date.
        DB::statement(<<<'SQL'
            UPDATE fleet_daily_pins fdp
            SET trip_id = t.trip_id
            FROM trips t
            WHERE fdp.trip_id IS NULL
              AND fdp.fleet_route_id IS NOT NULL
              AND t.fleet_route_id = fdp.fleet_route_id
              AND t.trip_date = fdp.pin_date
SQL);

        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            if (Schema::hasColumn('fleet_daily_pins', 'fleet_route_id')) {
                $table->dropConstrainedForeignId('fleet_route_id');
            }

            // Replace fleet/date uniqueness with trip/date uniqueness.
            $table->dropUnique('fleet_daily_pins_fleet_id_pin_date_unique');
            $table->unique(['trip_id', 'pin_date']);
            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            $table->dropForeign(['trip_id']);
            $table->dropUnique(['trip_id', 'pin_date']);

            if (!Schema::hasColumn('fleet_daily_pins', 'fleet_route_id')) {
                $table->foreignId('fleet_route_id')
                    ->nullable()
                    ->after('fleet_id')
                    ->constrained('fleets_routes', 'fleet_route_id')
                    ->nullOnDelete();
            }

            $table->unique(['fleet_id', 'pin_date']);
            $table->dropColumn('trip_id');
        });
    }
};
