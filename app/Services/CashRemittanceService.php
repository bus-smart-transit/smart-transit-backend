<?php

namespace App\Services;

use App\Models\CashRemittance;
use App\Models\ConductorShiftLog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batch 29, cash step 3: the remittance batch of a Conductor shift.
 *
 * A batch is created `pending` when a shift ends (End Shift, a trip-status close, a stale close, the repair command).
 * The Conductor's closing count is stored on it when given; when it is not (an automatic close) the batch says so
 * (`declaration = none`) and nothing is invented. The Operator who owns the fleet confirms it on the web dashboard by
 * entering (or accepting) the counted amount. The batch never escalates and needs no same-day confirmation.
 * Both the Operator's recording of cash refunds and this confirmation are the Operator's: a documented scope limitation.
 */
class CashRemittanceService
{
    public function __construct(private ShiftCashService $cash) {}

    /** Creates the pending batch of an ended shift (once per shift). */
    public function openForShift(ConductorShiftLog $log, ?float $declaredCash = null): CashRemittance
    {
        $existing = CashRemittance::where('conductor_shift_log_id', $log->shift_log_id)->first();
        if ($existing) {
            return $existing;
        }

        $owner = DB::table('fleets')->where('fleet_id', $log->fleet_id)->value('company_user_id')
            ?? DB::table('trips')->where('trip_id', $log->trip_id)->value('company_user_id');
        $declared = $declaredCash !== null ? round(max(0.0, $declaredCash), 2) : null;

        return CashRemittance::create([
            'conductor_shift_log_id' => $log->shift_log_id,
            'conductor_company_user_id' => $log->conductor_company_user_id,
            'operator_company_user_id' => $owner,
            'status' => CashRemittance::PENDING,
            'declaration' => $declared !== null ? CashRemittance::DECLARED : CashRemittance::NONE,
            'declared_cash' => $declared,
            'declared_at' => $declared !== null ? now() : null,
        ]);
    }

    /**
     * One page of the Operator's batches.
     *
     * @param  array{status?: string, page?: int, per_page?: int}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginate(int $operatorCompanyUserId, array $filters): array
    {
        $status = $filters['status'] ?? 'pending';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        $query = $this->baseQuery()->where('r.operator_company_user_id', $operatorCompanyUserId);
        if (in_array($status, [CashRemittance::PENDING, CashRemittance::CONFIRMED, CashRemittance::FLAGGED, CashRemittance::RESOLVED], true)) {
            $query->where('r.status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('s.ended_at')->orderByDesc('r.id')->forPage($page, $perPage)->get();

        return [
            'data' => $rows->map(fn ($row) => $this->present($row))->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'status' => $status, 'tolerance_amount' => $this->tolerance()],
        ];
    }

    /**
     * The Operator confirms a pending batch with the amount they counted. Omitting it accepts the Conductor's declared
     * count; with no declaration the Operator must enter the amount.
     *
     * @return array<string, mixed>
     */
    public function confirm(int $remittanceId, int $operatorCompanyUserId, object $operator, ?float $countedAmount, ?string $note): array
    {
        DB::transaction(function () use ($remittanceId, $operatorCompanyUserId, $operator, $countedAmount, $note) {
            // Another Operator's batch is the same answer as a missing one.
            $batch = CashRemittance::whereKey($remittanceId)->where('operator_company_user_id', $operatorCompanyUserId)->lockForUpdate()->first();
            if (! $batch) {
                throw new ModelNotFoundException;
            }
            if ($batch->status !== CashRemittance::PENDING) {
                throw ValidationException::withMessages(['status' => ['This remittance has already been '.$batch->status.'.']]);
            }

            $counted = $countedAmount ?? ($batch->declared_cash !== null ? (float) $batch->declared_cash : null);
            if ($counted === null) {
                throw ValidationException::withMessages(['counted_amount' => ['The Chauffeur gave no count for this shift. Enter the amount you counted.']]);
            }
            $counted = round(max(0.0, $counted), 2);
            $expected = $this->cash->summary((int) $batch->conductor_shift_log_id)['expected_cash'];

            // Batch 29, step 4: the Chauffeur's declared count against the expected cash. Within the global tolerance it is
            // approved (confirmed); beyond it the batch is flagged. The variance is recorded either way. With no declaration
            // there is nothing to compare, so a batch is never flagged on that basis alone and has no variance.
            $tolerance = $this->tolerance();
            $varianceCents = null;
            $status = CashRemittance::CONFIRMED;
            if ($batch->declaration === CashRemittance::DECLARED && $batch->declared_cash !== null) {
                $varianceCents = (int) round(((float) $batch->declared_cash) * 100) - (int) round($expected * 100);
                if (abs($varianceCents) > (int) round($tolerance * 100)) {
                    $status = CashRemittance::FLAGGED;
                }
            }

            $batch->update([
                'status' => $status,
                'confirmed_amount' => $counted,
                'confirmed_by_user_id' => $operator->user_id,
                'confirmed_at' => now(),
                'expected_cash' => $expected,
                'variance' => $varianceCents !== null ? round($varianceCents / 100, 2) : null,
                'tolerance_applied' => $varianceCents !== null ? $tolerance : null,
                'flagged_at' => $status === CashRemittance::FLAGGED ? now() : null,
                'note' => $note !== null && $note !== '' ? mb_substr(strip_tags($note), 0, 255) : null,
            ]);

            AuditLogger::log($status === CashRemittance::FLAGGED ? 'remittance.flagged' : 'remittance.confirmed', 'CashRemittance', (int) $batch->id, [
                'shift_log_id' => (int) $batch->conductor_shift_log_id,
                'declaration' => $batch->declaration,
                'declared_cash' => $batch->declared_cash,
                'counted_amount' => $counted,
                'expected_cash' => $expected,
                'variance' => $varianceCents !== null ? round($varianceCents / 100, 2) : null,
                'tolerance' => $tolerance,
            ]);
        });

        $row = $this->baseQuery()->where('r.id', $remittanceId)->first();

        return $this->present($row);
    }

    /**
     * The Operator closes out a FLAGGED batch after investigating it. The explanation is required. The batch becomes
     * `resolved` (distinct from a batch that matched on the first confirm) and the variance it was flagged for is kept.
     *
     * @return array<string, mixed>
     */
    public function resolve(int $remittanceId, int $operatorCompanyUserId, object $operator, string $resolutionNote): array
    {
        $note = trim(strip_tags($resolutionNote));
        if ($note === '') {
            throw ValidationException::withMessages(['resolution_note' => ['Explain the discrepancy before resolving it.']]);
        }

        DB::transaction(function () use ($remittanceId, $operatorCompanyUserId, $operator, $note) {
            $batch = CashRemittance::whereKey($remittanceId)->where('operator_company_user_id', $operatorCompanyUserId)->lockForUpdate()->first();
            if (! $batch) {
                throw new ModelNotFoundException;
            }
            if ($batch->status !== CashRemittance::FLAGGED) {
                throw ValidationException::withMessages(['status' => ['Only a flagged remittance can be resolved. This one is '.$batch->status.'.']]);
            }

            $batch->update([
                'status' => CashRemittance::RESOLVED,
                'resolved_by_user_id' => $operator->user_id,
                'resolved_at' => now(),
                'resolution_note' => mb_substr($note, 0, 255),
            ]);

            AuditLogger::log('remittance.resolved', 'CashRemittance', (int) $batch->id, [
                'shift_log_id' => (int) $batch->conductor_shift_log_id,
                'variance' => $batch->variance,
                'resolution_note' => mb_substr($note, 0, 255),
            ]);
        });

        return $this->present($this->baseQuery()->where('r.id', $remittanceId)->first());
    }

    /** The one global tolerance (config/remittance.php), in pesos, never negative; a variance within it is approved. */
    private function tolerance(): float
    {
        return round(max(0.0, (float) config('remittance.tolerance_amount')), 2);
    }

    private function baseQuery()
    {
        return DB::table('cash_remittances as r')
            ->join('conductor_shift_logs as s', 's.shift_log_id', '=', 'r.conductor_shift_log_id')
            ->join('company_users as c', 'c.company_user_id', '=', 'r.conductor_company_user_id')
            ->leftJoin('company_users as cf', 'cf.user_id', '=', 'r.confirmed_by_user_id')
            ->leftJoin('company_users as cr', 'cr.user_id', '=', 'r.resolved_by_user_id')
            ->leftJoin('fleets as f', 'f.fleet_id', '=', 's.fleet_id')
            ->leftJoin('trips as t', 't.trip_id', '=', 's.trip_id')
            ->leftJoin('fleets_routes as fr', 'fr.fleet_route_id', '=', 't.fleet_route_id')
            ->leftJoin('routes as ro', 'ro.route_id', '=', 'fr.route_id')
            ->select([
                'r.id', 'r.status', 'r.declaration', 'r.declared_cash', 'r.declared_at', 'r.expected_cash', 'r.confirmed_amount', 'r.confirmed_at', 'r.note',
                'r.conductor_shift_log_id', 'c.name as conductor_name', 'cf.name as confirmed_by_name',
                'r.variance', 'r.tolerance_applied', 'r.flagged_at', 'r.resolved_at', 'r.resolution_note', 'cr.name as resolved_by_name',
                's.shift_date', 's.started_at', 's.ended_at', 's.end_source',
                'f.plate_number', 'f.bus_company_name', 'f.body_no', 'ro.route_name',
            ]);
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $live = $this->cash->summary((int) $row->conductor_shift_log_id);
        $confirmed = $row->status !== CashRemittance::PENDING;
        $money = fn ($v) => $v === null ? null : round((float) $v, 2);
        $variance = $money($row->variance);
        // For a pending batch with a declared count: what the difference stands at now (information only; the check
        // runs when the Operator confirms).
        $varianceNow = (! $confirmed && $row->declaration === CashRemittance::DECLARED && $row->declared_cash !== null)
            ? round((float) $row->declared_cash - $live['expected_cash'], 2)
            : null;
        $kind = fn ($v) => $v === null ? null : ($v > 0 ? 'over' : ($v < 0 ? 'short' : 'matched'));

        return [
            'id' => (int) $row->id,
            'status' => $row->status,
            'declaration' => $row->declaration,
            'no_declaration' => $row->declaration === CashRemittance::NONE,
            'shift' => [
                'shift_log_id' => (int) $row->conductor_shift_log_id,
                'shift_date' => substr((string) $row->shift_date, 0, 10),
                'started_at' => $row->started_at,
                'ended_at' => $row->ended_at,
                'end_source' => $row->end_source,
                'conductor_name' => $row->conductor_name,
                'plate_number' => $row->plate_number,
                'bus_label' => \App\Support\BusLabel::compose($row->bus_company_name, $row->body_no),
                'route_name' => $row->route_name,
            ],
            'opening_cash' => $live['opening_cash'],
            'cash_sales' => $live['cash_sales'],
            'cash_refunds' => $live['cash_refunds'],
            // A pending batch shows the cash as it stands now (a late refund may still arrive); a confirmed one keeps the
            // amount that was expected when it was confirmed, and `expected_now` shows if that has moved since.
            'expected_cash' => $confirmed ? $money($row->expected_cash) : $live['expected_cash'],
            'expected_now' => $live['expected_cash'],
            'declared_cash' => $money($row->declared_cash),
            'declared_at' => $row->declared_at,
            'confirmed_amount' => $money($row->confirmed_amount),
            'confirmed_at' => $row->confirmed_at,
            'confirmed_by_name' => $row->confirmed_by_name,
            'note' => $row->note,
            // Batch 29, step 4: the declared count against the expected cash when it was confirmed (null when nobody
            // declared). It is recorded whether or not it triggered a flag and is never overwritten, not even on resolve.
            'variance' => $variance,
            'variance_kind' => $kind($variance),
            'variance_now' => $varianceNow,
            'variance_now_kind' => $kind($varianceNow),
            'tolerance_applied' => $money($row->tolerance_applied),
            'flagged_at' => $row->flagged_at,
            'resolution' => $row->resolved_at === null ? null : ['note' => $row->resolution_note, 'resolved_by_name' => $row->resolved_by_name, 'resolved_at' => $row->resolved_at],
        ];
    }
}
