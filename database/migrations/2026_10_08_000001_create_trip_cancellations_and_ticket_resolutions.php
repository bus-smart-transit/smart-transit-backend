<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 28 Stage 3, item 6: operator-cancelled trips (rebook or refund).
 *
 *  - trip_cancellations: one row per cancelled trip (who, when in UTC, the stage it was cancelled at and, for a
 *    cancellation after departure, the last point the bus is known to have reached and where that came from).
 *  - ticket_resolutions: one row per ticket, ever (unique ticket_id), saying how the cancelled ticket was settled:
 *    a rebook onto a later departure, or a refund (gateway reversal for a digital ticket, a recorded cash entry
 *    for a cash ticket). The unique key is what makes a double tap or a replayed webhook harmless.
 *  - payments.gateway_payment_id: PayMongo's own payment id (pay_...), taken from the paid webhook, which a refund
 *    needs. (Older payments are resolved from the checkout session at refund time.)
 *
 * All timestamps are stored as UTC. No existing column changes; tickets.status simply gains new values:
 * trip_cancelled (awaiting the passenger's choice), rebooked, refund_pending, refunded, refund_review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_cancellations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('trip_id')->unique();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->string('actor_role', 20)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('stage', 20); // before_departure | mid_route
            $table->timestamp('cancelled_at')->useCurrent(); // UTC
            $table->integer('last_point_distance_m')->nullable(); // metres from the route's first stop
            $table->string('last_point_source', 20)->nullable(); // stop_ack | gps
            $table->unsignedBigInteger('last_point_stop_id')->nullable();
            $table->timestamp('last_point_at')->nullable(); // UTC: when that point was recorded
            $table->timestamps();

            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
        });

        Schema::create('ticket_resolutions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->unique();
            $table->unsignedBigInteger('trip_cancellation_id');
            $table->string('resolution', 12); // rebook | refund
            $table->string('status', 20); // pending | succeeded | failed | needs_review
            $table->string('method', 12)->nullable(); // gateway | cash
            $table->decimal('amount', 10, 2)->nullable(); // the refund
            $table->decimal('fare_paid', 10, 2)->nullable();
            $table->decimal('ridden_fare', 10, 2)->nullable();
            $table->json('basis')->nullable(); // how the amount was worked out (distances, rule, last point)
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_role', 20)->nullable();
            $table->boolean('on_behalf')->default(false); // staff chose for the passenger
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('rebooked_ticket_id')->nullable();
            $table->string('gateway_payment_id', 64)->nullable();
            $table->string('gateway_refund_id', 64)->nullable();
            $table->string('gateway_status', 32)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('not_before')->nullable(); // UTC: a Maya partial refund waits for the next Manila midnight
            $table->timestamp('requested_at')->useCurrent(); // UTC
            $table->timestamp('completed_at')->nullable(); // UTC
            $table->timestamps();

            $table->foreign('ticket_id')->references('ticket_id')->on('tickets')->cascadeOnDelete();
            $table->foreign('trip_cancellation_id')->references('id')->on('trip_cancellations')->cascadeOnDelete();
            $table->index('gateway_refund_id');
            $table->index(['status', 'method']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_payment_id', 64)->nullable()->after('payment_intent_id');
        });

    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('gateway_payment_id');
        });
        Schema::dropIfExists('ticket_resolutions');
        Schema::dropIfExists('trip_cancellations');
    }
};
