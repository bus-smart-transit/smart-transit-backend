<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Batch 15, Items 2/3: Firebase Cloud Messaging device token. Stored on
     * the base `users` table (not company_users) since push registration is
     * per logged-in account/browser, not role-specific — both Drivers
     * (Item 2, trip-assignment alerts) and Operators (Item 3, payment
     * confirmation alerts) need one. Single active token per user for this
     * phase, per the batch spec ("at minimum support one active token per
     * user") — registering a new token overwrites the previous one, which
     * is the simplest correct behavior for a single-device-at-a-time use
     * case (a stale token also just fails silently on the next send).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('fcm_token')->nullable()->after('two_factor_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fcm_token');
        });
    }
};
