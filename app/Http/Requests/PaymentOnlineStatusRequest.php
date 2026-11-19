<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 4 (2026-11-19): the input rules of `PaymentController::onlineStatus`, moved out of the controller unchanged.
 */
class PaymentOnlineStatusRequest extends FormRequest
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
        return ['ref' => 'required|string|max:64'];
    }
}
