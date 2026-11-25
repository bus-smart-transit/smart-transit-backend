<?php

namespace App\Http\Requests;

use App\Support\BaggageFee;
use Illuminate\Foundation\Http\FormRequest;

/** 2026-11-25 (user): the Operator sets the company's baggage price for each size. */
class BaggageFeesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [];
        foreach (BaggageFee::sizeKeys() as $size) {
            $rules[$size] = ['required', 'numeric', 'min:0', 'max:'.BaggageFee::MAX_PRICE];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [];
        foreach (BaggageFee::SIZES as $size => $meta) {
            $messages["{$size}.required"] = "Enter the price for {$meta['label']}.";
            $messages["{$size}.numeric"] = "The price for {$meta['label']} must be a number.";
            $messages["{$size}.min"] = "The price for {$meta['label']} cannot be below 0.";
            $messages["{$size}.max"] = "The price for {$meta['label']} can be at most PHP ".number_format(BaggageFee::MAX_PRICE).'.';
        }

        return $messages;
    }
}
