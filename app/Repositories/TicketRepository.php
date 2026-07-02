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
        return Ticket::with(['trip', 'fare', 'payment'])->where('ticket_uuid', $uuid)->first();
    }

    public function findByPassenger(int $passengerId): Collection
    {
        return Ticket::with(['trip.fleetRoute.route', 'fare'])
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
}
