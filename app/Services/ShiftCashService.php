<?php

namespace App\Services;

use App\Models\ConductorShiftLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Batch 29, cash handling step 2: the cash of one Conductor shift.
 *
 *   expected cash = opening cash (declared at Start Shift) + cash sales of the shift - cash refunds of the shift
 *
 * A cash sale belongs to the shift it was made in (`onsite_payment.conductor_shift_log_id`, set at the sale). A cash refund
 * belongs to the shift that SOLD the refunded ticket, not to the shift open when the Operator pays it out
 * (`ticket_resolutions.conductor_shift_log_id`), so a late refund still reduces the cash of the Conductor who collected it.
 * Only refunds that SUCCEEDED count (the cash was actually handed back). Amounts are the stored ticket and refund amounts.
 */
class ShiftCashService
{
    /** The Conductor's currently open shift, if any. */
    public function openShiftId(int $conductorCompanyUserId): ?int
    {
        $id = ConductorShiftLog::where('conductor_company_user_id', $conductorCompanyUserId)->whereNull('ended_at')->orderByDesc('started_at')->value('shift_log_id');

        return $id ? (int) $id : null;
    }

    /** The shift that sold this ticket, or null when it is not a cash ticket or no shift can be matched. */
    public function shiftIdForTicket(int $ticketId): ?int
    {
        $sale = DB::table('tickets')
            ->join('onsite_payment', 'onsite_payment.payment_id', '=', 'tickets.payment_id')
            ->where('tickets.ticket_id', $ticketId)
            ->first(['onsite_payment.conductor_shift_log_id', 'onsite_payment.conductor_id', 'onsite_payment.issued_at']);

        if (! $sale) {
            return null;
        }
        if ($sale->conductor_shift_log_id) {
            return (int) $sale->conductor_shift_log_id;
        }

        // A sale made before the link existed: the shift of that Conductor whose window contains the sale time.
        $tz = config('app.timezone', 'Asia/Manila');
        $at = $sale->issued_at ? Carbon::parse($sale->issued_at, 'UTC')->setTimezone($tz) : null;
        if (! $at) {
            return null;
        }

        $id = ConductorShiftLog::where('conductor_company_user_id', $sale->conductor_id)
            ->where('started_at', '<=', $at->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $at->format('Y-m-d H:i:s')))
            ->orderByDesc('started_at')
            ->value('shift_log_id');

        return $id ? (int) $id : null;
    }

    /** @return array<string, mixed>|null */
    public function summaryForOpenShift(int $conductorCompanyUserId): ?array
    {
        $id = $this->openShiftId($conductorCompanyUserId);

        return $id ? $this->summary($id) : null;
    }

    /** @return array<string, mixed> */
    public function summary(int $shiftLogId): array
    {
        $shift = ConductorShiftLog::findOrFail($shiftLogId);
        $opening = (int) round(((float) $shift->opening_cash) * 100);

        $sales = DB::table('onsite_payment')
            ->join('payments', 'payments.payment_id', '=', 'onsite_payment.payment_id')
            ->join('tickets', 'tickets.payment_id', '=', 'payments.payment_id')
            ->where('onsite_payment.conductor_shift_log_id', $shiftLogId)
            ->where('payments.status', 'paid')
            ->where('payments.is_valid', true)
            ->where('tickets.status', '!=', 'rebooked')
            ->get(['tickets.amount']);
        $salesCents = (int) $sales->sum(fn ($r) => (int) round(((float) $r->amount) * 100));

        $refunds = DB::table('ticket_resolutions')
            ->where('conductor_shift_log_id', $shiftLogId)
            ->where('resolution', 'refund')
            ->where('method', 'cash')
            ->where('status', 'succeeded')
            ->whereNotNull('amount')
            ->get(['amount']);
        $refundCents = (int) $refunds->sum(fn ($r) => (int) round(((float) $r->amount) * 100));

        return [
            'shift_log_id' => (int) $shift->shift_log_id,
            'opening_cash' => $this->money($opening),
            'cash_sales' => $this->money($salesCents),
            'cash_sales_tickets' => $sales->count(),
            'cash_refunds' => $this->money($refundCents),
            'cash_refund_count' => $refunds->count(),
            'expected_cash' => $this->money($opening + $salesCents - $refundCents),
        ];
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
