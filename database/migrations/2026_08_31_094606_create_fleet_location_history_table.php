<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::create('fleet_location_history', function (Blueprint $table) {
                $table->id('history_id');
                $table->unsignedBigInteger('fleet_id');
                $table->unsignedBigInteger('trip_id')->nullable();
                // SQLite fallback: store as plain "lat,lng" string
                $table->string('location', 120);
                $table->decimal('heading', 5, 2)->nullable();
                $table->decimal('speed_kmh', 6, 2)->nullable();
                $table->timestamp('recorded_at')->useCurrent();

                $table->foreign('fleet_id')->references('fleet_id')->on('fleets')->onDelete('cascade');
                $table->foreign('trip_id')->references('trip_id')->on('trips')->nullOnDelete();
                $table->index(['fleet_id', 'recorded_at']);
                $table->index(['trip_id', 'recorded_at']);
            });

            return;
        }

        DB::statement('
            CREATE TABLE IF NOT EXISTS fleet_location_history (
                history_id  BIGSERIAL PRIMARY KEY,
                fleet_id    BIGINT NOT NULL REFERENCES fleets(fleet_id) ON DELETE CASCADE,
                trip_id     BIGINT REFERENCES trips(trip_id) ON DELETE SET NULL,
                location    GEOGRAPHY(POINT, 4326) NOT NULL,
                heading     DECIMAL(5, 2),
                speed_kmh   DECIMAL(6, 2),
                recorded_at TIMESTAMPTZ DEFAULT NOW()
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS flh_fleet_time_idx ON fleet_location_history (fleet_id, recorded_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS flh_trip_time_idx  ON fleet_location_history (trip_id,  recorded_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS flh_location_idx   ON fleet_location_history USING GIST(location)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_location_history');
    }
};
