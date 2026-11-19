<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 49 item 4: start a GPS simulation of a departed trip (the service checks the trip and the switch). */
class StartGpsSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => 'required|integer|min:1',
            'speed' => 'required|integer|in:'.implode(',', config('route_geometry.gps.simulator_speeds')),
        ];
    }
}
