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
        ];
    }
}
