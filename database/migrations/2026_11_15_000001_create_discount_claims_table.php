<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 75 (panel, 2026-11-15): a Student, Senior citizen or PWD discount claimed when buying online. The passenger
 * pays the full fare; the Chauffeur checks the ID on the bus and approves or rejects the claim. On approval the
 * difference goes back through PayMongo (the refund columns follow ticket_resolutions). One claim per ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->unique();
            $table->string('discount_type_key', 32);
            $table->string('discount_label', 64);
            $table->decimal('discount_rate', 4, 3);
            $table->string('status', 12); // claimed | approved | rejected | lapsed
            $table->decimal('fare', 10, 2); // the full fare the ticket was sold at
            $table->decimal('refund_amount', 10, 2)->nullable(); // set on approval
            $table->string('refund_status', 12)->nullable(); // pending | succeeded | failed (approved claims only)
            $table->string('gateway_payment_id', 64)->nullable();
            $table->string('gateway_refund_id', 64)->nullable();
            $table->string('gateway_status', 32)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('not_before')->nullable(); // UTC: a Maya partial refund waits for the next Manila midnight
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable(); // UTC
            $table->timestamp('completed_at')->nullable(); // UTC: the refund succeeded
            $table->timestamps();
            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->index('gateway_refund_id');
            $table->index(['status', 'refund_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_claims');
    }
};
