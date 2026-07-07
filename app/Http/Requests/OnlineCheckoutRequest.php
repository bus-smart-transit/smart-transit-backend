<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class OnlineCheckoutRequest extends FormRequest
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
            'payment_channel'             => 'required|string|in:gcash,maya,card',
        ];
    }
}
