<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `ConductorShiftController::end`, moved out of the controller unchanged.
 */
class ConductorShiftEndRequest extends FormRequest
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
        return ['closing_cash' => ['nullable', 'numeric', 'min:0', 'max:1000000']];
    }
}
