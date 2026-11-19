<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `TicketResolutionController::refundHistory`, moved out of the controller unchanged.
 */
class TicketResolutionRefundHistoryRequest extends FormRequest
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
        return ['page' => 'nullable|integer|min:1'];
    }
}
