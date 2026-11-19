<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // D5: custom drop-off is off until an operator/admin enables it per route
        // (real-world stopping rules for franchised buses are still to be confirmed).
        Schema::table('routes', function (Blueprint $table) {
            $table->boolean('allow_custom_dropoff')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn('allow_custom_dropoff');
        });
    }
};
