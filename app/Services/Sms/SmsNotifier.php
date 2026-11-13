<?php

namespace App\Services\Sms;

use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\TicketRepository;
use App\Services\TripCancellationService;
use App\Support\TicketBoarding;
use App\Support\TravellerContact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Batch 64 (user, 2026-11-13): what SmartTransit says by text, and to whom. No message starts with "SmartTransit":
 * the sender name the user set on IPROG already heads every text (2026-11-13). Each method builds one short message
 * (one credit, 160 characters) and hands it to SmsService, which decides whether it is sent. Never throws: a text is
 * extra news, so a problem here is logged and the payment, login or cancellation carries on.
 *
 * Who gets it: a passenger account's phone; for a purchase without one, the contact given at checkout when it is a
 * mobile number (a cash sale's phone, or "booking for someone else"). Staff: their staff profile phone.
 */
class SmsNotifier
{
    public function __construct(private SmsService $sms, private TicketRepository $tickets) {}

    /** The 6-digit login code (sent as well as the email). */
    public function loginCode(User $user, string $code, int $minutes): void
    {
        $this->safely(function () use ($user, $code, $minutes) {
            $phone = $user->passengerProfile?->phone_num ?? $user->companyProfile?->phone_num;
            $this->sms->send('login_code', $phone, "Your login code is {$code}. It works for {$minutes} minutes. Do not share it with anyone.", 'User', (int) $user->user_id);
        });
    }

    /** An online payment was confirmed: the trip and the Ticket IDs. */
    public function paymentConfirmed(Payment $payment): void
    {
        $this->safely(function () use ($payment) {
            if (! SmsService::enabledFor('payment_confirmed')) {
                return;
            }
            $tickets = $this->tickets->findForPaymentWithTrip((int) $payment->payment_id);
            if ($tickets->isEmpty()) {
                return;
            }
            $count = $tickets->count();
            $this->sms->send(
                'payment_confirmed',
                $this->buyerPhone($payment, $tickets),
                "Paid. {$count} ".($count === 1 ? 'ticket' : 'tickets').' '.$this->tripLine($tickets->first()).'. Ticket ID: '.$this->ids($tickets).'. Show the QR when boarding.',
                'Payment',
                (int) $payment->payment_id,
            );
        });
    }

    /** A cash ticket sold on the bus, when the passenger gave a mobile number. */
    public function cashTicket(Payment $payment): void
    {
        $this->safely(function () use ($payment) {
            if (! SmsService::enabledFor('cash_ticket') || ! TravellerContact::isPhone($payment->traveller_contact)) {
                return;
            }
            $tickets = $this->tickets->findForPaymentWithTrip((int) $payment->payment_id);
            if ($tickets->isEmpty()) {
                return;
            }
            $this->sms->send(
                'cash_ticket',
                $payment->traveller_contact,
                'Your cash ticket '.$this->tripLine($tickets->first()).'. Ticket ID: '.$this->ids($tickets).'. Keep this text.',
                'Payment',
                (int) $payment->payment_id,
            );
        });
    }

    /** A refund reached the buyer's e-wallet. */
    public function refundCompleted(Payment $payment, float $amount): void
    {
        $this->safely(function () use ($payment, $amount) {
            if (! SmsService::enabledFor('refund_completed')) {
                return;
            }
            $wallet = ['gcash' => 'GCash', 'maya' => 'Maya'][$payment->payment_channel] ?? 'e-wallet';
            $tickets = $this->tickets->findForPaymentWithTrip((int) $payment->payment_id);
            $this->sms->send(
                'refund_completed',
                $this->buyerPhone($payment, $tickets),
                'PHP '.number_format($amount, 2)." was refunded to your {$wallet}. Ref ".($payment->transaction_reference ?? '').'.',
                'Payment',
                (int) $payment->payment_id,
            );
        });
    }

    /**
     * A trip was cancelled: one text per purchase on it. Online fares are refunded automatically
     * (refunds.auto_refund_online_on_cancel); cash ticket holders choose a refund or another trip.
     *
     * @param  list<int>|null  $ticketIds  only these tickets (a late payment for a trip already cancelled)
     */
    public function tripCancelled(Trip $trip, ?array $ticketIds = null, bool $onlineRefundedAutomatically = true): void
    {
        $this->safely(function () use ($trip, $ticketIds, $onlineRefundedAutomatically) {
            if (! SmsService::enabledFor('trip_cancelled')) {
                return;
            }
            $trip->loadMissing('fleetRoute.route');
            $line = $this->tripLineFor($trip);
            foreach ($this->tickets->findForTripWithPayment((int) $trip->trip_id, [TripCancellationService::AWAITING_CHOICE], $ticketIds)->groupBy('payment_id') as $group) {
                $payment = $group->first()->payment;
                if (! $payment) {
                    continue;
                }
                $next = $payment->payment_method === 'online' && $onlineRefundedAutomatically
                    ? 'Your fare will be refunded to your e-wallet.'
                    : 'Open the app or ask at the terminal to rebook or get a refund.';
                $this->sms->send('trip_cancelled', $this->buyerPhone($payment, $group), "Your trip {$line} was cancelled. {$next}", 'Trip', (int) $trip->trip_id);
            }
        });
    }

    /** The Operator's "Tell passengers" on a crew alert: one text per purchase still on the trip. Returns how many. */
    public function tellPassengers(int $tripId, string $notice): int
    {
        $sent = 0;
        $this->safely(function () use ($tripId, $notice, &$sent) {
            if (! SmsService::enabledFor('tell_passengers')) {
                return;
            }
            foreach ($this->tickets->findForTripWithPayment($tripId, ['valid', 'boarded'])->groupBy('payment_id') as $group) {
                $payment = $group->first()->payment;
                if ($payment && $this->sms->send('tell_passengers', $this->buyerPhone($payment, $group), $notice, 'Trip', $tripId)?->phone) {
                    $sent++;
                }
            }
        });

        return $sent;
    }

    /** The buyer's mobile: the passenger account's phone, else the checkout contact when it is a mobile number. */
    private function buyerPhone(Payment $payment, Collection $tickets): ?string
    {
        $accountPhone = $tickets->first()?->passenger?->phone_num;
        if ($accountPhone) {
            return $accountPhone;
        }

        return TravellerContact::isPhone($payment->traveller_contact) ? $payment->traveller_contact : null;
    }

    private function tripLine(object $ticket): string
    {
        $from = $ticket->originStop?->stop_name;
        $to = $ticket->destinationStop?->stop_name ?? $ticket->destination_label;
        $where = $from && $to ? "{$from} to {$to}" : ($ticket->trip?->fleetRoute?->route?->route_name ?? 'your trip');

        return trim($where.' '.$this->when($ticket->trip));
    }

    /**
     * Batch 66 (user's wording, 2026-11-13): sent at once when the Operator gives a Driver or Chauffeur a schedule. It
     * names no trip, so it is the same for every assignment; sms.once_per_day keeps it to one text per crew member per
     * day (an Operator usually assigns several trips in one sitting). The push notification still goes for each one.
     */
    public const CREW_ASSIGNED_MESSAGE = 'You have new schedules assigned. Please check your portal to view and accept them.';

    /** A single trip (scheduled with its crew, or the crew assigned later). Passengers are never texted here. */
    public function crewTripAssigned(Trip $trip, string $role): void
    {
        $this->safely(function () use ($trip, $role) {
            if (! SmsService::enabledFor('crew_assigned')) {
                return;
            }
            $relation = $role === 'driver' ? 'driver' : 'conductor';
            $trip->loadMissing($relation);
            $this->sms->send('crew_assigned', $trip->{$relation}?->phone_num, self::CREW_ASSIGNED_MESSAGE, 'Trip', (int) $trip->trip_id);
        });
    }

    /** A shift block: one text for the whole block, not one per leg. */
    public function crewBlockAssigned(?object $staff, int $blockId): void
    {
        $this->safely(function () use ($staff, $blockId) {
            if (! SmsService::enabledFor('crew_assigned')) {
                return;
            }
            $this->sms->send('crew_assigned', $staff?->phone_num, self::CREW_ASSIGNED_MESSAGE, 'ShiftBlock', $blockId);
        });
    }

    private function tripLineFor(Trip $trip): string
    {
        return trim(($trip->fleetRoute?->route?->route_name ?? '').' '.$this->when($trip));
    }

    private function when(?Trip $trip): string
    {
        if (! $trip) {
            return '';
        }
        $date = $trip->trip_date?->format('M j');
        $time = substr((string) $trip->departure_time, 0, 5);

        return trim(($date ? "on {$date}" : '').($time ? " {$time}" : ''));
    }

    /** Up to three Ticket IDs, then "+N more". */
    private function ids(Collection $tickets): string
    {
        $ids = $tickets->map(fn ($ticket) => TicketBoarding::ticketId($ticket->ticket_uuid))->values();

        return $ids->take(3)->implode(', ').($ids->count() > 3 ? ' +'.($ids->count() - 3).' more' : '');
    }

    private function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::warning('A text message could not be prepared.', ['error' => $e->getMessage()]);
        }
    }
}
