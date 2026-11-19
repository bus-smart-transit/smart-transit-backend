<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateTripStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:scheduled,delayed,boarding,departed,in-progress,completed,cancelled'],
        ];
    }
}
