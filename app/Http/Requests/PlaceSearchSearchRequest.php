<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `PlaceSearchController::search`, moved out of the controller unchanged.
 */
class PlaceSearchSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:120'],
            'mode' => ['sometimes', 'in:suggest,search'],
        ];
    }
}
