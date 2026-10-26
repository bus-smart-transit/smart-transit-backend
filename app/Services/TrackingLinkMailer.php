<?php

namespace App\Services;

use App\Mail\TicketTrackingMail;
use App\Models\Payment;
use App\Models\Ticket;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "follow your bus" email once an online payment has become tickets: one link per trip (§4.10), to the email the
 * buyer used (a guest's checkout email, or the passenger's account email), with the boarding QR (§4.10, 2026-10-26).
 *
 * Fail-soft: a payment is already complete when this runs, so a mail that cannot be sent is logged (without the link)
 * and never undoes or delays anything. With MAIL_INTERCEPT_EMAIL set (the demo sender only reaches its owner) the mail
 * goes there and says which account it is for. The mail is queued so the payment webhook answers at once.
 */
class TrackingLinkMailer
{
    public function __construct(private TicketTrackingService $tracking) {}

    public function sendForPayment(Payment $payment): void
    {
        try {
            $tickets = Ticket::with(['trip.fleetRoute.route', 'originStop', 'destinationStop', 'passenger.user'])
                ->where('payment_id', $payment->payment_id)
                ->orderBy('ticket_id')
                ->get();

            $buyer = $payment->guest_email ?: $tickets->first()?->passenger?->user?->email;
            if ($tickets->isEmpty() || ! $buyer) {
                return;
            }

            // §4.10: one link per bus, not per ticket. Tickets bought together on the same trip ride the same bus, so the
            // first ticket's link stands for them all; a roundtrip has two trips and so two links. Every ticket keeps
            // its own token (its own "New tracking link" in the account is unchanged).
            $base = rtrim((string) config('app.frontend_url'), '/');
            $items = $tickets->groupBy('trip_id')->map(function ($group) use ($base) {
                $ticket = $group->first();
                $token = $this->tracking->tokenFor($ticket);

                return $token === null ? null : [
                    'route' => (string) ($ticket->trip?->fleetRoute?->route?->route_name ?? 'Your trip'),
                    'date' => (string) $ticket->trip?->trip_date?->toDateString(),
                    'time' => substr((string) $ticket->trip?->departure_time, 0, 5),
                    'from' => (string) ($ticket->originStop?->stop_name ?? 'Boarding point'),
                    'to' => (string) ($ticket->destinationStop?->stop_name ?? $ticket->destination_label ?? 'Drop-off point'),
                    'tickets' => (string) $group->count(),
                    'link' => $base.'/track/'.$token,
                ];
            })->filter()->values()->all();

            if ($items === []) {
                return;
            }

            $intercept = config('mail.intercept_email');
            $name = $tickets->first()->passenger?->name ?: 'there';

            Mail::to($intercept ?: $buyer)->queue(new TicketTrackingMail(
                $name,
                $items,
                (string) config('tracking.opens_minutes_before'),
                (string) $buyer,
                (bool) $intercept,
                $this->qrCodes($payment, $tickets),
            ));
        } catch (\Throwable $e) {
            Log::warning('Tracking link email could not be sent for payment '.$payment->payment_id.': '.$e->getMessage());
        }
    }

    /**
     * §4.10 (2026-10-26): the boarding QR in the email, drawn here (endroid/qr-code, no outside service) from the same
     * content the app shows (QRCodeService): one group QR for the purchase when the buyer kept that choice and bought
     * more than one ticket, otherwise one QR per ticket. If a QR cannot be drawn the email still goes, without it.
     *
     * @return list<array{label: string, png: string}> base64 PNG
     */
    private function qrCodes(Payment $payment, $tickets): array
    {
        try {
            $qr = app(QRCodeService::class);
            $draw = fn (string $content) => base64_encode((new PngWriter)->write(new QrCode(data: $content, size: 280, margin: 12))->getString());

            if ($tickets->count() > 1 && ($payment->email_group_qr ?? true)) {
                $content = $qr->generateQRData($tickets->first())['group_qr_content'] ?? null;

                return $content ? [['label' => 'Group QR: boards all '.$tickets->count().' tickets in one scan', 'png' => $draw($content)]] : [];
            }

            return $tickets->values()->map(fn (Ticket $ticket, int $i) => [
                'label' => $tickets->count() > 1 ? 'Ticket '.($i + 1).' of '.$tickets->count() : 'Your ticket',
                'png' => $draw((string) $qr->generateQRData($ticket)['qr_content']),
            ])->all();
        } catch (\Throwable $e) {
            Log::warning('Ticket QR for the tracking email could not be drawn for payment '.$payment->payment_id.': '.$e->getMessage());

            return [];
        }
    }
}
