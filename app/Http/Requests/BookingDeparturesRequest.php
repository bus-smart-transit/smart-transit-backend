<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `BookingController::departures`, moved out of the controller unchanged.
 */
class BookingDeparturesRequest extends FormRequest
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
            'origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|different:origin_stop_id|exists:stops,stop_id',
            'date' => 'required|date_format:Y-m-d',
            'seat_type' => 'nullable|in:seated', // online booking is for seats; standing is sold on the bus
        ];
    }
}
