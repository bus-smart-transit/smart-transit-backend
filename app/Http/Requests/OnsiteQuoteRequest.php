<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// The same fields one on-site sale item carries, flat, so a quote and the sale read the same way.
class OnsiteQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => 'required|integer|exists:trips,trip_id',
            'seat_type' => 'required|string|in:seated,standing',
            // `gps` = boarding between stops: the server resolves the boarding point from the bus's own position.
            'boarding_mode' => 'nullable|string|in:gps',
            'origin_stop_id' => 'required_unless:boarding_mode,gps|nullable|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|exists:stops,stop_id',
        ];
    }
}
