<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-11-21 (user, Dynamic Route Scheduling): GET operator/fleets/availability - which of the company's buses can take
 * a trip on this route at this date and time.
 */
class FleetAvailabilityRequest extends FormRequest
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
            'trip_date' => 'required|date_format:Y-m-d',
            'departure_time' => 'required|date_format:H:i',
            'service_type' => 'nullable|in:regular,express',
        ];
    }
}
