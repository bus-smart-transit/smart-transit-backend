<?php

namespace App\Services;

use App\Mail\RefundCompletedMail;
use App\Models\PassengerUser;
use App\Models\Payment;
use App\Models\Ticket;
use App\Services\Sms\SmsNotifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * 2026-11-04 (Batch 45, Todo R): "your refund is done", once, to the buyer: the guest's checkout email, or the
 * signed-in passenger's account email (from the payment's tickets, or the checkout's own record of the passenger when
 * no ticket was ever issued, as for a late payment). No address: nothing is sent.
 *
 * Fail-soft like TrackingLinkMailer: the refund is already settled when this runs, so a mail that cannot be sent is
 * logged and never changes anything. Queued, so the webhook or the Operator's request answers at once.
 */
class RefundCompletedMailer
{
    public function send(Payment $payment, float $amount, ?Ticket $ticket = null): void
    {
        try {
            $ticket ??= Ticket::with(['passenger.user', 'trip.fleetRoute.route'])->where('payment_id', $payment->payment_id)->orderBy('ticket_id')->first();
            $ticket?->loadMissing(['passenger.user', 'trip.fleetRoute.route']);

            $passengerUser = $ticket?->passenger?->user ?? $this->checkoutPassengerUser($payment);
            $buyer = $payment->guest_email ?: $passengerUser?->email;
            if (! $buyer) {
                return;
            }

            $intercept = config('mail.intercept_email');
            Mail::to($intercept ?: $buyer)->queue(new RefundCompletedMail(
                name: $ticket?->passenger?->name ?: 'there',
                amount: round($amount, 2),
                wallet: ['gcash' => 'GCash', 'maya' => 'Maya'][$payment->payment_channel] ?? 'your e-wallet',
                reference: (string) ($payment->transaction_reference ?? ''),
                route: (string) ($ticket?->trip?->fleetRoute?->route?->route_name ?? ''),
                date: (string) ($ticket?->trip?->trip_date?->toDateString() ?? ''),
                time: substr((string) ($ticket?->trip?->departure_time ?? ''), 0, 5),
                accountEmail: (string) $buyer,
                redirected: (bool) $intercept,
            ));
            // Batch 64: and a short text (when refund texts are switched on).
            app(SmsNotifier::class)->refundCompleted($payment, $amount);
        } catch (\Throwable $e) {
            Log::warning('Refund completed email could not be sent.', ['payment_id' => $payment->payment_id, 'error' => $e->getMessage()]);
        }
    }

    private function checkoutPassengerUser(Payment $payment): ?object
    {
        $passengerId = collect($payment->items_payload ?? [])->pluck('passenger_id')->filter()->first();

        return $passengerId ? PassengerUser::with('user')->find((int) $passengerId)?->user : null;
    }
}
