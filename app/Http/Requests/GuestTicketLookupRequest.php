<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuestTicketLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_reference' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'payment_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}