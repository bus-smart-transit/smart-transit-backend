<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Server-side lifecycle timestamps (Batch 24, B5). Written once, on the
        // first transition into each state, so retries and double taps never
        // move them.
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('boarding_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['boarding_at', 'departed_at', 'completed_at']);
        });
    }
};
