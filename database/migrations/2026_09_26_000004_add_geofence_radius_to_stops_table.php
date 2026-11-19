<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18: Stop entity extended with a geofence radius rather than
 * introducing a new `terminals` table — confirmed sufficient once this
 * one field is added (Stop already has latitude/longitude). Nullable so
 * existing stops keep using the shift-block system's config default
 * (see config/shift_blocks.php) until an Operator sets a specific radius.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->unsignedInteger('geofence_radius_m')->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('stops', function (Blueprint $table) {
            $table->dropColumn('geofence_radius_m');
        });
    }
};
