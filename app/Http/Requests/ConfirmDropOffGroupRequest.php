<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 2026-10-27 (§7): POST conductor/trips/current/drop-off-groups/confirm. */
class ConfirmDropOffGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'destination_key' => ['required', 'string', 'max:64', 'regex:/^(stop|point|pin):[0-9.,-]+$/'],
            'ticket_ids' => ['required', 'array', 'min:1', 'max:200'],
            'ticket_ids.*' => ['integer'],
        ];
    }
}
