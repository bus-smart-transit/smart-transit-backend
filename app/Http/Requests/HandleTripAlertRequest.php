<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 58: an Operator marks a crew alert handled, with an optional note for the crew. */
class HandleTripAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['note' => 'nullable|string|max:255'];
    }
}
