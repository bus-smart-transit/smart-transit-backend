<?php

namespace App\Services;

use App\Models\Ticket;

class QRCodeService
{
    /**
     * Generate QR code data/URL for a ticket
     * Returns data suitable for frontend QR code generation
     */
    public function generateQRData(Ticket $ticket): array
    {
        // QR code encodes the ticket UUID
        // Frontend can use this with a library like qrcode.js or html5-qrcode
        $qrContent = $this->encodeTicketData($ticket);

        return [
            'ticket_id' => $ticket->ticket_id,
            'ticket_uuid' => $ticket->ticket_uuid,
            'qr_content' => $qrContent,
            'qr_url' => $this->generateQRUrl($qrContent),
            'passenger_name' => $ticket->passenger?->user?->name ?? 'Guest',
            'trip_id' => $ticket->trip_id,
            'destination' => $ticket->destinationStop?->stop_name ?? 'Not specified',
            'seat_type' => $ticket->seat_type,
            'amount' => $ticket->amount,
        ];
    }

    /**
     * Encode ticket data into QR format
     * Format: ticket_uuid|trip_id|passenger_id|destination_stop_id|issued_at
     */
    private function encodeTicketData(Ticket $ticket): string
    {
        return implode('|', [
            $ticket->ticket_uuid,
            $ticket->trip_id,
            $ticket->passenger_id ?? 'guest',
            $ticket->destination_stop_id ?? 0,
            $ticket->created_at->timestamp,
        ]);
    }

    /**
     * Generate QR code image URL (using external API)
     * Uses qrserver.com API for simplicity (alternative: install qrcode package)
     */
    private function generateQRUrl(string $content, int $size = 300): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($content);
    }

    /**
     * Decode QR content to extract ticket data
     * Returns array with ticket_uuid and other encoded data
     */
    public function decodeQRContent(string $content): array
    {
        $parts = explode('|', $content);

        return [
            'ticket_uuid' => $parts[0] ?? null,
            'trip_id' => (int)($parts[1] ?? 0),
            'passenger_id' => $parts[2] !== 'guest' ? (int)$parts[2] : null,
            'destination_stop_id' => (int)($parts[3] ?? 0),
            'issued_at' => isset($parts[4]) ? date('Y-m-d H:i:s', $parts[4]) : null,
        ];
    }

    /**
     * Validate QR code format
     */
    public function isValidQRContent(string $content): bool
    {
        $parts = explode('|', $content);
        return count($parts) === 5 && !empty($parts[0]); // Must have ticket_uuid
    }
}
