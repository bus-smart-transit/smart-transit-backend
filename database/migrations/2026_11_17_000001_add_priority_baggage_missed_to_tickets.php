<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 76 (user, 2026-11-17): a ticket can ask for a priority seat (senior, PWD, pregnant), can carry baggage in the
 * compartment (the baggage fee is added to the ticket's fare; only the yes/no is kept, so the Chauffeur knows to load
 * it), and is marked "missed" when the bus leaves the passenger's boarding stop before the ticket was scanned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('priority_type', 16)->nullable()->after('discount_id_checked_at');
            $table->boolean('has_baggage')->default(false)->after('priority_type');
            $table->timestamp('missed_at')->nullable()->after('has_baggage');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['priority_type', 'has_baggage', 'missed_at']);
        });
    }
};
