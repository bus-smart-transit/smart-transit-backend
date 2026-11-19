<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `DropoffRulesController::points`, moved out of the controller unchanged.
 */
class DropoffRulesPointsRequest extends FormRequest
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
            'route_id' => 'nullable|integer',
            'municipality' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ];
    }
}
