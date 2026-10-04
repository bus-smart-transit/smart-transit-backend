<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRouteStopExpressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'served_by_express' => 'sometimes|required|boolean',
            'express_offset_min' => 'sometimes|nullable|integer|min:0|max:1440',
        ];
    }
}
