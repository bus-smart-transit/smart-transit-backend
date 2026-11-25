<?php

namespace App\Http\Requests;

use App\Support\BaggageFee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 2026-11-25 (user): the Chauffeur records baggage paid on board: its size and the paper stub's number, if any. */
class BaggageChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'size' => ['required', 'string', Rule::in(BaggageFee::sizeKeys())],
            'stub_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'size.required' => 'Choose Small, Medium or Large.',
            'size.in' => 'Choose Small, Medium or Large.',
            'stub_number.max' => 'The stub number can be at most 30 characters.',
        ];
    }
}
