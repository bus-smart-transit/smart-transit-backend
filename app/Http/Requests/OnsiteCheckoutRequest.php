<?php

namespace App\Http\Requests;

use App\Support\BaggageFee;
use App\Support\PriorityBoarding;
use App\Support\TripCapacity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnsiteCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.trip_id' => 'required|integer|exists:trips,trip_id',
            'items.*.seat_type' => ['required', 'string', Rule::in(TripCapacity::seatTypes())],
            // Task 6: `gps` = boarding between stops. The boarding point is resolved on the server from the bus's
            // own position, so no origin and no coordinates for it are accepted here.
            'items.*.boarding_mode' => 'nullable|string|in:gps',
            // 2026-10-26: Passenger In. The check-in names the boarding stop (snapped back); never a client position.
            'items.*.pending_boarding_id' => 'nullable|integer|exists:pending_boardings,id',
            // The fare the Chauffeur was shown; a different server fare refuses the sale (TicketService::reserveAndPrice).
            'items.*.quoted_amount' => 'nullable|numeric|min:0',
            // 2026-10-27: one onsite discount per ticket, only after the Chauffeur checked the ID (OnsiteDiscountService).
            'items.*.discount_type_key' => 'nullable|string|max:32',
            'items.*.discount_id_checked' => 'nullable|boolean',
            // Batch 76: a priority seat per ticket. 2026-11-25 (user): baggage by size, paid with the sale as its own cash
            // payment (BaggageChargeService), with the paper baggage stub's number if one is given.
            'items.*.priority_type' => ['nullable', 'string', Rule::in(array_keys(PriorityBoarding::types()))],
            'items.*.baggage_size' => ['nullable', 'string', Rule::in(BaggageFee::sizeKeys())],
            'items.*.baggage_stub' => ['nullable', 'string', 'max:30'],
            'items.*.origin_stop_id' => 'required_without_all:items.*.boarding_mode,items.*.pending_boarding_id|nullable|integer|exists:stops,stop_id',
            // Task 7: the destination is a stop or a custom drop-off pin (coordinates re-validated and snapped server-side).
            'items.*.destination_stop_id' => 'required_without_all:items.*.destination_lat,items.*.alighting_point_id|nullable|integer|exists:stops,stop_id',
            'items.*.alighting_point_id' => 'nullable|integer|exists:alighting_points,id',
            'items.*.destination_lat' => 'nullable|required_with:items.*.destination_lng|numeric|between:-90,90',
            'items.*.destination_lng' => 'nullable|required_with:items.*.destination_lat|numeric|between:-180,180',
            'items.*.destination_label' => 'nullable|string|max:255',
            'items.*.passenger_id' => 'nullable|integer|exists:passenger_users,passenger_id',
            'idempotency_key' => 'nullable|string|min:16|max:128',
            // Batch 64: an optional mobile number for the Ticket ID text.
            'traveller_contact' => 'nullable|string|max:40',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['items.*.seat_type.in' => 'Standing passengers are not allowed. Sell a seat.'];
    }
}
