<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `ShiftBlockController::index`, `ShiftBlockController::myBlocksAsDriver`, `ShiftBlockController::myBlocksAsConductor`, `TripController::myTrips`, `TripController::myConductorTrips`, moved out of the controller unchanged.
 */
class WidePageRequest extends FormRequest
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
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
