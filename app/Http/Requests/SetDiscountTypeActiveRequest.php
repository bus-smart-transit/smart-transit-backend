<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 2026-10-27: PATCH operator/discount-types/{key}/active. */
class SetDiscountTypeActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }
}
