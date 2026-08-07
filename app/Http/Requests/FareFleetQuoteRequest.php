<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FareFleetQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'seat_type' => ['required', 'string', 'in:seated,standing'],
        ];
    }
}