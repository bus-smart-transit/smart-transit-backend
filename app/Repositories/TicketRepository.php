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
        return Ticket::with(['trip.fleetRoute.route', 'fareRule', 'originStop', 'destinationStop', 'payment'])
            ->where('passenger_id', $passengerId)
            ->latest()
            ->get();
    }

    public function findByPayment(int $paymentId): Collection
    {
        return Ticket::where('payment_id', $paymentId)->get();
    }

    public function markBoarded(int $ticketId): bool
    {
        return Ticket::where('ticket_id', $ticketId)->update([
            'status' => 'boarded',
            'boarded_at' => now(),
        ]) > 0;
    }

    public function findByTransactionAndEmail(string $transactionReference, ?string $email = null, ?int $paymentId = null): Collection
    {
        return Ticket::with(['payment', 'originStop', 'destinationStop', 'trip.fleetRoute.route'])
            ->whereHas('payment', function ($q) use ($transactionReference, $email, $paymentId) {
                $q->where('transaction_reference', $transactionReference);

                if ($email !== null && $email !== '') {
                    $q->where('guest_email', $email);
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
            ->whereIn('status', ['issued', 'boarded'])
            ->whereNull('alighted_at')
            ->orderByRaw("CASE WHEN status = 'boarded' THEN 0 ELSE 1 END")
            ->orderByDesc('boarded_at')
            ->orderByDesc('created_at')
            ->get();
    }
}
