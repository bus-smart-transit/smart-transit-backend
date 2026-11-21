<?php

namespace App\Http\Requests;

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
            'body_no' => ['nullable', 'string', 'max:30', 'regex:'.self::LABEL_PATTERN],
            'seated_capacity' => 'required|integer|min:0',
            'standing_capacity' => 'required|integer|min:0',
            'fleet_type' => 'required|in:public,private',
            'vehicle_class' => ['nullable', 'string', 'max:32', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && VehicleClass::normalize($value) === null) {
                    $fail('Choose a vehicle class from the list.');
                }
            }],
            // 2026-11-21 (user): the bus's operating hours; the route is chosen when a trip is scheduled.
            'operating_start_time' => 'nullable|date_format:H:i|required_with:operating_end_time',
            'operating_end_time' => 'nullable|date_format:H:i|required_with:operating_start_time',
            'routes' => 'sometimes|array|max:20',
            'routes.*.route_id' => 'required|integer|exists:routes,route_id|distinct',
            'routes.*.start_time' => 'required|date_format:H:i',
            'routes.*.end_time' => 'required|date_format:H:i',
        ];
    }
}
