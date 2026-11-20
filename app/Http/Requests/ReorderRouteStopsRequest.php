<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 2026-11-20 (user): the route's stops in their new order (every route stop id of the route, once). */
class ReorderRouteStopsRequest extends FormRequest
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
            'route_stop_ids' => ['required', 'array', 'min:2'],
            'route_stop_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
