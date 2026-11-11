<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\Trip;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;

/**
 * Batch 60 item 1 (user, 2026-11-11): ONE owner for "this digital ticket can no longer be used". A ticket that was
 * never scanned stays usable while its ride can still happen, and is marked expired once:
 *  - its trip (leg) has ended (completed), or
 *  - the bus has reached or gone past the ticket's destination (TripProgressService::destinationReached: the Driver
 *    tapped that stop, or the live position is past it by booking.ticket_expiry_passed_margin_m).
 * Example: a ticket to the third stop can still be scanned while the bus is at the first; once the bus is past the
 * third stop it is expired.
 * The end-of-trip-day rule (TicketService::appendValidityWindow) stays as the last safety net. A boarded ticket is never
 * touched here, and money does not change: an expired unused ticket was paid (TicketService::getTripEarnings).
 */
class TicketExpiryService
{
    public const PASSED_MESSAGE = 'This ticket has expired. The bus has already reached or passed the passenger\'s destination.';

    public function __construct(
        private TicketRepository $tickets,
        private TripRepository $trips,
        private TripProgressService $progress,
    ) {}

    /** The trip (leg) ended: every ticket of it that was never scanned is expired. Returns how many. */
    public function expireForEndedTrip(int $tripId): int
    {
        return $this->tickets->expireValidForTrip($tripId);
    }

    /** True when the bus has reached or gone past this ticket's destination on its running trip. */
    public function destinationPassed(Ticket $ticket, Trip $trip): bool
    {
        return (int) $ticket->trip_id === (int) $trip->trip_id && $this->progress->destinationReached($trip, $ticket);
    }

    /** Scan time: expire this one ticket when its destination is behind the bus. Returns true when it was expired. */
    public function expireIfPassed(Ticket $ticket, Trip $trip): bool
    {
        if ($ticket->status !== 'valid' || ! $this->destinationPassed($ticket, $trip)) {
            return false;
        }

        return $this->tickets->expireValid([(int) $ticket->ticket_id]) > 0;
    }

    /** One running trip: expire its unscanned tickets whose destination is behind the bus. Returns how many. */
    public function expirePassedForTrip(Trip $trip): int
    {
        if (! TripProgressService::isRunning($trip->status)) {
            return 0;
        }
        $ids = $this->tickets->findValidForTrip((int) $trip->trip_id)
            ->filter(fn (Ticket $ticket) => $this->progress->destinationReached($trip, $ticket))
            ->map(fn (Ticket $ticket) => (int) $ticket->ticket_id)
            ->values()
            ->all();

        return $ids === [] ? 0 : $this->tickets->expireValid($ids);
    }

    /** The scheduler's sweep (tickets:expire-passed, every minute): all running trips that still have unscanned tickets. */
    public function expirePassedOnRunningTrips(): int
    {
        $expired = 0;
        foreach ($this->trips->findRunningWithValidTickets() as $trip) {
            $expired += $this->expirePassedForTrip($trip);
        }

        return $expired;
    }
}
