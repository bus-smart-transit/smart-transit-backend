<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ScanTicketRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'ticket_uuid' => 'required|string|exists:tickets,ticket_uuid',
        ];
    }
}
