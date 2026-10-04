<?php
namespace App\Http\Requests;
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
        ];
    }
}
