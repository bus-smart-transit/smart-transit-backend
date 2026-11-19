<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('leg_direction', 20)->nullable()->after('trip_type');
            $table->index(['shift_block_id', 'departure_time'], 'trips_shift_block_departure_idx');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex('trips_shift_block_departure_idx');
            $table->dropColumn('leg_direction');
        });
    }
};
