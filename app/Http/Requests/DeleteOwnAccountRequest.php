<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-11-05: a passenger deletes their own account (AccountDeletionService). No id is accepted: the account is
 * always the signed-in one. The password is asked again, and `confirm` must be ticked on the page.
 */
class DeleteOwnAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'passenger';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => 'Enter your password to delete your account.',
            'confirm.accepted' => 'Tick the box to confirm you want to delete your account.',
        ];
    }
}
