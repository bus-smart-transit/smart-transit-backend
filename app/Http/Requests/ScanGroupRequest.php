<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ScanGroupRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'transaction_reference' => 'required|string|exists:payments,transaction_reference',
        ];
    }
}
