<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 29, cash handling step 2: the Conductor shift owns its cash.
 *
 *  - conductor_shift_logs.opening_cash: what the Conductor declares at Start Shift (default 0).
 *  - onsite_payment.conductor_shift_log_id: the shift a cash sale was made in (set at the sale).
 *  - ticket_resolutions.conductor_shift_log_id: the shift that SOLD the refunded cash ticket (adviser decision: a late
 *    refund still reduces the cash of the Conductor who collected it, whatever shift is open when it is paid out).
 *
 * Existing rows are linked by the shift window (Manila wall-clock started_at/ended_at) that contains the cash sale's
 * issued_at (UTC); a sale outside every shift of its Conductor stays unlinked. Nothing is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conductor_shift_logs', function (Blueprint $table) {
            $table->decimal('opening_cash', 10, 2)->default(0);
        });

        Schema::table('onsite_payment', function (Blueprint $table) {
            $table->unsignedBigInteger('conductor_shift_log_id')->nullable()->index();
        });

        Schema::table('ticket_resolutions', function (Blueprint $table) {
            $table->unsignedBigInteger('conductor_shift_log_id')->nullable()->index();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('ticket_resolutions', function (Blueprint $table) {
            $table->dropIndex(['conductor_shift_log_id']);
            $table->dropColumn('conductor_shift_log_id');
        });
        Schema::table('onsite_payment', function (Blueprint $table) {
            $table->dropIndex(['conductor_shift_log_id']);
            $table->dropColumn('conductor_shift_log_id');
        });
        Schema::table('conductor_shift_logs', function (Blueprint $table) {
            $table->dropColumn('opening_cash');
        });
    }

    private function backfill(): void
    {
        $tz = config('app.timezone', 'Asia/Manila');
        $shifts = DB::table('conductor_shift_logs')->get(['shift_log_id', 'conductor_company_user_id', 'started_at', 'ended_at']);
        if ($shifts->isEmpty()) {
            return;
        }

        DB::table('onsite_payment')->whereNull('conductor_shift_log_id')->orderBy('onsite_payment_id')->chunkById(200, function ($rows) use ($shifts, $tz) {
            foreach ($rows as $row) {
                $at = $row->issued_at ? Carbon::parse($row->issued_at, 'UTC')->setTimezone($tz) : ($row->created_at ? Carbon::parse($row->created_at, $tz) : null);
                if (! $at) {
                    continue;
                }
                $match = $shifts
                    ->where('conductor_company_user_id', $row->conductor_id)
                    ->filter(fn ($s) => Carbon::parse($s->started_at, $tz)->lte($at) && ($s->ended_at === null || Carbon::parse($s->ended_at, $tz)->gte($at)))
                    ->sortByDesc('started_at')
                    ->first();
                if ($match) {
                    DB::table('onsite_payment')->where('onsite_payment_id', $row->onsite_payment_id)->update(['conductor_shift_log_id' => $match->shift_log_id]);
                }
            }
        }, 'onsite_payment_id');

        // Cash refunds follow the sale of their ticket.
        DB::table('ticket_resolutions')->where('method', 'cash')->where('resolution', 'refund')->whereNull('conductor_shift_log_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $shiftId = DB::table('tickets')
                    ->join('onsite_payment', 'onsite_payment.payment_id', '=', 'tickets.payment_id')
                    ->where('tickets.ticket_id', $row->ticket_id)
                    ->value('onsite_payment.conductor_shift_log_id');
                if ($shiftId) {
                    DB::table('ticket_resolutions')->where('id', $row->id)->update(['conductor_shift_log_id' => $shiftId]);
                }
            }
        });
    }
};
