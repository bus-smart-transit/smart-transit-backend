<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 27 Part 2, Task 1: pairing and the daily PIN belong to the shift block.
     *
     * A Driver and Chauffeur paired for a block on a day stay paired for every leg, so the pin row is found by
     * (shift_block_id, pin_date). trip_id stays (NOT NULL, unique with pin_date) and holds the block's FIRST leg,
     * the anchor, so every older reader and the staff_id + date scoping keep working. A standalone trip keeps
     * shift_block_id null and is found by trip_id as before.
     *
     * Backfill only links an existing pin whose trip already belongs to a block.
     */
    public function up(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_block_id')->nullable()->after('trip_id');
            $table->foreign('shift_block_id')->references('shift_block_id')->on('shift_blocks')->cascadeOnDelete();
            $table->unique(['shift_block_id', 'pin_date']);
        });

        foreach (DB::table('fleet_daily_pins')->get() as $pin) {
            $blockId = DB::table('trips')->where('trip_id', $pin->trip_id)->value('shift_block_id');
            if (! $blockId) {
                continue;
            }
            // One pin per block and day: keep the first, leave a duplicate (same block and day) unlinked.
            $taken = DB::table('fleet_daily_pins')->where('shift_block_id', $blockId)->where('pin_date', $pin->pin_date)->exists();
            if (! $taken) {
                DB::table('fleet_daily_pins')->where('id', $pin->id)->update(['shift_block_id' => $blockId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('fleet_daily_pins', function (Blueprint $table) {
            $table->dropUnique(['shift_block_id', 'pin_date']);
            $table->dropForeign(['shift_block_id']);
            $table->dropColumn('shift_block_id');
        });
    }
};
