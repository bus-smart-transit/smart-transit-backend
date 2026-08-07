<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            $table->timestampTz('paired_at')->nullable()->after('conductor_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            $table->dropColumn('paired_at');
        });
    }
};
