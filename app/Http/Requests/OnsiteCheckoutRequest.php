<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'items.*.seat_type' => 'required|string|in:seated,standing',
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
            'items.*.origin_stop_id' => 'required_without_all:items.*.boarding_mode,items.*.pending_boarding_id|nullable|integer|exists:stops,stop_id',
            // Task 7: the destination is a stop or a custom drop-off pin (coordinates re-validated and snapped server-side).
            'items.*.destination_stop_id' => 'required_without_all:items.*.destination_lat,items.*.alighting_point_id|nullable|integer|exists:stops,stop_id',
            'items.*.alighting_point_id' => 'nullable|integer|exists:alighting_points,id',
            'items.*.destination_lat' => 'nullable|required_with:items.*.destination_lng|numeric|between:-90,90',
            'items.*.destination_lng' => 'nullable|required_with:items.*.destination_lat|numeric|between:-180,180',
            'items.*.destination_label' => 'nullable|string|max:255',
            'items.*.passenger_id' => 'nullable|integer|exists:passenger_users,passenger_id',
            'idempotency_key' => 'nullable|string|min:16|max:128',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }
}
