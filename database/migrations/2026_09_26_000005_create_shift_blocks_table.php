<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 18: Shift Block Hand-off System.
 *
 * PK convention: matches the rest of this schema (auto-increment bigint
 * with a descriptive PK name — trips.trip_id, fleets.fleet_id,
 * company_users.company_user_id — not UUID). shift_blocks.shift_block_id
 * follows the same pattern.
 *
 * `block_type` is a plain string (not a DB-level enum) so new block types
 * beyond morning/afternoon can be added later without a migration —
 * validated at the application layer instead (see ShiftBlockService).
 *
 * Deliberately does NOT duplicate leg data — each leg of a shift block is
 * simply an existing `trips` row, tagged via the new nullable
 * trips.shift_block_id FK (see the companion migration). No separate
 * shift_block_legs table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_blocks', function (Blueprint $table) {
            $table->id('shift_block_id');
            $table->foreignId('fleet_id')->constrained('fleets', 'fleet_id')->cascadeOnDelete();
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('conductor_id');
            $table->unsignedBigInteger('company_user_id'); // owning Operator, mirrors trips.company_user_id
            $table->string('block_type', 40); // e.g. 'morning' | 'afternoon' — validated, not DB-enum
            $table->string('status', 20)->default('scheduled'); // scheduled|in_progress|completed|cancelled
            $table->string('audit_status', 20)->default('not_applicable'); // not_applicable|pending_review|reviewed
            $table->date('scheduled_date');
            $table->timestamps();

            $table->foreign('driver_id')->references('company_user_id')->on('company_users')->cascadeOnDelete();
            $table->foreign('conductor_id')->references('company_user_id')->on('company_users')->cascadeOnDelete();
            $table->foreign('company_user_id')->references('company_user_id')->on('company_users')->cascadeOnDelete();

            $table->index(['driver_id', 'scheduled_date']);
            $table->index(['conductor_id', 'scheduled_date']);
            $table->index(['fleet_id', 'scheduled_date']);
            $table->index(['audit_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_blocks');
    }
};
