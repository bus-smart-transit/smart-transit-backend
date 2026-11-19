<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `DropoffRulesController::storeRestriction`, moved out of the controller
 * unchanged. UpdateDropoffRestrictionRequest uses the same rules with every identifying field optional.
 */
class StoreDropoffRestrictionRequest extends FormRequest
{
    /** Whether the route, name and stops are required (a new restriction) or optional (an edit). */
    protected bool $creating = true;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->creating ? 'required' : 'sometimes';

        return [
            'route_id' => $required.'|integer|exists:routes,route_id',
            'name' => $required.'|string|max:120',
            'from_stop_id' => $required.'|integer|exists:stops,stop_id',
            'to_stop_id' => $required.'|integer|exists:stops,stop_id',
            'note' => 'nullable|string|max:1000',
            'source' => 'nullable|string|max:120',
            'is_active' => 'nullable|boolean',
        ];
    }
}
