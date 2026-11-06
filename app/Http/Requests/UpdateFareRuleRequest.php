<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-11-06: edit a fare rule. FareRuleService::updateRule decides which fields may change (a rule in use keeps its
 * prices; it can be ended, stopped or given a note).
 */
class UpdateFareRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_fare' => 'sometimes|required|numeric|min:0',
            'fare_per_km' => 'sometimes|required|numeric|min:0',
            'base_distance_km' => 'sometimes|nullable|numeric|min:0|max:1000',
            'rounding_mode' => 'sometimes|nullable|string|in:'.implode(',', (array) config('fares.rounding_modes')),
            'effective_from' => 'sometimes|nullable|date_format:Y-m-d',
            'effective_to' => 'sometimes|nullable|date_format:Y-m-d',
            'status' => 'sometimes|required|in:active,inactive',
            'note' => 'sometimes|nullable|string|max:255',
        ];
    }
}
