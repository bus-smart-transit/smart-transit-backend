<?php

namespace App\Http\Requests;

use App\Rules\ValidPhone;
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
        if ($this->has('name')) {
            $clean['name'] = preg_replace('/\s+/', ' ', trim((string) $this->input('name')));
        }
        // Batch 47 part B: one stored phone form (E.164); App\Rules\ValidPhone refuses a number that is not valid.
        if ($this->has('phone_num')) {
            $clean['phone_num'] = ValidPhone::prepare($this->input('phone_num'));
        }
        $this->merge($clean);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            // Batch 47 part B: the same phone rule as sign-up and staff forms (App\Support\PhoneNumber).
            'phone_num' => ['sometimes', 'required', 'string', new ValidPhone],
            'gender' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', Gender::VALUES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.min' => 'Enter your full name.',
        ];
    }
}
