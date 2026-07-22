<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Normalize legacy fare rule seat type values used by old UI/data seeders.
     */
    public function up(): void
    {
        DB::table('fare_rules')
            ->where('seat_type', 'public')
            ->update(['seat_type' => 'seated']);

        DB::table('fare_rules')
            ->where('seat_type', 'private')
            ->update(['seat_type' => 'standing']);
    }

    public function down(): void
    {
        DB::table('fare_rules')
            ->where('seat_type', 'seated')
            ->update(['seat_type' => 'public']);

        DB::table('fare_rules')
            ->where('seat_type', 'standing')
            ->update(['seat_type' => 'private']);
    }
};
