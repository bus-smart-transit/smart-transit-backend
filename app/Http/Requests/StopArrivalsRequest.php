<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 77: GET stops/{code}/arrivals?limit= (public; the Track Bus stop pin card). */
class StopArrivalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('tracking.arrivals.max_limit', 20)],
        ];
    }
}
