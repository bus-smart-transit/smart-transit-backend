<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateRouteCustomDropoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            // Either the older on/off switch or (Batch 28 Stage 3, item 10) the mode: off, designated_points or free_pin.
            'allow_custom_dropoff' => 'required_without:custom_dropoff_mode|boolean',
            'custom_dropoff_mode' => 'nullable|string|in:off,designated_points,free_pin',
        ];
    }
}