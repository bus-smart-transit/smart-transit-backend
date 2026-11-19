<?php

namespace App\Http\Requests;

use App\Support\VehicleClass;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `BookingController::trips`, moved out of the controller unchanged.
 */
class BookingTripsRequest extends FormRequest
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
            // Batch 52 item 1: the passenger's town (or province), and the stop ids nearest first as measured on the device.
            'town' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'prefer' => ['nullable', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/'],
            'from' => 'nullable|string|max:64',
            'to' => 'nullable|string|max:64',
            'date' => 'nullable|string|max:10',
            'seat_type' => 'nullable|in:seated', // online booking is for seats; standing is sold on the bus
            // Batch 54 (user): the trips page's bus type filter (Ordinary or Aircon, the classes in config/fares.php).
            'bus_type' => 'nullable|string|in:'.implode(',', VehicleClass::keys()),
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.(int) config('booking.trips_max_per_page'),
        ];
    }
}
