<?php

namespace App\Repositories;

use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TicketRepository
{
    public function create(array $payload): Ticket
    {
        $payload['ticket_uuid'] = $payload['ticket_uuid'] ?? (string) Str::uuid();

        return Ticket::create($payload);
    }

    public function findByUuid(string $uuid): ?Ticket
    {
        return Ticket::with(['trip.fleetRoute.route', 'fareRule', 'payment', 'originStop', 'destinationStop', 'passenger.user'])
            ->where('ticket_uuid', $uuid)
            ->first();
    }

    public function findByPassenger(int $passengerId): Collection
    {
        return Ticket::with(['trip.fleetRoute.route', 'trip.fleetRoute.fleet', 'fareRule', 'originStop', 'destinationStop', 'payment'])
            ->where('passenger_id', $passengerId)
            ->latest()
            ->get();
    }

    public function findByPayment(int $paymentId): Collection
    {
        return Ticket::where('payment_id', $paymentId)->get();
    }

    public function findById(int $ticketId): ?Ticket
    {
        return Ticket::find($ticketId);
    }

    /**
     * Tickets on a trip that are still "in play" (not cancelled/expired)
     * and are backed by a verified paid payment — used for trip earnings
     * summaries. Moved here from a query built directly in TicketService.
     */
    public function findPaidTicketsWithPaymentForTrip(int $tripId): Collection
    {
        return Ticket::with('payment')
            ->where('trip_id', $tripId)
            ->whereIn('status', ['valid', 'boarded', 'alighted'])
            ->whereHas('payment', function ($query) {
                $query->where('status', 'paid')
                    ->where('is_valid', true);
            })
            ->get();
    }

    /**
     * Guarded by `where('status', 'boarded')` so a duplicate/racing call
     * can't re-stamp `alighted_at` on an already-alighted ticket.
     */
    public function markAlighted(int $ticketId): bool
    {
        return Ticket::where('ticket_id', $ticketId)
            ->where('status', 'boarded')
            ->update(['status' => 'alighted', 'alighted_at' => now()]) > 0;
    }

    /**
     * Registered (non-guest) passengers holding a still-active ticket for
     * a trip — used to notify affected passengers on trip cancellation/
     * auto-delay. Guest-checkout tickets (passenger_id null) are excluded
     * since there's no account to push a notification to.
     */
    public function findActivePassengersForTrip(int $tripId): Collection
    {
        return Ticket::with('passenger.user')
            ->where('trip_id', $tripId)
            ->whereNotNull('passenger_id')
            ->whereIn('status', ['valid', 'boarded'])
            ->get()
            ->pluck('passenger')
            ->filter()
            ->unique('passenger_id')
            ->values();
    }

    public function markBoarded(int $ticketId): bool
    {
        return Ticket::where('ticket_id', $ticketId)->update([
            'status' => 'boarded',
            'boarded_at' => now(),
        ]) > 0;
    }

    public function findByTransactionRef(string $transactionRef): Collection
    {
        return Ticket::with(['trip', 'payment', 'originStop', 'destinationStop', 'passenger.user'])
            ->whereHas('payment', fn ($q) => $q->where('transaction_reference', $transactionRef))
            ->get();
    }

    public function findByTransactionAndEmail(string $transactionReference, ?string $email = null, ?int $paymentId = null): Collection
    {
        return Ticket::with(['payment', 'originStop', 'destinationStop', 'trip.fleetRoute.route'])
            ->whereHas('payment', function ($q) use ($transactionReference, $email, $paymentId) {
                $q->where('transaction_reference', $transactionReference);

                if ($email !== null && $email !== '') {
                    $q->where('guest_email', $email);
                } else {
                    // No email supplied: only payments with no stored guest email can match,
                    // so a reference alone never opens a payment made with an email.
                    $q->whereNull('guest_email');
                }

                if ($paymentId !== null) {
                    $q->where('payment_id', $paymentId);
                }
            })
            ->get();
    }

    /**
     * Get all boarded tickets for a trip with destination stop info
     */
    public function getboardedTicketsWithDestination(int $tripId): Collection
    {
        return Ticket::with(['passenger.user', 'destinationStop', 'originStop'])
            ->where('trip_id', $tripId)
            ->where('status', 'boarded')
            ->get();
    }

    /**
     * Get passengers alighting at a specific stop on a trip
     */
    public function getPassengersAlightingAtStop(int $tripId, int $stopId): Collection
    {
        return Ticket::with(['passenger.user', 'originStop', 'destinationStop'])
            ->where('trip_id', $tripId)
            ->where('destination_stop_id', $stopId)
            ->where('status', 'boarded')
            ->get();
    }

    /**
     * Count boarded tickets of a specific seat type for a trip
     */
    public function countBoardedByType(int $tripId, string $seatType): int
    {
        return Ticket::where('trip_id', $tripId)
            ->where('seat_type', $seatType)
            ->where('status', 'boarded')
            ->count();
    }

    /**
     * Count passengers boarding at a specific stop
     */
    public function countBoardingAtStop(int $tripId, int $stopId): int
    {
        return Ticket::where('trip_id', $tripId)
            ->where('origin_stop_id', $stopId)
            ->whereIn('status', ['boarded', 'alighted'])
            ->count();
    }

    /**
     * Count passengers alighting at a specific stop
     */
    public function countAlightingAtStop(int $tripId, int $stopId): int
    {
        return Ticket::where('trip_id', $tripId)
            ->where('destination_stop_id', $stopId)
            ->whereIn('status', ['boarded', 'alighted'])
            ->count();
    }

    /**
     * Aggregate boarding/alighting counts per stop for a trip in 2 queries
     * instead of 2×N per-stop queries. Moved here from raw DB::table()
     * calls in OccupancyService::getOccupancyByStop().
     * Returns ['boarding' => [stop_id => count], 'alighting' => [stop_id => count]].
     */
    public function getBoardingAndAlightingCountsByStop(int $tripId): array
    {
        $boarding = Ticket::where('trip_id', $tripId)
            ->whereIn('status', ['boarded', 'alighted'])
            ->whereNotNull('origin_stop_id')
            ->selectRaw('origin_stop_id AS stop_id, COUNT(*) AS cnt')
            ->groupBy('origin_stop_id')
            ->pluck('cnt', 'stop_id')
            ->map(fn ($v) => (int) $v);

        $alighting = Ticket::where('trip_id', $tripId)
            ->whereIn('status', ['boarded', 'alighted'])
            ->whereNotNull('destination_stop_id')
            ->selectRaw('destination_stop_id AS stop_id, COUNT(*) AS cnt')
            ->groupBy('destination_stop_id')
            ->pluck('cnt', 'stop_id')
            ->map(fn ($v) => (int) $v);

        return ['boarding' => $boarding, 'alighting' => $alighting];
    }

    /**
     * Count cumulative passengers on bus after a specific stop
     */
    public function countPassengersAfterStop(int $tripId, int $stopId): int
    {
        return Ticket::where('trip_id', $tripId)
            ->where('status', 'boarded')
            ->get()
            ->filter(function ($ticket) use ($stopId) {
                // Passenger is on bus if they've boarded and haven't reached destination
                $boardedAtOrBefore = ($ticket->originStop?->stop_id ?? 0) <= $stopId;
                $destinationAfter = ($ticket->destinationStop?->stop_id ?? 999) > $stopId;

                return $boardedAtOrBefore && $destinationAfter;
            })
            ->count();
    }

    /**
     * D5: passengers who asked to get off at a custom point (no destination stop
     * recorded), still on their way. The bus needs to know to stop there.
     */
    public function getRequestedStopsForTrip(int $tripId): Collection
    {
        return Ticket::where('trip_id', $tripId)
            ->whereIn('status', ['valid', 'boarded'])
            ->whereNull('alighted_at')
            ->whereNull('destination_stop_id')
            ->whereNotNull('destination_lat')
            ->whereNotNull('destination_lng')
            ->get(['ticket_id', 'destination_lat', 'destination_lng', 'destination_label']);
    }

    /**
     * Get all boarded passengers with full details
     */
    public function getBoardedPassengersDetails(int $tripId): Collection
    {
        return Ticket::with(['passenger.user', 'originStop', 'destinationStop'])
            ->where('trip_id', $tripId)
            ->where('status', 'boarded')
            ->get();
    }

    /**
     * Get active passengers for a trip (reserved/issued + boarded, not yet alighted).
     */
    public function getActivePassengersDetails(int $tripId): Collection
    {
        return Ticket::with(['passenger.user', 'originStop', 'destinationStop', 'payment'])
            ->where('trip_id', $tripId)
            ->whereIn('status', ['valid', 'boarded'])
            ->whereNull('alighted_at')
            ->orderByRaw("CASE WHEN status = 'boarded' THEN 0 ELSE 1 END")
            ->orderByDesc('boarded_at')
            ->orderByDesc('created_at')
            ->get();
    }
}
