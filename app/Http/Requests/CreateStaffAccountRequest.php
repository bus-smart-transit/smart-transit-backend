<?php

namespace App\Http\Requests;

use App\Support\Gender;
use Illuminate\Foundation\Http\FormRequest;

class CreateStaffAccountRequest extends FormRequest
{
    /**
     * 2026-10-28: the email is saved lowercased and trimmed. Sign-in lowercases what is typed (LoginRequest), so an
     * email saved with capitals (Juan@Gmail.com) could never sign in.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone_num' => 'required|string',
            'address' => 'required|string',
            'birth_date' => 'nullable|date',
            'gender' => 'nullable|string|in:'.implode(',', Gender::VALUES),
            'username' => 'nullable|string|unique:users,username',
            'role' => 'required|string|in:operator,driver,conductor',
        ];
    }
}
