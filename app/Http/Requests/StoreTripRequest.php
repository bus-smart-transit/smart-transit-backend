<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 2026-11-21 (user): a bus and a route (the route is chosen when scheduling), or an existing bus-and-route row.
            'fleet_route_id' => 'required_without_all:fleet_id,route_id|nullable|integer|exists:fleets_routes,fleet_route_id',
            'fleet_id' => 'required_without:fleet_route_id|nullable|integer|exists:fleets,fleet_id',
            'route_id' => 'required_without:fleet_route_id|nullable|integer|exists:routes,route_id',
            'trip_date' => 'required|date|after_or_equal:today',
            'departure_time' => ['required', 'date_format:H:i'],
            'trip_type' => ['required', 'in:one_way,round_trip'],
            'return_departure_time' => ['nullable', 'date_format:H:i', 'required_if:trip_type,round_trip'],
            'driver_id' => 'nullable|integer|exists:company_users,company_user_id', // Batch 46: a trip may wait for its crew (TripCrew)
            'conductor_id' => 'nullable|integer|exists:company_users,company_user_id',
            'service_type' => ['sometimes', 'in:regular,express'],
        ];
    }
}
