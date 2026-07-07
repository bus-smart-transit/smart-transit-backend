<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class AddRouteStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'stop_id' => 'required|integer|exists:stops,stop_id',
            'stop_order' => 'required|integer|min:1',
            'distance_from_origin_km' => 'required|numeric|min:0',
        ];
    }
}
