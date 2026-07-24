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
            if (Schema::hasColumn('fleet_daily_pins', 'fleet_id')) {
                $table->dropConstrainedForeignId('fleet_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            if (!Schema::hasColumn('fleet_daily_pins', 'fleet_id')) {
                $table->unsignedBigInteger('fleet_id')->nullable()->after('trip_id');
            }
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                UPDATE fleet_daily_pins
                SET fleet_id = (
                    SELECT fr.fleet_id
                    FROM trips t
                    JOIN fleets_routes fr ON fr.fleet_route_id = t.fleet_route_id
                    WHERE t.trip_id = fleet_daily_pins.trip_id
                    LIMIT 1
                )
                WHERE fleet_id IS NULL
SQL);
        } else {
            DB::statement(<<<'SQL'
                UPDATE fleet_daily_pins fdp
                SET fleet_id = fr.fleet_id
                FROM trips t
                JOIN fleets_routes fr ON fr.fleet_route_id = t.fleet_route_id
                WHERE fdp.trip_id = t.trip_id
                  AND fdp.fleet_id IS NULL
SQL);
        }

        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            $table->foreign('fleet_id')->references('fleet_id')->on('fleets')->cascadeOnDelete();
        });
    }
};
