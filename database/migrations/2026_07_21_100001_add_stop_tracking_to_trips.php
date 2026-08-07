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
        Schema::table('trips', function (Blueprint $table) {
            // Track which stop the driver last acknowledged/passed
            $table->foreignId('last_acknowledged_stop_id')->nullable()->after('conductor_id')->constrained('stops', 'stop_id');
            $table->timestamp('last_acknowledged_at')->nullable()->after('last_acknowledged_stop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['last_acknowledged_stop_id']);
            $table->dropColumn(['last_acknowledged_stop_id', 'last_acknowledged_at']);
        });
    }
};
