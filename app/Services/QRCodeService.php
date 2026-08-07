<?php

namespace App\Services;

use App\Models\Ticket;
use App\Repositories\StopRepository;

class QRCodeService
{
    public function __construct(private StopRepository $stopRepository) {}
    /**
     * Generate QR code data/URL for a ticket
     * Returns data suitable for frontend QR code generation
     */
    public function generateQRData(Ticket $ticket): array
    {
        // QR code now encodes only ticket UUID.
        $qrContent = $this->encodeTicketData($ticket);
        $destination = $ticket->destinationStop?->stop_name ?? $this->resolveDestinationFromPaymentPayload($ticket) ?? 'Not specified';

        $transactionRef = $ticket->payment?->transaction_reference;
        $groupQrContent = $transactionRef ? "grp:{$transactionRef}" : null;

        return [
            'ticket_uuid'           => $ticket->ticket_uuid,
            'qr_content'            => $qrContent,
            'qr_url'                => $this->generateQRUrl($qrContent),
            'group_qr_content'      => $groupQrContent,
            'group_qr_url'          => $groupQrContent ? $this->generateQRUrl($groupQrContent) : null,
            'transaction_reference' => $transactionRef,
            'passenger_name'        => $ticket->passenger?->user?->name ?? 'Guest',
            'trip_id'               => $ticket->trip_id,
            'destination'           => $destination,
            'seat_type'             => $ticket->seat_type,
            'amount'                => $ticket->amount,
            'valid_from'            => $ticket->trip?->trip_date?->copy()->startOfDay()?->toIso8601String(),
            'expires_at'            => $ticket->trip?->trip_date?->copy()->endOfDay()?->toIso8601String(),
        ];
    }

    private function resolveDestinationFromPaymentPayload(Ticket $ticket): ?string
    {
        $items = $ticket->payment?->items_payload ?? [];

        foreach ($items as $item) {
            $sameTrip = (int) ($item['trip_id'] ?? 0) === (int) $ticket->trip_id;
            $sameSeatType = ($item['seat_type'] ?? null) === $ticket->seat_type;
            if (!$sameTrip || !$sameSeatType) {
                continue;
            }

            $destinationStopId = isset($item['destination_stop_id']) ? (int) $item['destination_stop_id'] : null;
            if (!$destinationStopId) {
                continue;
            }

            return $this->stopRepository->findById($destinationStopId)?->stop_name;
        }

        return null;
    }

    /**
     * Encode ticket data into QR format.
     * Format: ticket_uuid (UUID-only).
     */
    private function encodeTicketData(Ticket $ticket): string
    {
        return $ticket->ticket_uuid;
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
        // UUID-only format (preferred)
        if (!str_contains($content, '|')) {
            return [
                'ticket_uuid' => $content,
                'trip_id' => null,
                'passenger_id' => null,
                'destination_stop_id' => null,
                'issued_at' => null,
            ];
        }

        // Legacy format compatibility
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
        // UUID-only format is valid.
        if (!str_contains($content, '|')) {
            return !empty(trim($content));
        }

        // Legacy format compatibility.
        $parts = explode('|', $content);
        return count($parts) === 5 && !empty($parts[0]); // Must have ticket_uuid
    }
}
