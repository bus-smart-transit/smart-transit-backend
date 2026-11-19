<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `ShiftBlockController::update`, moved out of the controller unchanged.
 */
class ShiftBlockUpdateRequest extends FormRequest
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
            'driver_id' => ['nullable', 'integer'],
            'conductor_id' => ['nullable', 'integer'],
        ];
    }
}
