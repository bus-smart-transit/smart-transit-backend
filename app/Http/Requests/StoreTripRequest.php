<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreTripRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'fleet_route_id' => 'required|integer|exists:fleets_routes,fleet_route_id',
            'trip_date'      => 'required|date|after_or_equal:today',
            'departure_time' => 'nullable|date_format:H:i',
            'driver_id'      => 'required|integer|exists:company_users,company_user_id',
            'conductor_id'   => 'required|integer|exists:company_users,company_user_id',
        ];
    }
}
