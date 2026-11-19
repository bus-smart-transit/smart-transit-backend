<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `CrewShiftLogController::index`, moved out of the controller unchanged. The
 * list has two shapes: a date range with a staff filter and pages when `from` is sent (Batch 46), else one day.
 */
class CrewShiftLogIndexRequest extends FormRequest
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
        if ($this->has('from')) {
            return [
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
                'staff_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            ];
        }

        return ['date' => ['sometimes', 'date_format:Y-m-d']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end date must be on or after the start date.'];
    }
}
