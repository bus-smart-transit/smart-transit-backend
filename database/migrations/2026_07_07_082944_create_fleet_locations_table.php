<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // PostGIS is already enabled in Supabase — we just need the table.
        // We use raw SQL for the GEOGRAPHY column since Laravel's Blueprint
        // doesn't have a native PostGIS type.
        DB::statement('
        CREATE TABLE IF NOT EXISTS fleet_locations (
            fleet_location_id BIGSERIAL PRIMARY KEY,
            fleet_id          BIGINT NOT NULL REFERENCES fleets(fleet_id) ON DELETE CASCADE,
            trip_id           BIGINT REFERENCES trips(trip_id) ON DELETE SET NULL,
            location          GEOGRAPHY(POINT, 4326) NOT NULL,
            heading           DECIMAL(5, 2),
            speed_kmh         DECIMAL(6, 2),
            recorded_at       TIMESTAMPTZ DEFAULT NOW(),
            updated_at        TIMESTAMPTZ DEFAULT NOW(),
            UNIQUE(fleet_id)
        )
    ');

        DB::statement('
        CREATE INDEX IF NOT EXISTS fleet_locations_location_idx
        ON fleet_locations USING GIST(location)
    ');
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_locations');
    }
};
