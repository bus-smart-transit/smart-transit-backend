<?php

namespace App\Http\Controllers;

use App\Services\RefundListService;
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
            'status' => ['nullable', 'in:needs_refund,refunded,outcome_unknown,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->success($this->payments->paginate($this->ownerId($request), $data), 'Payments retrieved successfully');
    }

    /**
     * 2026-11-08 (user): the Refunds tab's one table: payment refunds and cancelled-trip ticket refunds together, with
     * one status filter and one set of pages (RefundListService). Read only; own company only.
     */
    public function refunds(Request $request, RefundListService $refunds)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', RefundListService::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->success($refunds->paginate($this->ownerId($request), $data['status'] ?? 'all', (int) ($data['page'] ?? 1)), 'Refunds retrieved successfully');
    }

    /** Batch 48 Phase 5: GET /admin/payments/needs-refund, the same list across every company, read only. */
    public function adminIndex(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:needs_refund,refunded,outcome_unknown,all'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->success($this->payments->paginate(null, $data), 'Payments retrieved successfully');
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

    /** POST /operator/payments/{id}/checkout-reconciled-not-paid { note } */
    public function reconcileCheckoutNotPaid(Request $request, int $id)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:12', 'max:255'],
        ]);

        try {
            $payment = $this->payments->recordCheckoutNotCharged(
                $id,
                $this->ownerId($request),
                $request->user(),
                $data['note'],
            );
        } catch (ModelNotFoundException) {
            return $this->error('Payment not found.', 404);
        }

        return $this->success(['payment_id' => $payment->payment_id, 'status' => $payment->status], 'Checkout reconciled as not charged');
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
