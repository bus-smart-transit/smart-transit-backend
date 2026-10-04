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
            // One-way: one booking_date for every item. Roundtrip (Batch 28, item 4): each item carries its own
            // booking_date (outbound and return are different days) and a journey_leg; both dates are future-only and
            // inside the booking window.
            'booking_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.now(config('app.timezone'))->addDays((int) config('booking.max_advance_days'))->toDateString()],
            'journey_type' => 'nullable|string|in:one_way,roundtrip',
            'items' => 'required|array|min:1',
            'items.*.booking_date' => ['nullable', 'date', 'after_or_equal:today', 'before_or_equal:'.now(config('app.timezone'))->addDays((int) config('booking.max_advance_days'))->toDateString()],
            'items.*.journey_leg' => 'nullable|string|in:outbound,return',
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

    /**
     * Roundtrip: one booking, two kinds of item. Every item names its leg; the outbound and return counts match
     * (one return ticket per outbound ticket); the return trip departs after the outbound trip does. A one-way
     * booking carries no leg at all. Stops, fares and capacity of each leg are checked by the pricing path.
     */
    private function validateRoundtrip(Validator $validator, array $items): void
    {
        $roundtrip = $this->input('journey_type') === 'roundtrip';
        $legs = collect($items)->map(fn ($item) => $item['journey_leg'] ?? null);

        if (! $roundtrip) {
            if ($legs->filter()->isNotEmpty()) {
                $validator->errors()->add('journey_type', 'A journey leg is only for a roundtrip booking.');
            }

            return;
        }

        if ($legs->contains(null)) {
            $validator->errors()->add('items', 'Every roundtrip item must say whether it is the outbound or the return leg.');

            return;
        }

        $outbound = collect($items)->where('journey_leg', 'outbound')->values();
        $return = collect($items)->where('journey_leg', 'return')->values();
        if ($outbound->isEmpty() || $return->isEmpty() || $outbound->count() !== $return->count()) {
            $validator->errors()->add('items', 'A roundtrip needs the same number of outbound and return tickets.');

            return;
        }

        $departure = function (array $item): ?Carbon {
            $trip = Trip::find((int) ($item['trip_id'] ?? 0));
            if (! $trip || ! $trip->trip_date) {
                return null;
            }

            return Carbon::parse($trip->trip_date->toDateString().' '.($trip->departure_time ?: '00:00'), config('app.timezone'));
        };
        $out = $departure($outbound->first());
        $back = $departure($return->first());
        if ($out && $back && $back->lte($out)) {
            $validator->errors()->add('items', 'The return trip must depart after the outbound trip.');
        }
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
            $this->validateRoundtrip($validator, $items);
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
