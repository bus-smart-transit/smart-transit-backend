<?php

namespace App\Http\Requests;

use App\Rules\EvenLegCount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `ShiftBlockController::previewRange` and `storeRange` (the single-block
 * fields, with a From and To date in place of scheduled_date; the range cap is the service's), moved out of the
 * controller unchanged.
 */
class ShiftBlockRangeRequest extends FormRequest
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
            'driver_id' => ['required', 'integer'],
            'conductor_id' => ['required', 'integer'],
            'block_type' => ['required', 'string'],
            'from_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'service_type' => ['sometimes', 'in:regular,express'],
            'leg_departure_times' => ['required', 'array', new EvenLegCount], // 2026-11-21 (user): out and back
            'leg_departure_times.*' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_date.after_or_equal' => 'The From date cannot be in the past.',
            'to_date.after_or_equal' => 'The To date must be on or after the From date.',
        ];
    }
}
