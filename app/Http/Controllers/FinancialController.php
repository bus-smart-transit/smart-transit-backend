<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancialFilterRequest;
use App\Services\AuditLogger;
use App\Services\FinancialReportService;
use App\Traits\ApiResponse;

/**
 * Batch 29, Stage 2: the Operator's financial page (summary, paginated log, CSV). An Operator is scoped to their own
 * fleets inside the query itself; an Admin sees every operator. A foreign route, fleet or trip id simply matches nothing.
 */
class FinancialController extends Controller
{
    use ApiResponse;

    public function __construct(private FinancialReportService $reports) {}

    /** GET /financials: the figures for the chosen range. */
    public function summary(FinancialFilterRequest $request)
    {
        [$owner, $filters] = $this->scope($request);

        return $this->success($this->reports->report($owner, $filters), 'Financial summary retrieved successfully');
    }

    /** GET /financials/transactions: one sorted, filtered page of the log. */
    public function transactions(FinancialFilterRequest $request)
    {
        [$owner, $filters] = $this->scope($request);

        return $this->success($this->reports->transactions($owner, $filters), 'Transactions retrieved successfully');
    }

    /** GET /financials/export?type=transactions|daily: the same query as the screen, as CSV. */
    public function export(FinancialFilterRequest $request)
    {
        [$owner, $filters] = $this->scope($request);
        $type = $request->validated('type') ?? 'transactions';

        $csv = $type === 'daily' ? $this->reports->dailyCsv($owner, $filters) : $this->reports->transactionsCsv($owner, $filters);
        AuditLogger::log('financials.export', 'Financials', null, ['type' => $type, 'from' => $filters['from'], 'to' => $filters['to']]);

        $name = "financial-{$type}-{$filters['from']}".($filters['to'] !== $filters['from'] ? "_to_{$filters['to']}" : '').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array{0: ?int, 1: array<string, mixed>} the owner's company_user_id (null = Admin, everything) and the normalised filters */
    private function scope(FinancialFilterRequest $request): array
    {
        $user = $request->user();
        $filters = $this->reports->normalize($request->validated());

        if ($user?->role === 'admin') {
            return [null, $filters];
        }

        $companyUser = $user?->companyProfile;
        if (! $companyUser) {
            abort(404, 'Staff profile not found.');
        }

        return [(int) $companyUser->company_user_id, $filters];
    }
}
