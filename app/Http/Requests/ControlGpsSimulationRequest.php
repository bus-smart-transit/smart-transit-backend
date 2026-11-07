<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 49 item 4: pause, resume, stop, jump to a stop, or change the speed of a GPS simulation. */
class ControlGpsSimulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|string|in:pause,resume,stop,jump,speed',
            'stop_id' => 'required_if:action,jump|nullable|integer|min:1',
            'speed' => 'required_if:action,speed|nullable|integer|in:'.implode(',', config('route_geometry.gps.simulator_speeds')),
        ];
    }
}
