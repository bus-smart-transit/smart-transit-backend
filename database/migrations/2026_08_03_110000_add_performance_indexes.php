<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for high-frequency query paths:
 *   - Ticket scanning, occupancy queries, driver/conductor trip lookups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Conductor occupancy + scan queries: WHERE trip_id AND status
            $table->index(['trip_id', 'status'], 'tickets_trip_status_idx');
            // Occupancy by seat type: WHERE trip_id AND status AND seat_type
            $table->index(['trip_id', 'status', 'seat_type'], 'tickets_trip_status_seat_idx');
            // Passenger ticket history: WHERE passenger_id (+ status filter)
            $table->index(['passenger_id', 'status'], 'tickets_passenger_status_idx');
            // Alighting queries: WHERE trip_id AND alighted_at IS NULL
            $table->index(['trip_id', 'alighted_at'], 'tickets_trip_alighted_idx');
        });

        Schema::table('trips', function (Blueprint $table) {
            // Driver current trip: WHERE driver_id AND trip_date AND status
            $table->index(['driver_id', 'trip_date', 'status'], 'trips_driver_date_status_idx');
            // Conductor current trip: WHERE conductor_id AND trip_date AND status
            $table->index(['conductor_id', 'trip_date', 'status'], 'trips_conductor_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex('tickets_trip_status_idx');
            $table->dropIndex('tickets_trip_status_seat_idx');
            $table->dropIndex('tickets_passenger_status_idx');
            $table->dropIndex('tickets_trip_alighted_idx');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex('trips_driver_date_status_idx');
            $table->dropIndex('trips_conductor_date_status_idx');
        });
    }
};
