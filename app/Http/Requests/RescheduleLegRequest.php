<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 2026-10-27 (§4.2): PATCH operator/shift-blocks/{shiftBlockId}/legs/{tripId}, the leg's new departure time. */
class RescheduleLegRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'departure_time' => ['required', 'date_format:H:i'],
        ];
    }
}
