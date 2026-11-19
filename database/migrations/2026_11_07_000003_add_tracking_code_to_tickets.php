<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-07 (Batch 49 item 5, user approved): a short tracking code per ticket that the passenger types at "Track my
 * bus" (8 characters, no 0/O/1/I/L). Stored like the tracking token: a hash to look it up (unique) and an encrypted
 * copy to show it to the ticket's owner. Null until the ticket is first shown or emailed; only tickets that can still
 * be tracked get one. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('tracking_code_hash', 64)->nullable()->unique();
            $table->text('tracking_code_enc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['tracking_code_hash']);
            $table->dropColumn(['tracking_code_hash', 'tracking_code_enc']);
        });
    }
};
