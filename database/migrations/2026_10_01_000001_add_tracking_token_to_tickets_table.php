<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 26: ticket-scoped bus tracking. Each ticket can carry one high-entropy, opaque tracking
 * token (256 bits). Only its SHA-256 hash is used to look a ticket up; an encrypted copy is kept
 * so the owner (a signed-in passenger, or a guest who proves the payment reference and email) can
 * be shown the same link again. Neither column is ever serialised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('tracking_token_hash', 64)->nullable()->unique();
            $table->text('tracking_token_enc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['tracking_token_hash']);
            $table->dropColumn(['tracking_token_hash', 'tracking_token_enc']);
        });
    }
};
