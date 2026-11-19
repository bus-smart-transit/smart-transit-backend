<?php

namespace App\Http\Requests;

use App\Services\RouteDurationFactorService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `RouteDurationFactorController::store` and `update` (a peak-hour duration
 * window), moved out of the controller unchanged.
 */
class RouteDurationFactorRequest extends FormRequest
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
            'route_id' => 'required|integer|exists:routes,route_id',
            'direction' => 'nullable|string|in:outbound,reverse',
            'day_type' => 'nullable|string|in:'.implode(',', RouteDurationFactorService::DAY_TYPES),
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'factor' => 'required|numeric',
            'source' => 'nullable|string|in:manual,measured',
            'note' => 'nullable|string|max:255',
        ];
    }
}
