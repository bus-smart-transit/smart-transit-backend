<?php

namespace App\Services;

use App\Mail\TicketTrackingMail;
use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "follow your bus" email once an online payment has become tickets: one link per ticket, to the email the
 * buyer used (a guest's checkout email, or the passenger's account email). It never carries the ticket QR code.
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

            $base = rtrim((string) config('app.frontend_url'), '/');
            $items = $tickets->map(function (Ticket $ticket) use ($base) {
                $token = $this->tracking->tokenFor($ticket);

                return $token === null ? null : [
                    'route' => (string) ($ticket->trip?->fleetRoute?->route?->route_name ?? 'Your trip'),
                    'date' => (string) $ticket->trip?->trip_date?->toDateString(),
                    'time' => substr((string) $ticket->trip?->departure_time, 0, 5),
                    'from' => (string) ($ticket->originStop?->stop_name ?? 'Boarding point'),
                    'to' => (string) ($ticket->destinationStop?->stop_name ?? $ticket->destination_label ?? 'Drop-off point'),
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
            ));
        } catch (\Throwable $e) {
            Log::warning('Tracking link email could not be sent for payment '.$payment->payment_id.': '.$e->getMessage());
        }
    }
}
