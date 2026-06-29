<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fare_matrix', function (Blueprint $table) {
            $table->id('fare_id');
            $table->foreignId('origin_stop_id')->constrained('stops', 'stop_id');
            $table->foreignId('destination_stop_id')->constrained('stops', 'stop_id');
            $table->double('amount');
            $table->string('seat_type');
            $table->string('status');
            $table->foreignId('fleet_id')->constrained('fleets', 'fleet_id');
            $table->foreignId('fare_rule_id')->constrained('fare_rules', 'fare_rule_id');
            $table->unique(['origin_stop_id', 'destination_stop_id', 'seat_type', 'fleet_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fare_matrix');
    }
};
