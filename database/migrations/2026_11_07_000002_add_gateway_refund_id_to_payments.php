<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-07 (Batch 49 item 1, user approved): seats are taken only when the payment is confirmed. A payment whose seats
 * were gone by then is refunded automatically through PayMongo; payments.gateway_refund_id is that refund's id, so the
 * signed refund event can mark the payment refunded and nobody refunds it a second time by hand. Null = no automatic
 * refund in progress. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_refund_id', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['gateway_refund_id']);
            $table->dropColumn('gateway_refund_id');
        });
    }
};
