<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `TripController::changeFleet`, moved out of the controller unchanged.
 */
class TripChangeFleetRequest extends FormRequest
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
        // 2026-11-21 (user): the new bus (it runs the trip's own route), or an existing bus-and-route row.
        return [
            'fleet_route_id' => 'required_without:fleet_id|nullable|integer|exists:fleets_routes,fleet_route_id',
            'fleet_id' => 'required_without:fleet_route_id|nullable|integer|exists:fleets,fleet_id',
        ];
    }
}
