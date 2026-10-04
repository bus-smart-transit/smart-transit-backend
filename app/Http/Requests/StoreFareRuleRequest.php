<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreFareRuleRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'fleet_id'    => 'required|integer|exists:fleets,fleet_id',
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
