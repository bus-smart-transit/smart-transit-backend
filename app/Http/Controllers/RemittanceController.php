<?php

namespace App\Http\Controllers;

use App\Services\CashRemittanceService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Batch 29, cash step 3: the Operator's remittance list and the confirm action. Operator only (an Admin has no daily
 * cash role); an Operator sees and confirms only the batches of their own fleets. Another Operator's batch is a 404.
 */
class RemittanceController extends Controller
{
    use ApiResponse;

    public function __construct(private CashRemittanceService $remittances) {}

    /** GET /operator/remittances?status=pending|confirmed|flagged|resolved|all&page= */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,confirmed,flagged,resolved,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->success($this->remittances->paginate($this->ownerId($request), $data), 'Remittances retrieved successfully');
    }

    /** POST /operator/remittances/{id}/confirm  { counted_amount?, note? } */
    public function confirm(Request $request, int $id)
    {
        $data = $request->validate([
            'counted_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $row = $this->remittances->confirm($id, $this->ownerId($request), $request->user(), isset($data['counted_amount']) ? (float) $data['counted_amount'] : null, $data['note'] ?? null);
        } catch (ModelNotFoundException) {
            return $this->error('Remittance not found.', 404);
        }

        return $this->success($row, 'Remittance confirmed');
    }

    /** POST /operator/remittances/{id}/resolve  { resolution_note } (a flagged batch only; the explanation is required) */
    public function resolve(Request $request, int $id)
    {
        $data = $request->validate([
            'resolution_note' => ['required', 'string', 'max:255'],
        ]);

        try {
            $row = $this->remittances->resolve($id, $this->ownerId($request), $request->user(), $data['resolution_note']);
        } catch (ModelNotFoundException) {
            return $this->error('Remittance not found.', 404);
        }

        return $this->success($row, 'Remittance resolved');
    }

    private function ownerId(Request $request): int
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            abort(404, 'Staff profile not found.');
        }

        return (int) $companyUser->company_user_id;
    }
}
