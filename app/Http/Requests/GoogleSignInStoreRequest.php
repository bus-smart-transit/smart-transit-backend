<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `GoogleSignInController::store`, moved out of the controller unchanged.
 */
class GoogleSignInStoreRequest extends FormRequest
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
        return ['id_token' => ['required', 'string', 'max:8192']];
    }
}
