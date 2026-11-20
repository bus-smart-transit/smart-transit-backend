<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\Trip;
use App\Repositories\DiscountClaimRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use App\Support\LiveUpdates;

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
 *
 * Batch 76 (user, 2026-11-17): a ticket never scanned when the bus LEAVES its boarding stop is "missed"
 * (TripProgressService::boardingStopPassed: Depart for the first stop, or the Driver tapping a later stop). A missed
 * passenger can no longer board and buys a new ticket; nothing is refunded. The seat goes back on sale, online and on
 * the bus, and a discount claimed on the ticket lapses. Checked when the Driver taps a stop or departs, at every scan,
 * and by the every-minute sweep.
 */
class TicketExpiryService
{
    public const PASSED_MESSAGE = 'This ticket has expired. The bus has already reached or passed the passenger\'s destination.';

    public const MISSED_MESSAGE = 'This ticket was missed. The bus already left the passenger\'s boarding stop, so it cannot be used. Buy a new ticket.';

    public function __construct(
        private TicketRepository $tickets,
        private TripRepository $trips,
        private TripProgressService $progress,
        private DiscountClaimRepository $claims,
    ) {}

    /** True when this unscanned ticket's boarding stop is behind the bus on its running trip. */
    public function boardingStopPassed(Ticket $ticket, Trip $trip): bool
    {
        return (int) $ticket->trip_id === (int) $trip->trip_id
            && $ticket->origin_stop_id !== null
            && $this->progress->boardingStopPassed($trip, (int) $ticket->origin_stop_id);
    }

    /** Scan time: mark this one ticket missed when the bus has left its boarding stop. Returns true when it was. */
    public function missIfPassed(Ticket $ticket, Trip $trip): bool
    {
        if ($ticket->status !== 'valid' || ! $this->boardingStopPassed($ticket, $trip)) {
            return false;
        }

        return $this->markMissed([$ticket]) > 0;
    }

    /** One running trip: mark missed every unscanned ticket whose boarding stop the bus has left. Returns how many. */
    public function markMissedForTrip(Trip $trip): int
    {
        if (! TripProgressService::isRunning($trip->status)) {
            return 0;
        }

        return $this->markMissed($this->tickets->findValidForTrip((int) $trip->trip_id)
            ->filter(fn (Ticket $ticket) => $this->boardingStopPassed($ticket, $trip))
            ->values()
            ->all());
    }

    /**
     * Marks these tickets missed (each only while still unscanned, so a scan in between wins and a seat is never
     * given back twice), gives each one's seat back to the trip and lapses their discount claims.
     *
     * @param  array<int, Ticket>  $tickets
     */
    private function markMissed(array $tickets): int
    {
        $missed = [];
        foreach ($tickets as $ticket) {
            if (! $this->tickets->markMissedIfValid((int) $ticket->ticket_id)) {
                continue;
            }
            $seated = $ticket->seat_type === 'standing' ? 0 : 1;
            $this->trips->decrementOccupancy((int) $ticket->trip_id, $seated, 1 - $seated);
            $missed[] = (int) $ticket->ticket_id;
        }
        $this->claims->lapseOpenForTickets($missed);
        // 2026-11-20: the passengers' open ticket screens show "Missed" at once (live updates).
        if ($missed !== []) {
            LiveUpdates::tickets($this->tickets->passengerUserIds($missed), (int) $tickets[0]->trip_id);
        }

        return count($missed);
    }

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
        // Batch 76: a ticket whose boarding stop is behind the bus is missed first (that also covers its destination).
        $this->markMissedForTrip($trip);
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
