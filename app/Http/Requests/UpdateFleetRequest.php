<?php

namespace App\Http\Requests;

use App\Support\TripCapacity;
use App\Support\VehicleClass;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFleetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $fleetId = (int) $this->route('fleetId', $this->route('fleet'));

        return [
            'plate_number' => ['sometimes', 'string', Rule::unique('fleets', 'plate_number')->ignore($fleetId, 'fleet_id')],
            'bus_company_name' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:'.StoreFleetRequest::LABEL_PATTERN],
            'body_no' => ['sometimes', 'required', 'string', 'max:30', 'regex:'.StoreFleetRequest::LABEL_PATTERN],
            'seated_capacity' => ['sometimes', 'required', 'integer', 'min:'.StoreFleetRequest::minSeats(), 'max:100'],
            'standing_capacity' => TripCapacity::standingAllowed() ? 'sometimes|integer|min:0' : 'sometimes|nullable|integer|in:0',
            'fleet_type' => 'sometimes|in:public,private',
            // 2026-11-21 (user): the bus's operating hours (both or neither).
            'operating_start_time' => 'sometimes|required|date_format:H:i',
            'operating_end_time' => 'sometimes|required|date_format:H:i',
            'vehicle_class' => ['sometimes', 'required', 'string', 'max:32', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && VehicleClass::normalize($value) === null) {
                    $fail('Choose a vehicle class from the list.');
                }
            }],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return StoreFleetRequest::fleetMessages();
    }
}
