<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18 supersedes the trip_driver_segments design (built one turn
 * earlier, never committed to git) with the shift_blocks model instead —
 * see 2026_09_26_000003_create_shift_blocks_table.php. Dropping cleanly
 * rather than leaving a dead, unused table behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('trip_driver_segments');
    }

    public function down(): void
    {
        // Intentionally not recreated — see 2026_09_26_000002_create_trip_driver_segments_table.php
        // (superseded) if this ever needs to be restored.
    }
};
