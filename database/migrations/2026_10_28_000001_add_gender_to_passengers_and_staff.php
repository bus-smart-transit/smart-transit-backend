<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-28: an optional gender on passengers (chosen at sign-up) and staff (set by the Operator when the account is
 * made): male, female or prefer_not_to_say (App\Support\Gender). Null means it was not given. Stored only; nothing
 * else in the system reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passenger_users', function (Blueprint $table) {
            $table->string('gender', 20)->nullable();
        });
        Schema::table('company_users', function (Blueprint $table) {
            $table->string('gender', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('passenger_users', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
        Schema::table('company_users', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
