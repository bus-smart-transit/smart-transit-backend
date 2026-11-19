<?php

namespace App\Http\Controllers;

use App\Http\Requests\RemittanceConfirmRequest;
use App\Http\Requests\RemittanceIndexRequest;
use App\Http\Requests\RemittanceResolveRequest;
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
    public function index(RemittanceIndexRequest $request)
    {
        $data = $request->validated();

        return $this->success($this->remittances->paginate($this->ownerId($request), $data), 'Remittances retrieved successfully');
    }

    /** POST /operator/remittances/{id}/confirm  { counted_amount?, note? } */
    public function confirm(RemittanceConfirmRequest $request, int $id)
    {
        $data = $request->validated();

        try {
            $row = $this->remittances->confirm($id, $this->ownerId($request), $request->user(), isset($data['counted_amount']) ? (float) $data['counted_amount'] : null, $data['note'] ?? null);
        } catch (ModelNotFoundException) {
            return $this->error('Remittance not found.', 404);
        }

        return $this->success($row, 'Remittance confirmed');
    }

    /** POST /operator/remittances/{id}/resolve  { resolution_note } (a flagged batch only; the explanation is required) */
    public function resolve(RemittanceResolveRequest $request, int $id)
    {
        $data = $request->validated();

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
