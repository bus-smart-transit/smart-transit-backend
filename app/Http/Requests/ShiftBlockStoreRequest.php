<?php

namespace App\Http\Requests;

use App\Rules\EvenLegCount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `ShiftBlockController::store`, moved out of the controller unchanged.
 */
class ShiftBlockStoreRequest extends FormRequest
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
            // 2026-11-21 (user): a bus and a route (the route is chosen when scheduling), or an existing bus-and-route row.
            'fleet_route_id' => ['required_without_all:fleet_id,route_id', 'nullable', 'integer'],
            'fleet_id' => ['required_without:fleet_route_id', 'nullable', 'integer', 'exists:fleets,fleet_id'],
            'route_id' => ['required_without:fleet_route_id', 'nullable', 'integer', 'exists:routes,route_id'],
            // 2026-11-23 (advisor): the crew may be assigned later (the Operator schedules a month ahead).
            'driver_id' => ['nullable', 'integer'],
            'conductor_id' => ['nullable', 'integer'],
            'block_type' => ['required', 'string'],
            'scheduled_date' => ['required', 'date'],
            'service_type' => ['sometimes', 'in:regular,express'],
            'leg_departure_times' => ['required', 'array', new EvenLegCount], // 2026-11-21 (user): out and back
            'leg_departure_times.*' => ['required', 'date_format:H:i'],
        ];
    }
}
