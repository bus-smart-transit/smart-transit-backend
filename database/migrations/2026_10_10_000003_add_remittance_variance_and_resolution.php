<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 29, cash step 4: the tolerance check and the discrepancy lifecycle (flagged -> resolved).
 *
 *  - variance: the declared count minus the expected cash when the batch was confirmed (null when nobody declared).
 *    Recorded whether or not it triggered a flag, and never overwritten.
 *  - tolerance_applied: the configured tolerance in force at that moment, for the audit trail.
 *  - flagged_at, resolved_by_user_id, resolved_at, resolution_note: how a flagged batch was closed out.
 * The status gains `resolved` (a string column, so no schema change for the value itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_remittances', function (Blueprint $table) {
            $table->decimal('variance', 10, 2)->nullable();
            $table->decimal('tolerance_applied', 10, 2)->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_note', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cash_remittances', function (Blueprint $table) {
            $table->dropColumn(['variance', 'tolerance_applied', 'flagged_at', 'resolved_by_user_id', 'resolved_at', 'resolution_note']);
        });
    }
};
