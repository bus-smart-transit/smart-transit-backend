<?php

namespace App\Http\Requests;

use App\Models\Trip;
use App\Support\BookingWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

class OnlineCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // One booking_date for the purchase (an item may repeat it). Future-only and inside the booking window.
            'booking_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.(BookingWindow::lastDateString() ?? '9999-12-31')],
            // Roundtrip booking is retired (2026-10-26): a return journey is a separate purchase.
            'journey_type' => 'nullable|string|in:one_way',
            'items' => 'required|array|min:1',
            'items.*.booking_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.(BookingWindow::lastDateString() ?? '9999-12-31')],
            'items.*.journey_leg' => 'prohibited',
            // 2026-10-27: discounts are given on the bus only; online fares earn reward points.
            'items.*.discount_type_key' => 'prohibited',
            'items.*.trip_id' => 'required|integer|exists:trips,trip_id',
            // Online tickets are for seats; standing places are sold only on the bus (TicketService::priceItem).
            'items.*.seat_type' => 'required|string|in:seated',

            // Boarding is always a known stop (B7). The drop-off is either a stop, or a
            // custom drop-off pin (validated and snapped server-side, D5).
            'items.*.origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'items.*.destination_stop_id' => 'required_without_all:items.*.destination_lat,items.*.destination_lng,items.*.alighting_point_id|nullable|integer|exists:stops,stop_id',
            'items.*.destination_lat' => 'required_without_all:items.*.destination_stop_id,items.*.alighting_point_id|nullable|numeric|between:-90,90',
            'items.*.destination_lng' => 'required_without_all:items.*.destination_stop_id,items.*.alighting_point_id|nullable|numeric|between:-180,180',
            // Batch 28 Stage 3, item 10: a designated drop-off point is named by id only (the server owns where it is).
            'items.*.alighting_point_id' => 'nullable|integer|exists:alighting_points,id',
            'items.*.destination_label' => ['nullable', 'string', 'max:255'],
            // C4: the passenger picks GCash or Maya; PayMongo's hosted checkout collects the rest.
            'payment_channel' => 'required|string|in:gcash,maya',
            'guest_email' => ['nullable', 'email'],
            'return_base_url' => ['nullable', 'url', 'max:255'],
            'reward_points_to_use' => ['nullable', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'min:16', 'max:128'],
            // §4.10: one group QR for the purchase in the email (default), or one QR per ticket.
            'email_group_qr' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * One purchase is one journey (smarttransit_prompt.md §4.10, 2026-10-26): every ticket is for the same trip, the
     * same boarding stop and the same destination (stop, designated drop-off point, or drop-off pin), so the purchase
     * has one tracking link and can have one group QR. Another journey, a return included, is a separate purchase.
     */
    private function validateOneJourney(Validator $validator, array $items): void
    {
        $journeyOf = fn ($item) => implode('|', [
            (int) ($item['trip_id'] ?? 0),
            (int) ($item['origin_stop_id'] ?? 0),
            (int) ($item['destination_stop_id'] ?? 0),
            (int) ($item['alighting_point_id'] ?? 0),
            isset($item['destination_lat']) ? round((float) $item['destination_lat'], 6) : '',
            isset($item['destination_lng']) ? round((float) $item['destination_lng'], 6) : '',
        ]);

        if (collect($items)->filter(fn ($item) => is_array($item))->map($journeyOf)->unique()->count() > 1) {
            $validator->errors()->add('items', 'All tickets in one purchase must be for the same trip, boarding stop and destination. Buy another journey separately.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.*.seat_type.in' => 'Online tickets are for seats only. Standing places are sold on the bus.',
            'journey_type.in' => 'Roundtrip booking is no longer offered. Book the return journey as a separate ticket.',
            'items.*.journey_leg.prohibited' => 'Roundtrip booking is no longer offered. Book the return journey as a separate ticket.',
            'items.*.discount_type_key.prohibited' => 'Discounts are given on the bus only. Online fares earn reward points instead.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'guest_checkout' => $this->user()?->passengerProfile === null,
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items', []);
            if (! is_array($items) || $items === []) {
                return;
            }

            $topDate = (string) $this->input('booking_date', '');
            foreach ($items as $index => $item) {
                if ($topDate === '' && empty($item['booking_date'])) {
                    $validator->errors()->add("items.{$index}.booking_date", 'Choose the travel date.');
                }
            }

            $errorsBefore = $validator->errors()->count();
            $this->validateOneJourney($validator, $items);
            if ($validator->errors()->count() > $errorsBefore) {
                return;
            }

            $tripIds = collect($items)
                ->pluck('trip_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($tripIds->isEmpty()) {
                return;
            }

            $tripDateById = Trip::query()
                ->whereIn('trip_id', $tripIds)
                ->get(['trip_id', 'trip_date'])
                ->mapWithKeys(function (Trip $trip) {
                    return [(int) $trip->trip_id => optional($trip->trip_date)->toDateString()];
                });

            foreach ($items as $index => $item) {
                $tripId = (int) ($item['trip_id'] ?? 0);
                $tripDate = $tripDateById->get($tripId);
                $bookingDate = (string) ($item['booking_date'] ?? $topDate);

                if (! $tripDate || $bookingDate === '' || $tripDate === $bookingDate) {
                    continue;
                }

                $displayDate = Carbon::parse($tripDate)->format('m/d/Y');
                $validator->errors()->add("items.{$index}.trip_id", "This trip is scheduled for {$displayDate}.");
            }
        });
    }
}
