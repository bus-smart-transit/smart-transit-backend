<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `StaffAuthController::changePassword`, moved out of the controller unchanged.
 */
class StaffAuthChangePasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'regex:/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.regex' => 'Use at least one uppercase letter, one lowercase letter and one number.',
            'password.confirmed' => 'The two new passwords do not match.',
        ];
    }
}
