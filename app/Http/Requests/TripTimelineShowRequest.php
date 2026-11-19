<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `TripTimelineController::show`, moved out of the controller unchanged.
 */
class TripTimelineShowRequest extends FormRequest
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
            'boarding_stop_id' => 'nullable|integer|min:1',
            'alighting_stop_id' => 'nullable|integer|min:1',
            'dropoff_lat' => 'nullable|numeric|between:-90,90|required_with:dropoff_lng',
            'dropoff_lng' => 'nullable|numeric|between:-180,180|required_with:dropoff_lat',
            'alighting_point_id' => 'nullable|integer|min:1',
            'seat_type' => 'nullable|in:seated,standing',
        ];
    }
}
