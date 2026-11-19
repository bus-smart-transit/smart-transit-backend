<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A payment that arrived after its seats were released and could not be honoured is marked `needs_refund` (no ticket is
// issued). The Operator refunds it in PayMongo and records that here: when, who, and a note (the PayMongo refund id).
// Existing rows keep NULL. `status` is a plain string, so the new value `refunded` needs no schema change.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'refunded_at')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable();
            $table->string('refund_note', 255)->nullable();
            $table->unsignedBigInteger('refunded_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'refunded_at')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn(['refunded_at', 'refund_note', 'refunded_by_user_id']);
            });
        }
    }
};
