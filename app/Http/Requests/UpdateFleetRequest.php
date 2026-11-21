<?php

namespace App\Http\Requests;

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
            'body_no' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:'.StoreFleetRequest::LABEL_PATTERN],
            'seated_capacity' => 'sometimes|integer|min:0',
            'standing_capacity' => 'sometimes|integer|min:0',
            'fleet_type' => 'sometimes|in:public,private',
            // 2026-11-21 (user): the bus's operating hours (both or neither).
            'operating_start_time' => 'sometimes|nullable|date_format:H:i|required_with:operating_end_time',
            'operating_end_time' => 'sometimes|nullable|date_format:H:i|required_with:operating_start_time',
            'vehicle_class' => ['sometimes', 'nullable', 'string', 'max:32', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && VehicleClass::normalize($value) === null) {
                    $fail('Choose a vehicle class from the list.');
                }
            }],
        ];
    }
}
