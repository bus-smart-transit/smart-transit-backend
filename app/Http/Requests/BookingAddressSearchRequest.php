<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `BookingController::addressSearch`, moved out of the controller unchanged.
 */
class BookingAddressSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['q' => 'required|string|min:'.(int) config('timeline.address_search.min_query_length').'|max:120'];
    }
}
