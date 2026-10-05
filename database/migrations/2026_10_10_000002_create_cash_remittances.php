<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 29, cash step 3: one remittance batch per Conductor shift, separate from the shift record.
 *
 * The shift log is what the Conductor reported (opening cash, sales, the shift window). The batch is what was handed
 * over and confirmed: the closing cash the Conductor declared (null when the shift was closed without a count, e.g. an
 * automatic close; `declaration` says which, for audit), the amount the Operator counted, who confirmed and when.
 * A batch is created `pending` when the shift ends, never escalates, and needs no same-day confirmation.
 * Timestamps are Manila wall-clock like the other app tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_remittances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conductor_shift_log_id')->unique();
            $table->unsignedBigInteger('conductor_company_user_id')->index();
            $table->unsignedBigInteger('operator_company_user_id')->nullable()->index();
            $table->string('status', 16)->default('pending')->index(); // pending | confirmed | flagged
            $table->string('declaration', 16)->default('none');         // declared | none (no Conductor count)
            $table->decimal('declared_cash', 10, 2)->nullable();
            $table->timestamp('declared_at')->nullable();
            $table->decimal('expected_cash', 10, 2)->nullable();        // frozen when the Operator confirms
            $table->decimal('confirmed_amount', 10, 2)->nullable();
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->foreign('conductor_shift_log_id')->references('shift_log_id')->on('conductor_shift_logs')->cascadeOnDelete();
        });

        // Shifts that already ended with cash sales get a pending batch (no declaration was ever taken). Shifts with no
        // cash activity are not backfilled: that would only fill the Operator's list with empty batches.
        $shiftIds = DB::table('onsite_payment')->whereNotNull('conductor_shift_log_id')->distinct()->pluck('conductor_shift_log_id');
        foreach (DB::table('conductor_shift_logs')->whereIn('shift_log_id', $shiftIds)->whereNotNull('ended_at')->get() as $shift) {
            $owner = DB::table('fleets')->where('fleet_id', $shift->fleet_id)->value('company_user_id')
                ?? DB::table('trips')->where('trip_id', $shift->trip_id)->value('company_user_id');
            DB::table('cash_remittances')->insert([
                'conductor_shift_log_id' => $shift->shift_log_id,
                'conductor_company_user_id' => $shift->conductor_company_user_id,
                'operator_company_user_id' => $owner,
                'status' => 'pending',
                'declaration' => 'none',
                'created_at' => $shift->ended_at,
                'updated_at' => $shift->ended_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_remittances');
    }
};
