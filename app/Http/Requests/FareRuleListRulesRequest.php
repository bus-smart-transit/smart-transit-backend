<?php

namespace App\Http\Requests;

use App\Support\ServiceType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `FareRuleController::listRules`, moved out of the controller unchanged.
 */
class FareRuleListRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vehicle_class' => 'nullable|string|max:32',
            'service_type' => 'nullable|string|in:'.implode(',', ServiceType::ALL),
            'seat_type' => 'nullable|string|in:seated,standing',
            'route_id' => 'nullable|integer',
            'fleet_id' => 'nullable|integer',
            'scope' => 'nullable|string|in:general,fleet',
            'status' => 'nullable|string|in:active,inactive',
        ];
    }
}
