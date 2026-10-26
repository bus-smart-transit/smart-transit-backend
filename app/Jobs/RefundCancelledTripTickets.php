<?php

namespace App\Jobs;

use App\Mail\TripCancelledRefundMail;
use App\Models\Ticket;
use App\Models\TicketResolution;
use App\Services\TicketResolutionService;
use App\Services\TripCancellationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * smarttransit_prompt.md §10 / 2026-10-26: when the Operator cancels a trip, an ONLINE ticket is refunded at once
 * instead of the passenger choosing rebook or refund. It goes through the existing refund path
 * (TicketResolutionService::choose with `refund`): the amount is the unused part (the full fare before departure),
 * Maya's next-day rule for a partial refund still applies, an amount that cannot be priced safely goes to Operator
 * review, and the signed PayMongo webhook still decides when a refund has succeeded. The buyer then gets one email per
 * purchase. CASH tickets are left as they were (awaiting choice): how bus lines hand back cash is not decided yet.
 *
 * Safe to run twice: a ticket that already has a resolution keeps it (ticket_resolutions.ticket_id is unique).
 */
class RefundCancelledTripTickets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    /** @param  list<int>|null  $ticketIds  only these tickets (a late payment), or every waiting ticket of the trip */
    public function __construct(public int $tripId, public ?array $ticketIds = null) {}

    public function handle(TicketResolutionService $resolutions): void
    {
        $tickets = Ticket::with(['payment', 'passenger.user', 'trip.fleetRoute.route'])
            ->where('trip_id', $this->tripId)
            ->where('status', TripCancellationService::AWAITING_CHOICE)
            ->when($this->ticketIds !== null, fn ($q) => $q->whereIn('ticket_id', $this->ticketIds))
            ->get()
            ->filter(fn (Ticket $ticket) => $resolutions->methodFor($ticket) === TicketResolution::METHOD_GATEWAY);

        $refunded = [];
        foreach ($tickets as $ticket) {
            try {
                $refunded[] = [$ticket, $resolutions->choose($ticket, ['choice' => TicketResolution::REFUND], ['user' => null, 'role' => 'system', 'on_behalf' => true])];
            } catch (\Throwable $e) {
                // One ticket's trouble never stops the others; it stays awaiting choice for the Operator's queue.
                Log::warning('Automatic refund of a cancelled trip ticket failed.', ['ticket_id' => $ticket->ticket_id, 'error' => $e->getMessage()]);
            }
        }

        foreach (collect($refunded)->groupBy(fn (array $pair) => $pair[0]->payment_id) as $group) {
            $this->email($group->all());
        }
    }

    /** @param  list<array{0: Ticket, 1: TicketResolution}>  $pairs  the refunded tickets of one purchase */
    private function email(array $pairs): void
    {
        try {
            [$first] = $pairs[0];
            $buyer = $first->payment?->guest_email ?: $first->passenger?->user?->email;
            if (! $buyer) {
                return;
            }

            $review = collect($pairs)->contains(fn (array $pair) => $pair[1]->status === TicketResolution::NEEDS_REVIEW);
            $amount = collect($pairs)->sum(fn (array $pair) => (float) $pair[1]->amount);
            $intercept = config('mail.intercept_email');

            Mail::to($intercept ?: $buyer)->queue(new TripCancelledRefundMail(
                name: $first->passenger?->name ?: 'there',
                route: (string) ($first->trip?->fleetRoute?->route?->route_name ?? 'Your trip'),
                date: (string) $first->trip?->trip_date?->toDateString(),
                time: substr((string) $first->trip?->departure_time, 0, 5),
                tickets: count($pairs),
                amount: round($amount, 2),
                wallet: ['gcash' => 'GCash', 'maya' => 'Maya'][$first->payment?->payment_channel] ?? 'your e-wallet',
                needsReview: $review,
                accountEmail: (string) $buyer,
                redirected: (bool) $intercept,
            ));
        } catch (\Throwable $e) {
            Log::warning('Trip cancellation refund email could not be sent.', ['payment_id' => $pairs[0][0]->payment_id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
