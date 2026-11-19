<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * smarttransit_prompt.md §4.10 (2026-10-26): the passenger's choice at checkout for the "follow your bus" email: one
 * group QR for the whole purchase (default) or one QR per ticket. Additive; existing payments read as the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('email_group_qr')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('email_group_qr');
        });
    }
};
