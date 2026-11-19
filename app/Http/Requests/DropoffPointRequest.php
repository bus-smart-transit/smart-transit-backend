<?php

namespace App\Http\Requests;

use App\Models\AlightingPoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 4 (2026-11-19): the input rules of `DropoffRulesController::storePoint` and `updatePoint` (an official
 * alighting point), moved out of the controller unchanged.
 */
class DropoffPointRequest extends FormRequest
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
            'route_id' => 'required|integer|exists:routes,route_id',
            'name' => 'required|string|max:120',
            'kind' => ['required', 'string', Rule::in(AlightingPoint::KINDS)],
            'municipality' => 'nullable|string|max:100',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'source' => 'nullable|string|max:120',
            'note' => 'nullable|string|max:1000',
            'is_active' => 'nullable|boolean',
        ];
    }
}
