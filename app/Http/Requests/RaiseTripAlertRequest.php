<?php

namespace App\Http\Requests;

use App\Services\TripAlertService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Batch 58: a Driver or Chauffeur sends an alert to their operator. The type is one of config('alerts.types'); the
 * phone's position is optional (both or neither). TripAlertService decides whether this person may send it.
 */
class RaiseTripAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string|in:'.implode(',', array_keys(TripAlertService::types())),
            'note' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
        ];
    }

    public function messages(): array
    {
        return ['type.in' => 'Choose what happened: '.implode(', ', TripAlertService::types()).'.'];
    }
}
