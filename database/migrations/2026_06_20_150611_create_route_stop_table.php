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
        Schema::create('route_stop_table', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stop_id')->constrained('stops', 'stop_id')->onDelete('cascade');
            $table->foreignId('route_id')->constrained('routes', 'route_id')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_stop');
    }
};
