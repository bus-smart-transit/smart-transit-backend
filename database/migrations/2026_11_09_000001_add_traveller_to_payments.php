<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-09 (Batch 51 item 7, user): a passenger may book for someone else. The traveller's name and contact (an email
 * or a Philippine mobile number) are kept on the booking (payment) and shown on its tickets; the ticket itself still
 * goes to the buyer's email until an SMS service is chosen. Null = the buyer travels. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('traveller_name', 100)->nullable();
            $table->string('traveller_contact', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['traveller_name', 'traveller_contact']);
        });
    }
};
