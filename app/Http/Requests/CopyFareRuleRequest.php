<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-11-06: copy a fare rule to start on a later date, optionally with new prices. FareRuleService::copyRule ends
 * the original the day before.
 */
class CopyFareRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'base_fare' => 'sometimes|required|numeric|min:0',
            'fare_per_km' => 'sometimes|required|numeric|min:0',
            'base_distance_km' => 'sometimes|nullable|numeric|min:0|max:1000',
            'rounding_mode' => 'sometimes|nullable|string|in:'.implode(',', (array) config('fares.rounding_modes')),
            'note' => 'sometimes|nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return ['effective_to.after_or_equal' => 'The end date must be on or after the start date.'];
    }
}
