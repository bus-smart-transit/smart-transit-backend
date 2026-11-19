<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 75: the Chauffeur approves or rejects a discount claimed online, after checking the passenger's valid ID. */
class ReviewDiscountClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => 'required|string|in:approve,reject',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['decision.in' => 'Choose Approve or Reject.'];
    }
}
