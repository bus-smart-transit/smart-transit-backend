<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-20 (user): points are earned in fractions (0.1 point per PHP 20 paid, RewardService::pointsFor), so a reward
 * transaction keeps two decimals. Existing whole-number rows keep their value. passenger_users.reward_points is
 * already a double. Redeeming stays in whole points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reward_transactions', function (Blueprint $table) {
            $table->decimal('points', 12, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('reward_transactions', function (Blueprint $table) {
            $table->integer('points')->change();
        });
    }
};
