<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreFareRuleRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            // Batch 28 item 5: with a fleet the rule is an override for that fleet; without one it is a general rule
            // found by (route, class, service type, seat type), and then vehicle_class is required (checked in the service).
            'fleet_id'    => 'nullable|integer|exists:fleets,fleet_id',
            'vehicle_class' => ['nullable', 'string', 'max:32', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && \App\Support\VehicleClass::normalize($value) === null) {
                    $fail('Choose a vehicle class from the list.');
                }
            }],
            'service_type' => 'nullable|string|in:'.implode(',', \App\Support\ServiceType::ALL),
            'route_id' => 'nullable|integer|exists:routes,route_id',
            'rounding_mode' => 'nullable|string|in:'.implode(',', (array) config('fares.rounding_modes')),
            'note' => 'nullable|string|max:255',
            'base_fare'   => 'required|numeric|min:0',
            'fare_per_km' => 'required|numeric|min:0',
            'seat_type'   => 'required|string|in:seated,standing',
            // Batch 27 Part 2: null base distance keeps the plain per-km formula; dates are Manila calendar days.
            'base_distance_km' => 'nullable|numeric|min:0|max:1000',
            'effective_from'   => 'nullable|date_format:Y-m-d',
            'effective_to'     => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
        ];
    }
}
