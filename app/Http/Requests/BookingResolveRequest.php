<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `BookingController::resolve`, moved out of the controller unchanged.
 */
class BookingResolveRequest extends FormRequest
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
            'mode' => 'required|in:now,later',
            'origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|different:origin_stop_id|exists:stops,stop_id',
            'date' => 'nullable|date_format:Y-m-d|required_if:mode,later',
            'time' => 'nullable|date_format:H:i|required_if:mode,later',
            'seat_type' => 'nullable|in:seated', // online booking is for seats; standing is sold on the bus
        ];
    }
}
