<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-05: a passenger can delete their own account (Data Privacy Act of 2012, right to erasure; the same idea as
 * GDPR). See App\Services\AccountDeletionService.
 * users.deletion_requested_at: when the passenger asked (UTC); signing in again within the grace days clears it.
 * users.anonymized_at: when the personal data was erased (UTC); the account can no longer be used.
 * passenger_users.phone_num, address and birth_date become nullable, so erasing them leaves no made-up value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
        });

        Schema::table('passenger_users', function (Blueprint $table) {
            $table->string('phone_num')->nullable()->change();
            $table->string('address')->nullable()->change();
            $table->timestamp('birth_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deletion_requested_at', 'anonymized_at']);
        });
        // The passenger columns stay nullable: an erased account has no value to put back.
    }
};
