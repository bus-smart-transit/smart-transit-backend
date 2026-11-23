<?php

namespace App\Http\Requests;

use App\Support\TripCapacity;
use App\Support\VehicleClass;
use Illuminate\Foundation\Http\FormRequest;

class StoreFleetRequest extends FormRequest
{
    /** Letters, digits, spaces and . - & ' # / only: the label is shown to passengers. */
    public const LABEL_PATTERN = "/^[\\pL\\pN\\s.\\-&'#\\/]+$/u";

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plate_number' => 'required|string|unique:fleets,plate_number',
            'bus_company_name' => ['nullable', 'string', 'max:100', 'regex:'.self::LABEL_PATTERN],
            // 2026-11-23 (advisor): body number, class, seats (at least booking.min_bus_seats) and operating hours are
            // required. Standing places are not allowed (TripCapacity::standingAllowed), so none is asked for.
            'body_no' => ['required', 'string', 'max:30', 'regex:'.self::LABEL_PATTERN],
            'seated_capacity' => ['required', 'integer', 'min:'.self::minSeats(), 'max:100'],
            'standing_capacity' => TripCapacity::standingAllowed() ? 'nullable|integer|min:0' : 'nullable|integer|in:0',
            'fleet_type' => 'required|in:public,private',
            'vehicle_class' => ['required', 'string', 'max:32', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && VehicleClass::normalize($value) === null) {
                    $fail('Choose a vehicle class from the list.');
                }
            }],
            // 2026-11-21 (user): the bus's operating hours; the route is chosen when a trip is scheduled.
            'operating_start_time' => 'required|date_format:H:i',
            'operating_end_time' => 'required|date_format:H:i',
            'routes' => 'sometimes|array|max:20',
            'routes.*.route_id' => 'required|integer|exists:routes,route_id|distinct',
            'routes.*.start_time' => 'required|date_format:H:i',
            'routes.*.end_time' => 'required|date_format:H:i',
        ];
    }

    public static function minSeats(): int
    {
        return max(1, (int) config('booking.min_bus_seats', 20));
    }

    /** @return array<string, string> */
    public static function fleetMessages(): array
    {
        return [
            'body_no.required' => 'Enter the bus body number.',
            'vehicle_class.required' => 'Choose a vehicle class.',
            'seated_capacity.required' => 'Enter how many seats the bus has.',
            'seated_capacity.min' => 'A bus needs at least '.self::minSeats().' seats.',
            'seated_capacity.max' => 'A bus can have at most 100 seats.',
            'standing_capacity.in' => 'Standing passengers are not allowed.',
            'operating_start_time.required' => 'Enter the time the bus starts operating.',
            'operating_end_time.required' => 'Enter the time the bus stops operating.',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return self::fleetMessages();
    }
}
