<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add columns required by webhook-based ticket finalization.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('tickets', 'fare_rule_id')) {
                $table->foreignId('fare_rule_id')
                    ->nullable()
                    ->after('trip_id')
                    ->constrained('fare_rules', 'fare_rule_id');
            }

            if (!Schema::hasColumn('tickets', 'distance_km')) {
                $table->decimal('distance_km', 8, 2)
                    ->nullable()
                    ->after('fare_rule_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'fare_rule_id')) {
                $table->dropForeign(['fare_rule_id']);
                $table->dropColumn('fare_rule_id');
            }

            if (Schema::hasColumn('tickets', 'distance_km')) {
                $table->dropColumn('distance_km');
            }
        });
    }
};
