<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateStopRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'stop_name' => 'sometimes|string|max:255',
            'location'  => 'sometimes|nullable|string|max:255',
            'latitude'  => 'sometimes|nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180|required_with:latitude',
        ];
    }
}
