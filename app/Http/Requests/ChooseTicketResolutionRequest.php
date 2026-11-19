<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `TicketResolutionController::choose`, moved out of the controller unchanged.
 */
class ChooseTicketResolutionRequest extends FormRequest
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
        return [
            'choice' => 'required|string|in:rebook,refund',
            'trip_id' => 'nullable|integer',
        ];
    }
}
