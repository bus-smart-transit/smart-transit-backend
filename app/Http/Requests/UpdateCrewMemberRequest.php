<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Batch 46 (decided 2026-11-05): an Operator edits one of their own Drivers or Chauffeurs: username, phone and email
 * only. The name and gender are not editable here; the crew member cannot change these themselves.
 */
class UpdateCrewMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('email')) {
            $merge['email'] = strtolower(trim((string) $this->input('email')));
        }
        if ($this->has('username')) {
            $merge['username'] = trim((string) $this->input('username'));
        }
        if ($this->has('phone_num')) {
            $merge['phone_num'] = PhoneNumber::normalize($this->input('phone_num')) ?? trim((string) $this->input('phone_num'));
        }
        $this->merge($merge);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => 'sometimes|required|string|min:3|max:50|regex:/^[A-Za-z0-9._-]+$/',
            'email' => 'sometimes|required|email|max:255',
            'phone_num' => ['sometimes', 'required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (PhoneNumber::normalize((string) $value) === null) {
                    $fail(PhoneNumber::INVALID_MESSAGE);
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Use only letters, numbers, dots, dashes and underscores.',
        ];
    }
}
