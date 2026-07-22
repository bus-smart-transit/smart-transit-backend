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
        Schema::table('tickets', function (Blueprint $table) {
            // Track origin and destination stops for each ticket
            $table->foreignId('origin_stop_id')->nullable()->after('seat_type')->constrained('stops', 'stop_id');
            $table->foreignId('destination_stop_id')->nullable()->after('origin_stop_id')->constrained('stops', 'stop_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeignKeyIfExists(['origin_stop_id']);
            $table->dropForeignKeyIfExists(['destination_stop_id']);
            $table->dropColumn(['origin_stop_id', 'destination_stop_id']);
        });
    }
};
