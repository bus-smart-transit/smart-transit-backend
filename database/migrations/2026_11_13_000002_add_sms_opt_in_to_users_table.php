<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 67 (user, 2026-11-13): each person chooses whether SmartTransit may text them ("Send me text messages" on the
 * Account page). Off for everyone until they turn it on, so credits are only spent on people who asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sms_opt_in')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sms_opt_in');
        });
    }
};
