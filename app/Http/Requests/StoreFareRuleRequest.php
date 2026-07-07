<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreFareRuleRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'fleet_id'    => 'required|integer|exists:fleets,fleet_id',
            'base_fare'   => 'required|numeric|min:0',
            'fare_per_km' => 'required|numeric|min:0',
            'seat_type'   => 'required|string|in:seated,standing',
        ];
    }
}
