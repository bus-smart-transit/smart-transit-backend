<?php

namespace App\Http\Requests;

use App\Support\Gender;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-10-29 (Batch 36 Phase 2): what a passenger may change on their own profile: name, phone and gender. Anything
 * else in the request (address, birth date, email, ids, role, status, points) is not read. The email changes through
 * its own confirmation flow, not here.
 */
class UpdatePassengerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['name', 'phone_num'] as $field) {
            if ($this->has($field)) {
                $clean[$field] = preg_replace('/\s+/', ' ', trim((string) $this->input($field)));
            }
        }
        $this->merge($clean);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            // The same phone rule as sign-up on the screen: 7 to 15 digits, spaces, dashes or brackets, optional +.
            'phone_num' => ['sometimes', 'required', 'string', 'regex:/^\+?[\d\s\-()]{7,15}$/'],
            'gender' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Gender::VALUES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone_num.regex' => 'Enter a valid phone number.',
            'name.min' => 'Enter your full name.',
        ];
    }
}
