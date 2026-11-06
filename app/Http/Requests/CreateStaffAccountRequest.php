<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
use App\Support\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStaffAccountRequest extends FormRequest
{
    /**
     * 2026-10-28: the email is saved lowercased and trimmed. Sign-in lowercases what is typed (LoginRequest), so an
     * email saved with capitals (Juan@Gmail.com) could never sign in.
     * Batch 46: the phone is stored in one E.164 form (App\Support\PhoneNumber); a number that is not valid is kept as
     * typed so the rule below can refuse it.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
        if ($this->has('phone_num')) {
            $this->merge(['phone_num' => ValidPhone::prepare($this->input('phone_num'))]);
        }
        if ($this->has('username')) {
            $this->merge(['username' => trim((string) $this->input('username'))]);
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
            // Batch 46: a taken email or username gets the same plain answer (messages below), not "this account exists".
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'phone_num' => ['required', 'string', new ValidPhone],
            'address' => 'required|string|max:255',
            // Batch 46 (decided 2026-11-05): address and birth date are required for Drivers and Chauffeurs.
            'birth_date' => 'required_if:role,driver,conductor|nullable|date|before:today',
            'gender' => 'nullable|string|in:'.implode(',', Gender::VALUES),
            'username' => 'nullable|string|min:3|max:50|regex:/^[A-Za-z0-9._-]+$/|unique:users,username',
            'role' => 'required|string|in:operator,driver,conductor',
            // Batch 46 (decided 2026-11-06): the Admin names the company of a new Operator, Driver or Chauffeur (found
            // by name, or created). An Operator never sends it: their crew joins their own company.
            'company_name' => [
                Rule::requiredIf(fn () => $this->user()?->role === 'admin'),
                Rule::prohibitedIf(fn () => $this->user()?->role !== 'admin'),
                'nullable', 'string', 'min:2', 'max:120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'This email cannot be used. Try another one.',
            'username.unique' => 'This username cannot be used. Try another one.',
            'username.regex' => 'Use only letters, numbers, dots, dashes and underscores.',
            'birth_date.required_if' => 'Birth date is required.',
            'birth_date.before' => 'Birth date must be in the past.',
            'company_name.required' => 'Enter the company this person works for.',
            'company_name.prohibited' => 'Your staff join your own company.',
        ];
    }
}
