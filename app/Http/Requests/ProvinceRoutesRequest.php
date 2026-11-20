<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** 2026-11-20: GET routes?province=... (the routes a homepage province picture opens). */
class ProvinceRoutesRequest extends FormRequest
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
        return ['province' => ['required', 'string', 'max:100']];
    }
}
