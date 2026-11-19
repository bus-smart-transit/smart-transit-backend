<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `DropoffOptionsController::show`, moved out of the controller unchanged.
 */
class DropoffOptionsShowRequest extends FormRequest
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
            'boarding_stop_id' => 'nullable|integer|min:1',
            'boarding_along_m' => 'nullable|numeric|min:0',
        ];
    }
}
