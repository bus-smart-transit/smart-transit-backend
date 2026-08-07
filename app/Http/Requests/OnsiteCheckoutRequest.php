<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class OnsiteCheckoutRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'items'                       => 'required|array|min:1',
            'items.*.trip_id'             => 'required|integer|exists:trips,trip_id',
            'items.*.seat_type'           => 'required|string|in:seated,standing',
            'items.*.origin_stop_id'      => 'required|integer|exists:stops,stop_id',
            'items.*.destination_stop_id' => 'required|integer|exists:stops,stop_id',
            'items.*.passenger_id'        => 'nullable|integer|exists:passenger_users,passenger_id',
        ];
    }
}
