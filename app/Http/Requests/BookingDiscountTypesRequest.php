<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 75: the discounts a passenger may claim online for one trip (the bus Operator's choice). */
class BookingDiscountTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['trip_id' => 'required|integer|exists:trips,trip_id'];
    }
}
