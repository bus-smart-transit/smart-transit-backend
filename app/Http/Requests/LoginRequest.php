<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email', ''))),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 2026-11-05: on the staff page a Driver or Chauffeur may type their username (UserService decides which).
            'email' => $this->input('portal') === 'staff'
                ? ['required', 'string', 'max:255', ...(str_contains((string) $this->input('email'), '@') ? ['email'] : [])]
                : ['required', 'email'],
            'password' => ['required', 'string'],
            // 2026-10-30: the page the person signs in on; POST /auth/login serves both (SignInController).
            'portal' => ['required', 'in:passenger,staff'],
        ];
    }
}
