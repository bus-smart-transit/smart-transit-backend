<?php

namespace App\Http\Controllers;

use App\Services\UnfulfilledPaymentService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * Payments the system took money for but could not turn into tickets (`needs_refund`), and the Operator's record that
 * they refunded them. Operator only; an Operator sees only payments for trips of their own.
 */
class UnfulfilledPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private UnfulfilledPaymentService $payments) {}

    /** GET /operator/payments/needs-refund?status=needs_refund|refunded|all&page= */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:needs_refund,refunded,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->success($this->payments->paginate($this->ownerId($request), $data), 'Payments retrieved successfully');
    }

    /** POST /operator/payments/{id}/refunded  { note } (the PayMongo refund id or reference; required) */
    public function recordRefund(Request $request, int $id)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        try {
            $row = $this->payments->recordRefund($id, $this->ownerId($request), $request->user(), $data['note']);
        } catch (ModelNotFoundException) {
            return $this->error('Payment not found.', 404);
        }

        return $this->success($row, 'Refund recorded');
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
