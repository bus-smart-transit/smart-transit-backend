<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `FleetPairingController::pairAsDriver` / `pairAsConductor` (a pairing token
 * from the partner's QR, or the typed PIN), moved out of the controller unchanged.
 */
class PairWithCrewRequest extends FormRequest
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
            'token' => 'nullable|string|required_without:pin_code',
            'pin_code' => 'nullable|string|required_without:token',
        ];
    }
}
