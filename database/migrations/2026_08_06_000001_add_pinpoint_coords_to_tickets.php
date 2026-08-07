<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Custom GPS drop-off coordinates — null for stop-based tickets.
            $table->decimal('origin_lat', 10, 8)->nullable()->after('distance_km');
            $table->decimal('origin_lng', 11, 8)->nullable()->after('origin_lat');
            $table->decimal('destination_lat', 10, 8)->nullable()->after('origin_lng');
            $table->decimal('destination_lng', 11, 8)->nullable()->after('destination_lat');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['origin_lat', 'origin_lng', 'destination_lat', 'destination_lng']);
        });
    }
};
