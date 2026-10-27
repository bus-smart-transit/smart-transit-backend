<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetDiscountTypeActiveRequest;
use App\Services\OnsiteDiscountService;
use App\Services\TripService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * 2026-10-27: onsite discount types. The Operator sees every type and turns each on or off for their buses; the
 * Chauffeur gets the types that are on for the bus of their current trip.
 */
class DiscountTypeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OnsiteDiscountService $discounts,
        private TripService $tripService,
    ) {}

    public function operatorIndex(Request $request)
    {
        $operator = $request->user()?->companyProfile;
        if (! $operator) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->discounts->listFor((int) $operator->company_user_id), 'Discount types retrieved');
    }

    public function setActive(SetDiscountTypeActiveRequest $request, string $key)
    {
        $operator = $request->user()?->companyProfile;
        if (! $operator) {
            return $this->error('Staff profile not found.', 404);
        }

        try {
            $type = $this->discounts->setActive((int) $operator->company_user_id, $key, (bool) $request->validated('active'));
        } catch (ValidationException $e) {
            return $this->error(collect($e->errors())->flatten()->first() ?? 'This discount type does not exist.', 404, $e->errors());
        }

        return $this->success($type, 'Discount setting saved');
    }

    public function conductorIndex(Request $request)
    {
        $conductor = $request->user()?->companyProfile;
        if (! $conductor) {
            return $this->error('Staff profile not found.', 404);
        }

        // The bus's Operator decides; without a current trip the defaults are shown (every sale re-checks its own trip).
        $trip = $this->tripService->getCurrentTripForConductor((int) $conductor->company_user_id);
        $operatorId = $trip ? (int) $trip->company_user_id : null;

        return $this->success($this->discounts->activeFor($operatorId), 'Discount types retrieved');
    }
}
