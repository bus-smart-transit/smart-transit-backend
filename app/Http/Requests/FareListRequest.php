<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FareListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fleet_id' => ['required', 'integer', 'exists:fleets,fleet_id'],
            'seat_type' => ['required', 'string', 'in:seated,standing'],
        ];
    }
}