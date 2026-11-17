<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 76 (user, 2026-11-17): a ticket can ask for a priority seat (senior, PWD, pregnant), can carry baggage in the
 * compartment (its own fee, kept apart from the fare so a discount never touches it), and is marked "missed" when the
 * bus leaves the passenger's boarding stop before the ticket was scanned (the seat goes back on sale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('priority_type', 16)->nullable()->after('discount_id_checked_at');
            $table->decimal('baggage_fee', 10, 2)->default(0)->after('priority_type');
            $table->timestamp('missed_at')->nullable()->after('baggage_fee');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['priority_type', 'baggage_fee', 'missed_at']);
        });
    }
};
