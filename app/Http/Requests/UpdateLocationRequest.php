<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'latitude'  => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'trip_id'   => 'nullable|integer|exists:trips,trip_id',
            'heading'   => 'nullable|numeric|between:0,360',
            'speed_kmh' => 'nullable|numeric|min:0',
        ];
    }
}
