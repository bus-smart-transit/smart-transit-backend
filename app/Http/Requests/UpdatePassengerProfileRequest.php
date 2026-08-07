<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdatePassengerProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name'      => 'sometimes|string|max:255',
            'phone_num' => 'sometimes|string',
            'address'   => 'sometimes|string',
            'birthdate' => 'sometimes|date',
        ];
    }
}
