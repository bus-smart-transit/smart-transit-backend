<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 2026-10-29: the Operator's answer to a trip's dispatch advice. "accept" and "keep" only record the choice;
 * "contact" also sends the Operator's message to the trip's assigned Driver and Chauffeur, so it needs a message.
 */
class SaveDispatchDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('note')) {
            $note = trim((string) $this->input('note'));
            $this->merge(['note' => $note === '' ? null : $note]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:accept,keep,contact'],
            'route' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'required_if:decision,contact', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'note.required_if' => 'Write a message for the crew first.',
        ];
    }
}
