<?php
namespace App\Services;
use App\Models\Ticket;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\FareRuleRepository;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private TripRepository $tripRepository,
        private RouteStopRepository $routeStopRepository,
        private FareRuleRepository $fareRuleRepository,
        private FareRuleService $fareRuleService,
        private FareCalculationService $fareCalculationService,
        private TripService $tripService,
        private RewardService $rewardService,
    ) {
    }

    /**
     * $item is one of two shapes:
     *   stop-based:   ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     *   custom-point: ['trip_id','seat_type','origin_lat','origin_lng','destination_lat','destination_lng']
     */
    public function reserveAndPrice(array $item): array
    {
        $trip = $this->tripRepository->findById($item['trip_id']);

        if (!$trip || !in_array($trip->status, ['scheduled', 'boarding'])) {
            throw ValidationException::withMessages([
                'trip' => ['This trip is not accepting tickets.'],
            ]);
        }

        if (isset($item['origin_stop_id'], $item['destination_stop_id'])) {
            $reserved = $this->priceByStops($trip, $item);
        } elseif (isset($item['origin_lat'], $item['origin_lng'], $item['destination_lat'], $item['destination_lng'])) {
            $reserved = $this->priceByCoordinates($trip, $item);
        } else {
            throw ValidationException::withMessages([
                'item' => ['Provide either origin_stop_id/destination_stop_id or origin_lat/lng + destination_lat/lng.'],
            ]);
        }

        // Reserve seat/standing capacity now — throws if full. Same hold
        // semantics regardless of which pricing path was used.
        $this->tripService->recordBoarding($trip->trip_id, $item['seat_type']);

        return $reserved;
    }

    /**
     * Reserves one more seat/standing slot using a precomputed pricing
     * template from a previous reserveAndPrice() call for identical items.
     */
    public function reserveFromTemplate(array $reservedTemplate, int $quantity = 1): array
    {
        if (!isset($reservedTemplate['trip_id'], $reservedTemplate['seat_type'])) {
            throw new \RuntimeException('Invalid reservation template.');
        }

        $this->tripService->recordBoardingMultiple(
            (int) $reservedTemplate['trip_id'],
            (string) $reservedTemplate['seat_type'],
            max(1, $quantity),
        );

        return $reservedTemplate;
    }

    /**
     * Computed on the fly — no fare_matrix table. The distance between two
     * known stops on a known route is exact (no interpolation needed), so
     * this is just a subtraction plus the shared fare formula.
     */
    private function priceByStops(object $trip, array $item): array
    {
        $originRouteStop = $this->routeStopRepository->findByRouteAndStop(
            $trip->fleetRoute->route_id,
            $item['origin_stop_id'],
        );
        $destinationRouteStop = $this->routeStopRepository->findByRouteAndStop(
            $trip->fleetRoute->route_id,
            $item['destination_stop_id'],
        );

        if (!$originRouteStop || !$destinationRouteStop) {
            throw ValidationException::withMessages([
                'stop' => ['One or both stops are not on this trip\'s route.'],
            ]);
        }

        $distanceKm = abs($destinationRouteStop->distance_from_origin_km - $originRouteStop->distance_from_origin_km);

        $rule = $this->fareRuleRepository->getActiveRule($trip->fleetRoute->fleet_id, $item['seat_type'])
            ?? $this->fareRuleRepository->getActiveRuleForRoute($trip->fleetRoute->route_id, $item['seat_type']);

        if (!$rule) {
            throw new \RuntimeException('No fare configured for this fleet and seat type.');
        }

        return [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'seat_type' => $item['seat_type'],
            'fare_rule_id' => $rule->fare_rule_id,
            'distance_km' => $distanceKm,
            'amount' => $this->fareCalculationService->computeFare($rule->base_fare, $rule->fare_per_km, $distanceKm),
            'origin_stop_id' => $item['origin_stop_id'],
            'destination_stop_id' => $item['destination_stop_id'],
        ];
    }

    /**
     * Re-derives price server-side from the trip's actual fleet + route via
     * GPS interpolation — never trusts a client-supplied amount from an
     * earlier browse-time quote.
     */
    private function priceByCoordinates(object $trip, array $item): array
    {
        $quote = $this->fareRuleService->getQuoteForTripFromCoordinates(
            $trip->fleetRoute->fleet_id,
            $trip->fleetRoute->route_id,
            [
                'origin_lat' => $item['origin_lat'],
                'origin_lng' => $item['origin_lng'],
                'destination_lat' => $item['destination_lat'],
                'destination_lng' => $item['destination_lng'],
                'seat_type' => $item['seat_type'],
            ]
        );

        return [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id'        => $trip->trip_id,
            'seat_type'      => $item['seat_type'],
            'fare_rule_id'   => $quote['fare_rule_id'],
            'distance_km'    => $quote['distance_km'],
            'amount'         => $quote['amount'],
            // Preserve custom GPS drop-off so the ticket can display where the
            // passenger actually intends to alight (not just the route terminal).
            'origin_lat'      => (float) $item['origin_lat'],
            'origin_lng'      => (float) $item['origin_lng'],
            'destination_lat' => (float) $item['destination_lat'],
            'destination_lng' => (float) $item['destination_lng'],
        ];
    }

    /**
     * Creates the actual ticket row for a previously reserved item. Called
     * only once payment is confirmed (online) or immediately (onsite/cash).
     * Does NOT call recordBoarding() again — capacity was already reserved
     * in reserveAndPrice().
     */
    public function finalizeTicket(array $reserved, ?int $passengerId, int $paymentId): Ticket
    {
        $ticket = $this->ticketRepository->create([
            'fleet_route_id' => $reserved['fleet_route_id'],
            'trip_id' => $reserved['trip_id'],
            'fare_rule_id' => $reserved['fare_rule_id'],
            'distance_km' => $reserved['distance_km'],
            'payment_id' => $paymentId,
            'passenger_id' => $passengerId,
            'status' => 'valid',
            'amount' => $reserved['amount'],
            'seat_type' => $reserved['seat_type'],
            'origin_stop_id'      => $reserved['origin_stop_id']      ?? null,
            'destination_stop_id' => $reserved['destination_stop_id'] ?? null,
            'origin_lat'          => $reserved['origin_lat']          ?? null,
            'origin_lng'          => $reserved['origin_lng']          ?? null,
            'destination_lat'     => $reserved['destination_lat']     ?? null,
            'destination_lng'     => $reserved['destination_lng']     ?? null,
        ]);

        if ($passengerId) {
            $earnableAmount = isset($reserved['reward_earn_amount'])
                ? (float) $reserved['reward_earn_amount']
                : (float) $reserved['amount'];

            $this->rewardService->awardPoints($passengerId, $earnableAmount, $paymentId);
        }

        return $ticket;
    }

    /**
     * Releases previously held capacity for an item that never became a
     * ticket (payment failed, or its hold expired).
     */
    public function releaseTicketHold(array $reserved): void
    {
        $this->tripService->releaseBoarding($reserved['trip_id'], $reserved['seat_type']);
    }

    /**
     * Combines reserveAndPrice() + finalizeTicket() into a single call.
     * Used by the onsite flow, where cash payment is confirmed instantly.
     */
    public function issueTicket(array $item, ?int $passengerId, int $paymentId): Ticket
    {
        return DB::transaction(function () use ($item, $passengerId, $paymentId) {
            $reserved = $this->reserveAndPrice($item);
            return $this->finalizeTicket($reserved, $passengerId, $paymentId);
        });
    }

    // $payload = ['ticket_uuid']
    // $conductorCompanyUserId — when provided, the ticket must belong to the
    // conductor's currently active trip (prevents scanning tickets for wrong
    // or future trips even if the date check somehow passes).
    public function validateScan(array $payload, ?int $conductorCompanyUserId = null): object
    {
        $ticket = $this->ticketRepository->findByUuid($payload['ticket_uuid']);

        if (!$ticket) {
            throw ValidationException::withMessages(['ticket' => ['Ticket not found.']]);
        }

        if ($ticket->status !== 'valid') {
            throw ValidationException::withMessages([
                'ticket' => ["Ticket is already {$ticket->status}."],
            ]);
        }

        if (!$ticket->payment || $ticket->payment->status !== 'paid' || !$ticket->payment->is_valid) {
            throw ValidationException::withMessages([
                'ticket' => ['Payment for this ticket has not been completed yet.'],
            ]);
        }

        // ── Trip ownership check ────────────────────────────────────────────
        // Verify the ticket belongs to the conductor's currently active trip.
        // This is the primary guard against scanning future-trip tickets:
        // a conductor can only board passengers onto the trip they are
        // currently running, regardless of the ticket's date.
        if ($conductorCompanyUserId !== null) {
            $activeTrip = $this->tripService->getCurrentTripForConductor($conductorCompanyUserId);

            if (!$activeTrip) {
                throw ValidationException::withMessages([
                    'ticket' => ['No active trip found. You must have an active trip to scan tickets.'],
                ]);
            }

            if ((int) $ticket->trip_id !== (int) $activeTrip->trip_id) {
                throw ValidationException::withMessages([
                    'ticket' => ['This ticket is for a different trip and cannot be scanned on your current trip.'],
                ]);
            }
        }

        // ── Date check (secondary guard, uses string comparison for reliability) ─
        // Avoid Carbon method chaining which can silently fail when $trip is null.
        // String comparison works correctly for ISO date strings (YYYY-MM-DD).
        $tripDateStr = $ticket->trip?->trip_date?->toDateString();
        $todayStr    = now()->toDateString();

        if ($tripDateStr === null) {
            throw ValidationException::withMessages([
                'ticket' => ['Ticket does not have a valid trip date and cannot be boarded.'],
            ]);
        }

        if ($tripDateStr > $todayStr) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is not yet active. It can only be used on the scheduled trip date (' . $tripDateStr . ').'],
            ]);
        }

        if ($tripDateStr < $todayStr) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket has expired. Tickets are only valid on their scheduled trip date (' . $tripDateStr . ').'],
            ]);
        }

        $this->ticketRepository->markBoarded($ticket->ticket_id);
        $boardedTicket = $this->ticketRepository->findByUuid($payload['ticket_uuid']);

        // Destination label — prefer a named stop; fall back to GPS coords for
        // custom-pinpoint tickets so the conductor sees WHERE the passenger alights.
        $destinationLabel = $boardedTicket->destinationStop?->stop_name
            ?? ($boardedTicket->destination_lat !== null
                ? 'GPS: ' . round((float) $boardedTicket->destination_lat, 5) . ', ' . round((float) $boardedTicket->destination_lng, 5)
                : null);

        $originLabel = $boardedTicket->originStop?->stop_name
            ?? ($boardedTicket->origin_lat !== null
                ? 'GPS: ' . round((float) $boardedTicket->origin_lat, 5) . ', ' . round((float) $boardedTicket->origin_lng, 5)
                : null);

        return (object) [
            'ticket_id'       => $boardedTicket->ticket_id,
            'ticket_uuid'     => $boardedTicket->ticket_uuid,
            'passenger_name'  => $boardedTicket->passenger?->user?->name ?? 'Guest',
            'origin'          => $originLabel,
            'destination'     => $destinationLabel,
            'destination_lat' => $boardedTicket->destination_lat,
            'destination_lng' => $boardedTicket->destination_lng,
            'seat_type'       => $boardedTicket->seat_type,
            'amount'          => (float) $boardedTicket->amount,
            'status'          => $boardedTicket->status,
            'boarded_at'      => $boardedTicket->boarded_at,
        ];
    }

    public function getPassengerTickets(int $passengerId): object
    {
        return $this->ticketRepository
            ->findByPassenger($passengerId)
            ->map(fn (Ticket $ticket) => $this->appendValidityWindow($ticket));
    }

    /**
     * Retrieve a ticket by UUID for ownership/authorization checks.
     * Throws ModelNotFoundException (auto-resolved as 404) if not found.
     */
    public function getTicketByUuidOrFail(string $uuid): Ticket
    {
        $ticket = $this->ticketRepository->findByUuid($uuid);

        if (!$ticket) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Ticket::class);
        }

        return $ticket;
    }

    /**
     * Trip earnings summary — total fare collected, broken down by payment method.
     * Computed server-side from verified payment/ticket records only.
     */
    public function getTripEarnings(int $tripId): array
    {
        $tickets = Ticket::with('payment')
            ->where('trip_id', $tripId)
            ->whereIn('status', ['boarded', 'alighted'])
            ->get();

        $totalFare    = 0.0;
        $onsiteAmount = 0.0;
        $onlineAmount = 0.0;
        $passengerCount = $tickets->count();

        foreach ($tickets as $ticket) {
            $amount = (float) ($ticket->amount ?? 0);
            $totalFare += $amount;
            $method = $ticket->payment?->payment_method ?? 'unknown';
            if ($method === 'cash' || $method === 'onsite') {
                $onsiteAmount += $amount;
            } else {
                $onlineAmount += $amount;
            }
        }

        return [
            'trip_id'        => $tripId,
            'total_fare'     => round($totalFare, 2),
            'onsite_amount'  => round($onsiteAmount, 2),
            'online_amount'  => round($onlineAmount, 2),
            'passenger_count'=> $passengerCount,
            'average_fare'   => $passengerCount > 0 ? round($totalFare / $passengerCount, 2) : 0.0,
        ];
    }


    public function scanGroup(array $payload, ?int $conductorCompanyUserId = null): array
    {
        $tickets = $this->ticketRepository->findByTransactionRef($payload['transaction_reference']);

        if ($tickets->isEmpty()) {
            throw ValidationException::withMessages([
                'transaction_reference' => ['No tickets found for this transaction.'],
            ]);
        }

        // Resolve conductor's active trip once for the whole group scan.
        $activeTripId = null;
        if ($conductorCompanyUserId !== null) {
            $activeTrip   = $this->tripService->getCurrentTripForConductor($conductorCompanyUserId);
            $activeTripId = $activeTrip?->trip_id;

            if (!$activeTripId) {
                throw ValidationException::withMessages([
                    'transaction_reference' => ['No active trip found. You must have an active trip to scan group tickets.'],
                ]);
            }
        }

        $todayStr     = now()->toDateString();
        $boardedCount = 0;
        $results      = [];

        foreach ($tickets as $ticket) {
            $base = [
                'ticket_uuid'    => $ticket->ticket_uuid,
                'passenger_name' => $ticket->passenger?->user?->name ?? 'Guest',
                'destination'    => $ticket->destinationStop?->stop_name,
                'seat_type'      => $ticket->seat_type,
                'amount'         => (float) $ticket->amount,
            ];

            if ($ticket->status !== 'valid') {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => "Already {$ticket->status}"]);
                continue;
            }

            if (!$ticket->payment || $ticket->payment->status !== 'paid' || !$ticket->payment->is_valid) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'Payment not completed']);
                continue;
            }

            // Trip ownership check — same logic as single-ticket scan.
            if ($activeTripId !== null && (int) $ticket->trip_id !== (int) $activeTripId) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'Ticket is for a different trip']);
                continue;
            }

            // Date check — string comparison, avoids Carbon null/timezone issues.
            $tripDateStr = $ticket->trip?->trip_date?->toDateString();

            if ($tripDateStr === null) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'No trip date on ticket']);
                continue;
            }

            if ($tripDateStr > $todayStr) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'Ticket not yet active (scheduled for ' . $tripDateStr . ')']);
                continue;
            }

            if ($tripDateStr < $todayStr) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'Ticket expired (was for ' . $tripDateStr . ')']);
                continue;
            }

            $this->ticketRepository->markBoarded($ticket->ticket_id);
            $boardedCount++;
            $results[] = array_merge($base, ['status' => 'boarded', 'skipped' => false]);
        }

        return [
            'transaction_reference' => $payload['transaction_reference'],
            'total_tickets'         => count($results),
            'boarded_count'         => $boardedCount,
            'tickets'               => $results,
        ];
    }

    public function findByTransactionAndEmail(string $transactionReference, ?string $email = null, ?int $paymentId = null): object
    {
        return $this->ticketRepository
            ->findByTransactionAndEmail($transactionReference, $email, $paymentId)
            ->map(fn (Ticket $ticket) => $this->appendValidityWindow($ticket));
    }

    private function appendValidityWindow(Ticket $ticket): Ticket
    {
        $tripDate = $ticket->trip?->trip_date;
        if ($tripDate) {
            $tz          = config('app.timezone', 'UTC');
            $dateStr     = $tripDate->toDateString(); // "YYYY-MM-DD"

            // valid_from — use the fleet route's scheduled departure time so
            // passengers see the actual boarding time, not midnight UTC which
            // renders incorrectly when the browser converts to local time.
            $startTime   = $ticket->trip?->fleetRoute?->start_time ?? '00:00:00';
            $validFrom   = \Illuminate\Support\Carbon::parse($dateStr . ' ' . $startTime, $tz);

            // expires_at — end of the trip day in the app's local timezone.
            // A ticket is only usable on its scheduled trip date (23:59:59 local).
            $expiresAt   = \Illuminate\Support\Carbon::parse($dateStr . ' 23:59:59', $tz);

            // Mark expired and persist BEFORE setting virtual attributes,
            // so that valid_from / expires_at (which are not real DB columns)
            // are never included in the UPDATE statement.
            if ($ticket->status === 'valid' && now()->gt($expiresAt)) {
                $ticket->status = 'expired';
                $ticket->save();
            }

            $ticket->setAttribute('valid_from', $validFrom->toIso8601String());
            $ticket->setAttribute('expires_at', $expiresAt->toIso8601String());
        } else {
            $ticket->setAttribute('valid_from', null);
            $ticket->setAttribute('expires_at', null);
        }

        // Sync so these virtual attributes are never treated as dirty
        // if something calls save() again downstream.
        $ticket->syncOriginal();

        return $ticket;
    }
}