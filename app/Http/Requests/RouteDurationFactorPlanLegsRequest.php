<?php

namespace App\Http\Requests;

use App\Services\LegPlanningService;
use App\Support\ServiceType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `RouteDurationFactorController::planLegs`, moved out of the controller unchanged.
 */
class RouteDurationFactorPlanLegsRequest extends FormRequest
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
            'fleet_route_id' => 'required|integer|exists:fleets_routes,fleet_route_id',
            'scheduled_date' => 'required|date_format:Y-m-d',
            'first_departure_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'service_type' => 'nullable|string|in:'.implode(',', ServiceType::ALL),
            // 2026-10-27 (§4.2): without leg_count the planner decides how many legs fit from the route's travel time.
            'leg_count' => 'nullable|integer|min:1|max:'.LegPlanningService::maxLegs(),
        ];
    }
}
