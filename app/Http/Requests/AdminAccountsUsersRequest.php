<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `AdminAccountsController::users`, moved out of the controller unchanged.
 */
class AdminAccountsUsersRequest extends FormRequest
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
            'role' => ['sometimes', 'nullable', 'in:passenger,driver,conductor,operator,admin'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
