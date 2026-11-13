<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Batch 67: "Send me text messages" on or off, for the signed-in person. */
class UpdateTextMessagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['enabled' => 'required|boolean'];
    }
}
