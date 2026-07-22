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
        Schema::create('fleet_daily_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fleet_id')->constrained('fleets', 'fleet_id')->cascadeOnDelete();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('conductor_id')->nullable();
            $table->char('pin_code', 6);
            $table->date('pin_date');
            $table->timestamp('driver_verified_at')->nullable();
            $table->timestamp('conductor_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['fleet_id', 'pin_date']);
            $table->foreign('driver_id')->references('company_user_id')->on('company_users')->nullOnDelete();
            $table->foreign('conductor_id')->references('company_user_id')->on('company_users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fleet_daily_pins');
    }
};
