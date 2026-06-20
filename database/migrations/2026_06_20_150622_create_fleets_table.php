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
        Schema::create('fleets', function (Blueprint $table) {
            $table->id('fleet_id');
            $table->foreignId('company_user_id')->constrained('company_users', 'company_user_id');
            $table->string('plate_number');
            $table->bigInteger('capacity');
            $table->bigInteger('seated_capacity');
            $table->bigInteger('standing_capacity');
            $table->string('status');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleets');
    }
};
