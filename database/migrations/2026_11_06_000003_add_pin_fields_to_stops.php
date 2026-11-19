<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-06 (Batch 47 part A, decided by the user). Additive:
 * - stops.location_label: a short area name the company types ("Downtown / Poblacion"), so two stops with the same
 *   name are told apart ("Acacia (Downtown / Poblacion)").
 * - stops.location_source: 'pinned' (placed on the map by a company admin) or 'name' (found from its name before
 *   pins existed, or seeded); stops.pinned_at: when it was last pinned. The Routes page lists 'name' stops to re-pin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->string('location_label', 80)->nullable();
            $table->string('location_source', 10)->nullable();
            $table->timestamp('pinned_at')->nullable();
        });

        DB::table('stops')->whereNotNull('latitude')->whereNull('location_source')->update(['location_source' => 'name']);
    }

    public function down(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->dropColumn(['location_label', 'location_source', 'pinned_at']);
        });
    }
};
