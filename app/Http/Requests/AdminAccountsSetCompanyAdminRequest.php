<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `AdminAccountsController::setCompanyAdmin`, moved out of the controller unchanged.
 */
class AdminAccountsSetCompanyAdminRequest extends FormRequest
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
        return ['is_company_admin' => ['required', 'boolean']];
    }
}
