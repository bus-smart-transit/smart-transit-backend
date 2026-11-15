<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 75: the Operator's fare table for one bus on one route (what passengers are charged from a stop). */
class RouteFareTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fleet_route_id' => 'required|integer|min:1',
            'direction' => 'nullable|in:outbound,reverse',
            'service_type' => 'nullable|in:regular,express',
            'origin_stop_id' => 'nullable|integer|min:1',
        ];
    }
}
