<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 45 Feature A (2026-11-04): the online booking fee (App\Support\BookingFee) is stored apart from the fares.
 * payments.fee_amount: the fee charged on that payment (0 for cash and for payments made before the fee existed).
 * ticket_resolutions.fee_amount: the part of a refund that gives the fee back (0 when the fee was not refunded).
 * Additive only; existing rows read 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('fee_amount', 10, 2)->default(0);
        });

        Schema::table('ticket_resolutions', function (Blueprint $table) {
            $table->decimal('fee_amount', 10, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_resolutions', function (Blueprint $table) {
            $table->dropColumn('fee_amount');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('fee_amount');
        });
    }
};
