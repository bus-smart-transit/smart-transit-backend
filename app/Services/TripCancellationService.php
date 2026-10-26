<?php

namespace App\Services;

use App\Jobs\RefundCancelledTripTickets;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\Trip;
use App\Models\TripCancellation;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteStopRepository;
use App\Support\LegDistance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Batch 28 Stage 3, item 6: what happens to the tickets when an OPERATOR cancels a trip.
 *
 * Every paid ticket on the trip that was not used up (status valid, or boarded on a trip cancelled mid-route) is
 * marked `trip_cancelled`, which means "awaiting the passenger's choice" (rebook or refund; see
 * TicketResolutionService). Nothing is refunded here and nothing is refunded automatically. Tickets already used
 * (alighted), expired or cancelled are left alone. The passenger is told by push and in the app.
 *
 * For a trip cancelled after it departed, the point the bus had reached is worked out once, here, and stored on the
 * cancellation, so every ridden-portion price uses the same point:
 *   - the last stop the driver acknowledged, or
 *   - the last accepted GPS position matched to the route (on the route, and not older than
 *     config('refunds.last_point.max_gps_age_seconds')),
 * whichever was recorded LATER. With neither, the point is unknown and nothing is guessed: each boarded ticket is
 * flagged for operator review (TicketResolutionService).
 *
 * Idempotent: a trip is recorded once; calling again returns the same row and does not touch tickets twice.
 */
class TripCancellationService
{
    public const AWAITING_CHOICE = 'trip_cancelled';

    public function __construct(
        private RouteStopRepository $routeStopRepository,
        private FleetLocationRepository $fleetLocationRepository,
        private CustomDropoffService $customDropoffService,
        private FcmService $fcmService,
    ) {}

    /**
     * @param  string  $previousStatus  the trip's status BEFORE it became cancelled (decides the stage)
     * @param  object|null  $actor  the signed-in user doing it (operator or admin), null for a system action
     */
    public function record(Trip $trip, string $previousStatus, ?object $actor = null, ?string $reason = null): TripCancellation
    {
        $existing = TripCancellation::where('trip_id', $trip->trip_id)->first();
        if ($existing) {
            return $existing;
        }

        $midRoute = in_array($previousStatus, ['departed', 'in-progress'], true);
        $lastPoint = $midRoute ? $this->resolveLastPoint($trip) : null;

        $cancellation = DB::transaction(function () use ($trip, $midRoute, $lastPoint, $actor, $reason) {
            $cancellation = TripCancellation::create([
                'trip_id' => $trip->trip_id,
                'cancelled_by_user_id' => $actor?->user_id ?? null,
                'actor_role' => $actor ? (string) ($actor->role ?? '') : 'system',
                'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'stage' => $midRoute ? TripCancellation::STAGE_MID_ROUTE : TripCancellation::STAGE_BEFORE_DEPARTURE,
                'cancelled_at' => Carbon::now('UTC'),
                'last_point_distance_m' => $lastPoint['distance_m'] ?? null,
                'last_point_source' => $lastPoint['source'] ?? null,
                'last_point_stop_id' => $lastPoint['stop_id'] ?? null,
                'last_point_at' => $lastPoint['at'] ?? null,
            ]);

            $this->markTickets($cancellation, $trip);

            return $cancellation;
        });

        AuditLogger::log('trip.cancelled_tickets_flagged', 'Trip', (int) $trip->trip_id, [
            'stage' => $cancellation->stage,
            'last_point_source' => $cancellation->last_point_source,
            'last_point_distance_m' => $cancellation->last_point_distance_m,
        ]);

        $this->notifyPassengers($trip, $cancellation);
        // 2026-10-26: online tickets are refunded at once (cash tickets keep the choice). After the commit, in the queue,
        // so the Operator's cancel answers at once however many gateway refunds follow.
        if (self::autoRefundsOnline()) {
            RefundCancelledTripTickets::dispatch((int) $trip->trip_id)->afterCommit();
        }

        return $cancellation;
    }

    /**
     * A payment that was still pending when the trip was cancelled can be confirmed late by the webhook. Its tickets
     * are created for a cancelled trip; they must not be valid, so they join the others awaiting a choice.
     */
    public function flagTicketsOfCancelledTrips(Payment $payment): void
    {
        $tickets = Ticket::where('payment_id', $payment->payment_id)->where('status', 'valid')->get();
        foreach ($tickets->groupBy('trip_id') as $tripId => $group) {
            $cancellation = TripCancellation::where('trip_id', $tripId)->first();
            if (! $cancellation) {
                continue;
            }
            Ticket::whereIn('ticket_id', $group->pluck('ticket_id'))->update(['status' => self::AWAITING_CHOICE]);
            foreach ($group as $ticket) {
                AuditLogger::log('ticket.trip_cancelled_awaiting_choice', 'Ticket', (int) $ticket->ticket_id, ['trip_id' => (int) $tripId, 'late_payment' => true]);
            }
            $trip = Trip::with('fleetRoute.route')->find($tripId);
            if ($trip) {
                $this->notifyPassengers($trip, $cancellation, $group->pluck('ticket_id')->all());
            }
            // A late online payment for a cancelled trip is refunded like the others.
            if (self::autoRefundsOnline()) {
                RefundCancelledTripTickets::dispatch((int) $tripId, $group->pluck('ticket_id')->map(fn ($id) => (int) $id)->all())->afterCommit();
            }
        }
    }

    /** config refunds.auto_refund_online_on_cancel (default on): online tickets of a cancelled trip are refunded at once. */
    public static function autoRefundsOnline(): bool
    {
        return (bool) config('refunds.auto_refund_online_on_cancel', true);
    }

    /** Paid, unused tickets become "trip cancelled, awaiting choice". */
    private function markTickets(TripCancellation $cancellation, Trip $trip): void
    {
        $tickets = Ticket::where('trip_id', $trip->trip_id)
            ->whereIn('status', ['valid', 'boarded'])
            ->whereHas('payment', fn ($q) => $q->where('status', 'paid')->where('is_valid', true))
            ->get();

        if ($tickets->isEmpty()) {
            return;
        }

        Ticket::whereIn('ticket_id', $tickets->pluck('ticket_id'))->update(['status' => self::AWAITING_CHOICE]);

        foreach ($tickets as $ticket) {
            AuditLogger::log('ticket.trip_cancelled_awaiting_choice', 'Ticket', (int) $ticket->ticket_id, [
                'trip_id' => (int) $trip->trip_id,
                'was' => $ticket->status,
                'stage' => $cancellation->stage,
            ]);
        }
    }

    /**
     * The point (metres from the route's first stop) the bus is known to have reached, or null when unknown.
     *
     * @return array{distance_m: int, source: string, stop_id: int|null, at: Carbon}|null
     */
    public function resolveLastPoint(Trip $trip): ?array
    {
        $trip->loadMissing('fleetRoute');
        $routeId = (int) $trip->fleetRoute->route_id;
        $fleetId = (int) $trip->fleetRoute->fleet_id;
        $direction = LegDistance::direction($trip->leg_direction);
        $candidates = [];

        if ($trip->last_acknowledged_stop_id && $trip->last_acknowledged_at) {
            $routeStop = $this->routeStopRepository->findByRouteAndStop($routeId, (int) $trip->last_acknowledged_stop_id);
            if ($routeStop) {
                $candidates[] = [
                    'distance_m' => $routeStop->distance_from_origin_m !== null
                        ? (int) $routeStop->distance_from_origin_m
                        : LegDistance::toMeters((float) $routeStop->distance_from_origin_km),
                    'source' => 'stop_ack',
                    'stop_id' => (int) $trip->last_acknowledged_stop_id,
                    'at' => Carbon::parse($trip->last_acknowledged_at, config('app.timezone'))->utc(),
                ];
            }
        }

        $fix = $this->fleetLocationRepository->getCurrentForTrip($fleetId, (int) $trip->trip_id);
        if ($fix && (int) $fix['age_seconds'] <= (int) config('refunds.last_point.max_gps_age_seconds')) {
            $located = $this->customDropoffService->locate($routeId, $direction, (float) $fix['latitude'], (float) $fix['longitude']);
            if ($located && ($located['snap']['on_route'] ?? false)) {
                $candidates[] = [
                    'distance_m' => (int) round($located['km_from_origin'] * 1000),
                    'source' => 'gps',
                    'stop_id' => null,
                    'at' => Carbon::now('UTC')->subSeconds((int) $fix['age_seconds']),
                ];
            }
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return $candidates[0];
    }

    private function notifyPassengers(Trip $trip, TripCancellation $cancellation, ?array $onlyTicketIds = null): void
    {
        $trip->loadMissing('fleetRoute.route');
        $routeName = $trip->fleetRoute?->route?->route_name ?? 'Your trip';

        // With automatic refunds on, only cash tickets still choose rebook or refund; online tickets are refunded and
        // emailed (RefundCancelledTripTickets).
        $query = Ticket::with('passenger.user')
            ->where('trip_id', $trip->trip_id)
            ->where('status', self::AWAITING_CHOICE)
            ->whereNotNull('passenger_id')
            ->when(self::autoRefundsOnline(), fn ($q) => $q->whereHas('payment', fn ($p) => $p->where('payment_method', '!=', 'online')));
        if ($onlyTicketIds !== null) {
            $query->whereIn('ticket_id', $onlyTicketIds);
        }

        foreach ($query->get()->groupBy('passenger_id') as $group) {
            $user = $group->first()->passenger?->user;
            if (! $user) {
                continue;
            }

            $count = $group->count();
            $this->fcmService->sendToUser(
                $user,
                'Trip Cancelled',
                "{$routeName} on {$trip->trip_date->toDateString()} was cancelled by the operator. Open "
                .($count === 1 ? 'your ticket' : "your {$count} tickets").' to rebook on a later departure or ask for a refund.',
                ['type' => 'trip_cancelled_choice', 'trip_id' => (int) $trip->trip_id, 'trip_cancellation_id' => (int) $cancellation->id],
            );
        }
    }
}
