<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `StaffAuthController::listDrivers`, moved out of the controller unchanged.
 */
class StaffAuthListDriversRequest extends FormRequest
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
            'trip_date' => ['sometimes', 'date_format:Y-m-d'],
            'include_busy' => ['sometimes', 'boolean'],
        ];
    }
}
