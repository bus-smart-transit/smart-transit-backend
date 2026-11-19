<?php

namespace App\Http\Requests;

use App\Services\TripAssignmentRequestService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of a Driver's or Chauffeur's request to be released from a trip
 * (`TripController::declineAsDriver` / `declineAsConductor`) or a shift block (`AssignmentController::declineAsDriver` /
 * `declineAsConductor`), moved out of the controllers unchanged.
 */
class DeclineAssignmentRequest extends FormRequest
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
            'reason_code' => ['nullable', 'string', 'in:'.implode(',', TripAssignmentRequestService::REASON_CODES)],
            'reason_text' => ['nullable', 'string', 'max:'.TripAssignmentRequestService::REASON_TEXT_MAX, 'required_if:reason_code,other'],
        ];
    }
}
