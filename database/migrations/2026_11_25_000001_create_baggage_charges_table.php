<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-25 (user): baggage in the compartment is paid in cash to the Chauffeur on board, at the size the Chauffeur
 * sees (small, medium, large). Online the passenger only says they bring baggage (tickets.has_baggage). Each payment is
 * one baggage_charges row, kept apart from the ticket's fare, so Finance and the remittance show it as its own cash.
 * A charge the Chauffeur takes back (a mistake, or the cash handed back) is voided, never deleted.
 * The Operator sets the price of each size for the company (null = the config default, fares.baggage_sizes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baggage_charges', function (Blueprint $table) {
            $table->id('baggage_charge_id');
            $table->foreignId('trip_id')->constrained('trips', 'trip_id')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets', 'ticket_id')->cascadeOnDelete();
            $table->unsignedBigInteger('conductor_company_user_id')->nullable();
            $table->foreign('conductor_company_user_id')->references('company_user_id')->on('company_users')->nullOnDelete();
            $table->unsignedBigInteger('conductor_shift_log_id')->nullable();
            $table->foreign('conductor_shift_log_id')->references('shift_log_id')->on('conductor_shift_logs')->nullOnDelete();
            $table->string('size', 10); // small | medium | large
            $table->decimal('amount', 10, 2);
            $table->string('stub_number', 30)->nullable();
            $table->timestamp('collected_at');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index('conductor_shift_log_id');
            $table->index(['trip_id', 'ticket_id']);
            $table->index('collected_at');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('baggage_fee_small', 8, 2)->nullable();
            $table->decimal('baggage_fee_medium', 8, 2)->nullable();
            $table->decimal('baggage_fee_large', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['baggage_fee_small', 'baggage_fee_medium', 'baggage_fee_large']);
        });
        Schema::dropIfExists('baggage_charges');
    }
};
