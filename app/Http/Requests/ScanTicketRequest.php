<?php
namespace App\Http\Requests;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
class ScanTicketRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            // The QR content (the full ticket UUID), or the 8-character Ticket ID printed on the ticket, which the
            // service looks up only within the conductor's own running trip.
            'ticket_uuid' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                $code = trim((string) $value);

                if (preg_match('/^[0-9a-f]{8}$/i', $code)) {
                    return;
                }

                if (! Str::isUuid($code) || ! Ticket::where('ticket_uuid', $code)->exists()) {
                    $fail('Enter the ticket QR code or its 8-character Ticket ID.');
                }
            }],
        ];
    }
}
