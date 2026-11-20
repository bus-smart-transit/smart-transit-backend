<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 2026-11-20 (user): the Company Admin renames a route or changes its origin and destination labels. */
class UpdateRouteRequest extends FormRequest
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
            'route_name' => ['sometimes', 'required', 'string', 'max:255'],
            'origin' => ['sometimes', 'required', 'string', 'max:255'],
            'destination' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}
