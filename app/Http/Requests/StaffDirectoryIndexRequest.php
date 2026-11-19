<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `StaffDirectoryController::index`, moved out of the controller unchanged.
 */
class StaffDirectoryIndexRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'in:driver,conductor,operator'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
