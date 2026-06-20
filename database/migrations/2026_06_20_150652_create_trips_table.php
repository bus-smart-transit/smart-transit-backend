<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id('trip_id');
            $table->foreignId('fleet_route_id')->constrained('fleets_routes', 'fleet_route_id');
            $table->foreignId('company_user_id')->constrained('company_users', 'company_user_id');
            $table->bigInteger('current_seated_capacity');
            $table->bigInteger('current_standing_capacity');
            $table->bigInteger('total_occupancy');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
