<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-19 (user): the Super Admin GPS simulator can take the bus off its route ("Go off route") and back
 * ("Back on route"), to test the Off route alert, the Fleets chip and the route adherence report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gps_simulations', function (Blueprint $table) {
            $table->boolean('off_route')->default(false)->after('along_m');
        });
    }

    public function down(): void
    {
        Schema::table('gps_simulations', function (Blueprint $table) {
            $table->dropColumn('off_route');
        });
    }
};
