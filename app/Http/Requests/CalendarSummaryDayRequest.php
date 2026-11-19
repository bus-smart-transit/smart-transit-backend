<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `CalendarSummaryController::day`, moved out of the controller unchanged.
 */
class CalendarSummaryDayRequest extends FormRequest
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
            'date' => 'required|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.(int) config('calendar.day_max_per_page'),
        ];
    }
}
