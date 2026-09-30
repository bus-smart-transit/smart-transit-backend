<?php

namespace App\Http\Requests;

use App\Models\Trip;
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
            'booking_date' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now(config('app.timezone'))->addDays((int) config('booking.max_advance_days'))->toDateString()],
            'items' => 'required|array|min:1',
            'items.*.trip_id' => 'required|integer|exists:trips,trip_id',
            'items.*.seat_type' => 'required|string|in:seated,standing',

            // Boarding is always a known stop (B7). The drop-off is either a stop, or a
            // custom drop-off pin (validated and snapped server-side, D5).
            'items.*.origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'items.*.destination_stop_id' => 'required_without_all:items.*.destination_lat,items.*.destination_lng|nullable|integer|exists:stops,stop_id',
            'items.*.destination_lat' => 'required_without:items.*.destination_stop_id|nullable|numeric|between:-90,90',
            'items.*.destination_lng' => 'required_without:items.*.destination_stop_id|nullable|numeric|between:-180,180',
            'items.*.destination_label' => ['nullable', 'string', 'max:255'],
            // C4: the passenger picks GCash or Maya; PayMongo's hosted checkout collects the rest.
            'payment_channel' => 'required|string|in:gcash,maya',
            'guest_email' => ['nullable', 'email'],
            'return_base_url' => ['nullable', 'url', 'max:255'],
            'reward_points_to_use' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'guest_checkout' => $this->user()?->passengerProfile === null,
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $bookingDate = (string) $this->input('booking_date', '');
            if ($bookingDate === '') {
                return;
            }

            $items = $this->input('items', []);
            if (! is_array($items) || $items === []) {
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

                if (! $tripDate || $tripDate === $bookingDate) {
                    continue;
                }

                $displayDate = Carbon::parse($tripDate)->format('m/d/Y');
                $validator->errors()->add("items.{$index}.trip_id", "This trip is scheduled for {$displayDate}.");
            }
        });
    }
}
