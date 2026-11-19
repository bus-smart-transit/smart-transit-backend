<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 51 item 3: a point for "Are you in {place}?" (the page sends it rounded; the server rounds it again). */
class RegionPlaceRequest extends FormRequest
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
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
