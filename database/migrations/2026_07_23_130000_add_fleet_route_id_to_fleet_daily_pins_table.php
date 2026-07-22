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
            $table->foreignId('fleet_route_id')
                ->nullable()
                ->after('fleet_id')
                ->constrained('fleets_routes', 'fleet_route_id')
                ->nullOnDelete();
        });

        // Best-effort backfill: tie existing pins to one matching trip for the same fleet/date.
        DB::statement(<<<'SQL'
            UPDATE fleet_daily_pins fdp
            SET fleet_route_id = t.fleet_route_id
            FROM trips t
            JOIN fleets_routes fr ON fr.fleet_route_id = t.fleet_route_id
            WHERE fdp.fleet_route_id IS NULL
              AND fr.fleet_id = fdp.fleet_id
              AND t.trip_date = fdp.pin_date
SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fleet_route_id');
        });
    }
};
