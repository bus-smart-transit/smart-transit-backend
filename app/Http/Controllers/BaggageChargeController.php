<?php

namespace App\Http\Controllers;

use App\Http\Requests\BaggageChargeRequest;
use App\Http\Requests\BaggageFeesUpdateRequest;
use App\Services\BaggageChargeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 2026-11-25 (user): baggage paid in cash on board (the Chauffeur) and its prices by size (the Operator). */
class BaggageChargeController extends Controller
{
    use ApiResponse;

    public function __construct(private BaggageChargeService $baggage) {}

    /** GET conductor/baggage-fees: the price list for the Chauffeur's current bus. */
    public function conductorFees(Request $request): JsonResponse
    {
        $staff = $request->user()?->companyProfile;
        if (! $staff) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->baggage->feesForConductor((int) $staff->company_user_id), 'Baggage prices retrieved');
    }

    /** POST conductor/tickets/{ticketId}/baggage: baggage paid for a passenger on the current trip. */
    public function store(BaggageChargeRequest $request, int $ticketId): JsonResponse
    {
        $staff = $request->user()?->companyProfile;
        if (! $staff) {
            return $this->error('Staff profile not found.', 404);
        }
        $data = $request->validated();
        $charge = $this->baggage->recordForTicket((int) $staff->company_user_id, $ticketId, $data['size'], $data['stub_number'] ?? null);

        return $this->success($this->baggage->present($charge), 'Baggage payment recorded', 201);
    }

    /** PATCH conductor/baggage/{chargeId}/void: the Chauffeur takes back a payment from their open shift. */
    public function void(Request $request, int $chargeId): JsonResponse
    {
        $staff = $request->user()?->companyProfile;
        if (! $staff) {
            return $this->error('Staff profile not found.', 404);
        }
        $charge = $this->baggage->void((int) $staff->company_user_id, $chargeId);

        return $this->success($this->baggage->present($charge), 'Baggage payment removed');
    }

    /** GET operator/baggage-fees */
    public function operatorFees(Request $request): JsonResponse
    {
        $staff = $request->user()?->companyProfile;
        if (! $staff) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->baggage->feesForOperator((int) $staff->company_user_id), 'Baggage prices retrieved');
    }

    /** PUT operator/baggage-fees */
    public function updateFees(BaggageFeesUpdateRequest $request): JsonResponse
    {
        $staff = $request->user()?->companyProfile;
        if (! $staff) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->baggage->updateFees((int) $staff->company_user_id, $request->validated()), 'Baggage prices saved');
    }
}
