<?php

namespace App\Http\Requests;

use App\Services\RegionService;
use Illuminate\Foundation\Http\FormRequest;

class StoreStopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stop_name' => 'required|string|max:255',
            'stop_code' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:stops,stop_code'],
            // 2026-11-06 (Batch 47 part A): the place is the map pin (required); the label tells same-named stops apart.
            'location_label' => 'nullable|string|max:80',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'municipality' => ['nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && app(RegionService::class)->canonicalMunicipality($value) === null) {
                    $fail('The municipality must be one listed in the region configuration.');
                }
            }],
        ];
    }
}
