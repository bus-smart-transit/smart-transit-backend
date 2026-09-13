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
            'booking_date' => 'required|date|after_or_equal:today',
            'items' => 'required|array|min:1',
            'items.*.trip_id' => 'required|integer|exists:trips,trip_id',
            'items.*.seat_type' => 'required|string|in:seated,standing',

            // Stop-based item: both stop IDs required together, only when
            // coordinates aren't supplied for that same item.
            'items.*.origin_stop_id' => 'required_without_all:items.*.origin_lat,items.*.origin_lng|nullable|integer|exists:stops,stop_id',
            'items.*.destination_stop_id' => 'required_without_all:items.*.destination_lat,items.*.destination_lng|nullable|integer|exists:stops,stop_id',

            // Custom-point item: all four coordinates required together,
            // only when stop IDs aren't supplied for that same item.
            'items.*.origin_lat' => 'required_without:items.*.origin_stop_id|nullable|numeric|between:-90,90',
            'items.*.origin_lng' => 'required_without:items.*.origin_stop_id|nullable|numeric|between:-180,180',
            'items.*.destination_lat' => 'required_without:items.*.destination_stop_id|nullable|numeric|between:-90,90',
            'items.*.destination_lng' => 'required_without:items.*.destination_stop_id|nullable|numeric|between:-180,180',
            'items.*.origin_label' => ['nullable', 'string', 'max:255'],
            'items.*.destination_label' => ['nullable', 'string', 'max:255'],

            'payment_channel' => 'required|string|in:gcash,maya,card',
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
