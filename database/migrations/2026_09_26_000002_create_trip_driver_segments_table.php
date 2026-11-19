<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2 corrected model (Batch 17 continuation, 2026-09-26): "one trip per day
 * per staff member, with driver hand-off support within a single trip".
 *
 * A trip is still ONE trip per driver per day (hard exclusivity — see
 * TripRepository::hasAnySameDayAssignment()), but a single trip's service
 * window can be split into sequential driver segments (e.g. Driver A
 * 6AM–12PM, Driver B 12PM–6PM on the same bus/trip, one Conductor spanning
 * both). This is a dedicated, normalized table for that segment plan — NOT
 * a nullable bolt-on column on `trips` or `fleet_daily_pins` (confirmed
 * with the reporter: keep this as its own table).
 *
 * The Conductor intentionally has NO equivalent segment table — confirmed
 * with the reporter that conductors always span the full trip; only the
 * driver role segments.
 *
 * `trips.driver_id`/`trips.driver_accepted_at` remain the single source of
 * truth for "who is the CURRENTLY ACTIVE driver on this trip right now" —
 * kept in sync by TripService::confirmHandoff() whenever the active
 * segment changes. This is deliberate: every existing driver-facing flow
 * (shift start, PIN/QR, pairing, dashboards) already reads trips.driver_id
 * and needed ZERO changes as a result — only segment planning /
 * acceptance / hand-off are new. fleet_daily_pins also needed NO schema
 * change: FleetDailyPinService::generateOrGet() already resets pairing
 * verification whenever the driver_id it's called with changes, which is
 * exactly the desired behavior on hand-off (Driver B must re-pair with the
 * Conductor before departing on their segment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_driver_segments', function (Blueprint $table) {
            $table->id('segment_id');
            $table->foreignId('trip_id')->constrained('trips', 'trip_id')->cascadeOnDelete();
            $table->unsignedBigInteger('driver_company_user_id')->nullable();
            $table->unsignedTinyInteger('sequence_order');
            $table->time('segment_start_time');
            $table->time('segment_end_time');
            // scheduled -> active -> completed, or declined (driver turned it down)
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('driver_accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            // Explicit hand-off confirmation (reporter-confirmed default):
            // set when this segment's driver confirms the reconciliation
            // checkpoint, which is what triggers the NEXT segment to
            // activate — hand-off is never purely schedule-driven.
            $table->timestamp('handoff_confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('driver_company_user_id')->references('company_user_id')->on('company_users')->nullOnDelete();
            $table->unique(['trip_id', 'sequence_order']);
            $table->index(['driver_company_user_id']);
            $table->index(['trip_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_driver_segments');
    }
};
