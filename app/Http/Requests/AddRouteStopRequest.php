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
            'distance_from_origin_km' => 'nullable|numeric|min:0',
            'stop_type' => 'sometimes|in:terminal,stop,pass_through',
            'scheduled_offset_min' => 'nullable|integer|min:0|max:65535',
        ];
    }
}
