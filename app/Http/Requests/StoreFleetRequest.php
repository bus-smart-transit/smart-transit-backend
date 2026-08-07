<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class StoreFleetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'plate_number' => 'required|string|unique:fleets,plate_number',
            'seated_capacity' => 'required|integer|min:0',
            'standing_capacity' => 'required|integer|min:0',
            'fleet_type' => 'required|in:public,private'
        ];
    }
}
