<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class FareQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
    public function rules(): array
    {
        return [
            'origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|exists:stops,stop_id',
            'seat_type' => 'required|string|in:seated,standing',
        ];
    }
}
