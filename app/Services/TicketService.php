<?php

namespace App\Services;

use App\Models\Ticket;
use App\Exceptions\FareUnavailableException;
use App\Support\BusLabel;
use App\Support\LegDistance;
use App\Support\ServiceType;
use App\Repositories\RouteStopRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private TripRepository $tripRepository,
        private RouteStopRepository $routeStopRepository,
        private FareRuleResolver $fareRuleResolver,
        private FareRuleService $fareRuleService,
        private FareCalculationService $fareCalculationService,
        private TripService $tripService,
        private RewardService $rewardService,
        private ReverseGeocodingService $reverseGeocodingService,
        private CustomDropoffService $customDropoffService,
        private TicketTrackingService $trackingService,
        private MidTripBoardingService $midTripBoardingService,
    ) {}

    /**
     * $item is one of two shapes:
     *   stop-based:   ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     *   custom drop-off: ['trip_id','seat_type','origin_stop_id','destination_lat','destination_lng'(,'destination_label')]
     *   Chauffeur mid-trip boarding (cash only): ['trip_id','seat_type','boarding_mode' => 'gps',
     *       'destination_stop_id' | 'destination_lat'+'destination_lng'(,'destination_label')]. No origin: the server
     *       resolves the boarding point from the bus's own position (never from the request).
     * `channel` => 'onsite' is set by the cash checkout on the server, never read from a request: it is what allows
     * a Chauffeur to sell while the bus is already moving.
     */
    public function reserveAndPrice(array $item): array
    {
        $trip = $this->tripRepository->findById($item['trip_id']);
        $onsite = ($item['channel'] ?? null) === 'onsite';
        $sellable = $onsite
            ? ['scheduled', 'delayed', 'boarding', 'departed', 'in-progress']
            : ['scheduled', 'delayed', 'boarding'];

        if (! $trip || ! in_array($trip->status, $sellable, true)) {
            throw ValidationException::withMessages([
                'trip' => ['This trip is not accepting tickets.'],
            ]);
        }

        $seatType = strtolower((string) ($item['seat_type'] ?? ''));
        $fleetType = strtolower((string) ($trip->fleetRoute->fleet->fleet_type ?? 'public'));
        $tripDate = $trip->trip_date?->toDateString();
        $today = now()->toDateString();

        $bookingDate = isset($item['booking_date']) ? (string) $item['booking_date'] : null;
        if ($bookingDate !== null && $tripDate !== null && $bookingDate !== $tripDate) {
            throw ValidationException::withMessages([
                'trip' => ['This trip is scheduled for '.Carbon::parse($tripDate)->format('m/d/Y').'.'],
            ]);
        }

        if ($fleetType === 'private' && $seatType === 'standing') {
            throw ValidationException::withMessages([
                'seat_type' => ['Private fleets allow seated bookings only.'],
            ]);
        }

        if ($seatType === 'standing' && $tripDate !== null && $tripDate > $today) {
            throw ValidationException::withMessages([
                'seat_type' => ['Standing bookings are available only on the trip date. Please choose seated for advance bookings.'],
            ]);
        }

        if ($onsite && ($item['boarding_mode'] ?? null) === 'gps') {
            $reserved = $this->priceFromBoardingPoint($trip, $item);
        } elseif (isset($item['origin_stop_id'], $item['destination_stop_id'])) {
            $reserved = $this->priceByStops($trip, $item);
        } elseif (isset($item['origin_stop_id'], $item['destination_lat'], $item['destination_lng'])) {
            $reserved = $this->priceByCustomDropoff($trip, $item);
        } else {
            throw ValidationException::withMessages([
                'item' => ['Provide origin_stop_id with either destination_stop_id or a custom drop-off (destination_lat/lng).'],
            ]);
        }

        // Roundtrip: which of the two tickets this is (outbound | return). Null for a one-way ticket.
        $reserved['journey_leg'] = in_array($item['journey_leg'] ?? null, ['outbound', 'return'], true) ? $item['journey_leg'] : null;

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
        if (! isset($reservedTemplate['trip_id'], $reservedTemplate['seat_type'])) {
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
     * this is a subtraction plus the shared fare formula.
     *
     * Batch 27 Part 2 (Tasks 2 and 4): the server no longer trusts the two stop ids.
     * Both must be stopping points for this trip's service (a pass-through is refused), the
     * destination must be AHEAD of the boarding stop in the leg's direction (a backwards or
     * zero-length ticket is a 422), the distance is measured along the leg, and the ticket stores
     * the two points it was priced between.
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

        if (! $originRouteStop || ! $destinationRouteStop) {
            throw ValidationException::withMessages([
                'stop' => ['One or both stops are not on this trip\'s route.'],
            ]);
        }

        $this->assertStopsServed($trip, $originRouteStop, $destinationRouteStop);

        $boardingKm = (float) $originRouteStop->distance_from_origin_km;
        $distanceKm = $this->assertAheadAndMeasure($trip, $boardingKm, $destinationRouteStop);

        // Checked after the stop and direction checks, so a tampered payload is refused for what it is.
        // Batch 28 item 5: no rule for this bus's class and service type (an express trip with no express rule
        // included) means "Fare unavailable": nothing is priced or sold, and nothing is borrowed from another class.
        $rule = $this->ruleFor($trip, $item['seat_type']);

        return [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'seat_type' => $item['seat_type'],
            'fare_rule_id' => $rule->fare_rule_id,
            'rounding_mode' => $rule->rounding_mode,
            'distance_km' => $distanceKm,
            'amount' => $this->fareCalculationService->priceWithRule($rule, $distanceKm),
            'origin_stop_id' => $item['origin_stop_id'],
            'destination_stop_id' => $item['destination_stop_id'],
            'boarding_distance_m' => $this->routeStopMeters($originRouteStop),
            'alighting_distance_m' => $this->routeStopMeters($destinationRouteStop),
        ];
    }

    /** Both stops must be places this trip's bus stops at: pass-through points never are (Task 2). */
    private function assertStopsServed(object $trip, \App\Models\RouteStop $origin, \App\Models\RouteStop $destination): void
    {
        foreach (['origin' => $origin, 'destination' => $destination] as $field => $routeStop) {
            if (! ServiceType::isServed($trip->service_type, $routeStop)) {
                $name = $routeStop->stop?->stop_name ?? 'that point';
                throw ValidationException::withMessages([
                    $field => [ServiceType::isExpress($trip->service_type)
                        ? "This express trip does not stop at {$name}."
                        : "Buses do not stop at {$name}. Choose a stop."],
                ]);
            }
        }
    }

    /**
     * Distance along the leg from the boarding point to a destination stop. A destination behind or at the
     * boarding point (for this leg's direction) is refused (Task 2).
     */
    private function assertAheadAndMeasure(object $trip, float $boardingKm, \App\Models\RouteStop $destination): float
    {
        $km = LegDistance::alongLegKm($boardingKm, (float) $destination->distance_from_origin_km, $trip->leg_direction);
        if ($km <= 0) {
            throw ValidationException::withMessages([
                'destination' => ['The destination must be ahead of your boarding point for this trip\'s direction.'],
            ]);
        }

        return $km;
    }

    /** The fare rule that prices this trip: fleet override, then (route, class, service type, seat type), then (class, service type, seat type). */
    private function ruleFor(object $trip, string $seatType): \App\Models\FareRule
    {
        $rule = $this->fareRuleResolver->resolve(
            $trip->fleetRoute->fleet,
            (int) $trip->fleetRoute->route_id,
            $trip->service_type,
            $seatType,
            $trip->trip_date?->toDateString(),
        );

        if (! $rule) {
            throw $this->fareUnavailable();
        }

        return $rule;
    }

    /**
     * Defaults for "book the return trip" from this ticket: board where this ticket gets off, get off where it boarded.
     * When the boarding was a snapped point (no origin stop) the return destination is the nearest preceding stop,
     * the last stop the bus passed before that point. Null when this ticket gets off at a pin (no stop to board at).
     *
     * @return array{origin_stop_id: int, destination_stop_id: int, origin_stop_code: string|null, destination_stop_code: string|null, destination_is_nearest_preceding_stop: bool}|null
     */
    private function returnJourneyFor(Ticket $ticket): ?array
    {
        $trip = $ticket->trip;
        if (! $trip?->fleetRoute || ! $ticket->destination_stop_id) {
            return null;
        }

        $snapped = \App\Support\TicketBoarding::isSnapped($ticket);
        $destinationId = $ticket->origin_stop_id;
        if (! $destinationId && $snapped) {
            $destinationId = \App\Support\TicketBoarding::precedingStopId(
                $this->routeStopRepository->getOrderedStops((int) $trip->fleetRoute->route_id),
                $ticket->boarding_distance_m,
                $trip->leg_direction,
            );
        }
        if (! $destinationId || (int) $destinationId === (int) $ticket->destination_stop_id) {
            return null;
        }

        $stops = \App\Models\Stop::whereIn('stop_id', [(int) $ticket->destination_stop_id, (int) $destinationId])->get()->keyBy('stop_id');

        return [
            'origin_stop_id' => (int) $ticket->destination_stop_id,
            'destination_stop_id' => (int) $destinationId,
            'origin_stop_code' => $stops[(int) $ticket->destination_stop_id]?->stop_code,
            'destination_stop_code' => $stops[(int) $destinationId]?->stop_code,
            'destination_is_nearest_preceding_stop' => $snapped,
        ];
    }

    /** A custom-point quote for this trip: its fleet, class and service type pick the rule; no rule is a 422, not a 500. */
    private function quoteCoordinates(object $trip, int $routeId, array $payload): array
    {
        try {
            return $this->fareRuleService->getQuoteForTripFromCoordinates(
                (int) $trip->fleetRoute->fleet_id,
                $routeId,
                $payload + ['service_type' => $trip->service_type],
            );
        } catch (FareUnavailableException) {
            throw $this->fareUnavailable();
        }
    }

    private function fareUnavailable(): ValidationException
    {
        return ValidationException::withMessages(['trip' => [FareUnavailableException::MESSAGE]]);
    }

    private function routeStopMeters(\App\Models\RouteStop $routeStop): int
    {
        return $routeStop->distance_from_origin_m !== null
            ? (int) $routeStop->distance_from_origin_m
            : LegDistance::toMeters((float) $routeStop->distance_from_origin_km);
    }


    /**
     * Task 6: Chauffeur cash sale to someone boarding between stops. The boarding point is the
     * bus's own latest accepted position snapped to the route (MidTripBoardingService); the
     * request carries only the destination. A weak, old, imprecise or off-route position is a
     * 422 that tells the Chauffeur to pick the boarding stop instead: no guessing, no fallback fare.
     * The destination must be ahead in the leg's direction; the fare uses the same shared formula
     * and the ticket stores the snapped boarding point plus the distance snapshot (origin_stop_id null).
     */
    private function priceFromBoardingPoint(object $trip, array $item): array
    {
        $resolved = $this->midTripBoardingService->resolve($trip);
        if ($resolved['state'] !== MidTripBoardingService::STATE_GOOD) {
            throw ValidationException::withMessages([
                'boarding' => [($resolved['message'] ?? 'The bus position is not reliable.').' Choose the boarding stop instead.'],
            ]);
        }

        $point = $resolved['boarding_point'];
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $routeId = (int) $trip->fleetRoute->route_id;
        $boardingKm = (float) $point['distance_from_origin_km'];

        $base = [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'seat_type' => $item['seat_type'],
            'origin_stop_id' => null,
            'origin_lat' => $point['latitude'],
            'origin_lng' => $point['longitude'],
            'boarding_distance_m' => $point['distance_from_origin_m'],
        ];

        if (isset($item['destination_stop_id'])) {
            $destination = $this->routeStopRepository->findByRouteAndStop($routeId, (int) $item['destination_stop_id']);
            if (! $destination) {
                throw ValidationException::withMessages([
                    'stop' => ['The destination is not on this trip\'s route.'],
                ]);
            }

            if (! ServiceType::isServed($trip->service_type, $destination)) {
                $name = $destination->stop?->stop_name ?? 'that point';
                throw ValidationException::withMessages([
                    'destination' => [ServiceType::isExpress($trip->service_type)
                        ? "This express trip does not stop at {$name}."
                        : "Buses do not stop at {$name}. Choose a stop."],
                ]);
            }

            $distanceKm = $this->assertAheadAndMeasure($trip, $boardingKm, $destination);
            $rule = $this->ruleFor($trip, $item['seat_type']);

            return $base + [
                'fare_rule_id' => $rule->fare_rule_id,
                'rounding_mode' => $rule->rounding_mode,
                'distance_km' => $distanceKm,
                'amount' => $this->fareCalculationService->priceWithRule($rule, $distanceKm),
                'destination_stop_id' => (int) $item['destination_stop_id'],
                'alighting_distance_m' => $this->routeStopMeters($destination),
            ];
        }

        if (! isset($item['destination_lat'], $item['destination_lng'])) {
            throw ValidationException::withMessages([
                'destination' => ['Choose where the passenger gets off.'],
            ]);
        }

        if (ServiceType::isExpress($trip->service_type)) {
            throw ValidationException::withMessages([
                'destination' => ['A custom drop-off is not available on an express trip.'],
            ]);
        }

        $dropoff = $this->customDropoffService->evaluateFrom(
            $routeId,
            $direction,
            null,
            (float) $point['distance_along_leg_m'],
            (float) $item['destination_lat'],
            (float) $item['destination_lng'],
        );

        $quote = $this->quoteCoordinates(
            $trip,
            $routeId,
            [
                'origin_lat' => $point['latitude'],
                'origin_lng' => $point['longitude'],
                'destination_lat' => $dropoff['snapped_latitude'],
                'destination_lng' => $dropoff['snapped_longitude'],
                'seat_type' => $item['seat_type'],
                'on' => $trip->trip_date?->toDateString(),
            ]
        );

        $label = trim(strip_tags((string) ($item['destination_label'] ?? '')));

        return $base + [
            'fare_rule_id' => $quote['fare_rule_id'],
            'rounding_mode' => $quote['rounding_mode'] ?? null,
            'distance_km' => $quote['distance_km'],
            'amount' => $quote['amount'],
            'alighting_distance_m' => $quote['alighting_distance_m'] ?? null,
            'destination_lat' => $dropoff['snapped_latitude'],
            'destination_lng' => $dropoff['snapped_longitude'],
            'destination_label' => mb_substr($label, 0, 255) ?: $this->reverseGeocodingService->resolvePlaceName(
                $dropoff['snapped_latitude'],
                $dropoff['snapped_longitude'],
            ) ?? 'Custom drop-off',
        ];
    }

    /**
     * D5 custom drop-off: boarding is a known stop (B7), the drop-off is a pin.
     * The pin is re-validated and snapped server-side (route flag, region,
     * threshold, direction, spacing) and the fare is re-derived from the
     * snapped point with the existing fare service (D4). The client's amount
     * and coordinates are never trusted; the ticket stores the snapped point.
     */
    private function priceByCustomDropoff(object $trip, array $item): array
    {
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $routeId = (int) $trip->fleetRoute->route_id;

        $boardingRouteStop = $this->routeStopRepository->findByRouteAndStop($routeId, (int) $item['origin_stop_id']);
        if (! $boardingRouteStop) {
            throw ValidationException::withMessages([
                'stop' => ['The boarding stop is not on this trip\'s route.'],
            ]);
        }

        if (ServiceType::isExpress($trip->service_type)) {
            throw ValidationException::withMessages([
                'destination' => ['A custom drop-off is not available on an express trip.'],
            ]);
        }

        $dropoff = $this->customDropoffService->evaluate(
            $routeId,
            $direction,
            (int) $item['origin_stop_id'],
            (float) $item['destination_lat'],
            (float) $item['destination_lng'],
        );

        $boarding = $boardingRouteStop->stop;
        $quote = $this->quoteCoordinates(
            $trip,
            $routeId,
            [
                'origin_lat' => (float) $boarding->latitude,
                'origin_lng' => (float) $boarding->longitude,
                'destination_lat' => $dropoff['snapped_latitude'],
                'destination_lng' => $dropoff['snapped_longitude'],
                'seat_type' => $item['seat_type'],
                'on' => $trip->trip_date?->toDateString(),
            ]
        );

        $label = trim(strip_tags((string) ($item['destination_label'] ?? '')));

        return [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'seat_type' => $item['seat_type'],
            'fare_rule_id' => $quote['fare_rule_id'],
            'rounding_mode' => $quote['rounding_mode'] ?? null,
            'distance_km' => $quote['distance_km'],
            'amount' => $quote['amount'],
            'boarding_distance_m' => $quote['boarding_distance_m'] ?? null,
            'alighting_distance_m' => $quote['alighting_distance_m'] ?? null,
            'origin_stop_id' => (int) $item['origin_stop_id'],
            // Snapped point, so the ticket and the driver's requested stop show the
            // place the bus will actually stop.
            'destination_lat' => $dropoff['snapped_latitude'],
            'destination_lng' => $dropoff['snapped_longitude'],
            'destination_label' => mb_substr($label, 0, 255) ?: $this->reverseGeocodingService->resolvePlaceName(
                $dropoff['snapped_latitude'],
                $dropoff['snapped_longitude'],
            ) ?? 'Custom drop-off',
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
        $tracking = $this->trackingService->newCredentials();
        $ticket = $this->ticketRepository->create([
            'tracking_token_hash' => $tracking['tracking_token_hash'],
            'tracking_token_enc' => $tracking['tracking_token_enc'],
            'fleet_route_id' => $reserved['fleet_route_id'],
            'trip_id' => $reserved['trip_id'],
            'fare_rule_id' => $reserved['fare_rule_id'],
            'rounding_mode' => $reserved['rounding_mode'] ?? null,
            'journey_leg' => $reserved['journey_leg'] ?? null,
            'distance_km' => $reserved['distance_km'],
            'payment_id' => $paymentId,
            'passenger_id' => $passengerId,
            'status' => 'valid',
            'amount' => $reserved['amount'],
            'seat_type' => $reserved['seat_type'],
            'origin_stop_id' => $reserved['origin_stop_id'] ?? null,
            'destination_stop_id' => $reserved['destination_stop_id'] ?? null,
            'origin_lat' => $reserved['origin_lat'] ?? null,
            'origin_lng' => $reserved['origin_lng'] ?? null,
            'destination_lat' => $reserved['destination_lat'] ?? null,
            'destination_lng' => $reserved['destination_lng'] ?? null,
            'destination_label' => $reserved['destination_label'] ?? null,
            'boarding_distance_m' => $reserved['boarding_distance_m'] ?? null,
            'alighting_distance_m' => $reserved['alighting_distance_m'] ?? null,
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

        if (! $ticket) {
            throw ValidationException::withMessages(['ticket' => ['Ticket not found.']]);
        }

        if ($ticket->status !== 'valid') {
            throw ValidationException::withMessages([
                'ticket' => ["Ticket is already {$ticket->status}."],
            ]);
        }

        if (! $ticket->payment || $ticket->payment->status !== 'paid' || ! $ticket->payment->is_valid) {
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

            if (! $activeTrip) {
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
        $todayStr = now()->toDateString();

        if ($tripDateStr === null) {
            throw ValidationException::withMessages([
                'ticket' => ['Ticket does not have a valid trip date and cannot be boarded.'],
            ]);
        }

        if ($tripDateStr > $todayStr) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is not yet active. It can only be used on the scheduled trip date ('.$tripDateStr.').'],
            ]);
        }

        if ($tripDateStr < $todayStr) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket has expired. Tickets are only valid on their scheduled trip date ('.$tripDateStr.').'],
            ]);
        }

        $this->ticketRepository->markBoarded($ticket->ticket_id);
        $boardedTicket = $this->ticketRepository->findByUuid($payload['ticket_uuid']);

        // Destination label — prefer a named stop; fall back to GPS coords for
        // custom-pinpoint tickets so the conductor sees WHERE the passenger alights.
        $destinationLabel = $boardedTicket->destinationStop?->stop_name
            ?? ($boardedTicket->destination_lat !== null
                ? 'GPS: '.round((float) $boardedTicket->destination_lat, 5).', '.round((float) $boardedTicket->destination_lng, 5)
                : null);

        $originLabel = $boardedTicket->originStop?->stop_name
            ?? (\App\Support\TicketBoarding::isSnapped($boardedTicket) ? \App\Support\TicketBoarding::BETWEEN_STOPS : null);

        return (object) [
            'ticket_id' => $boardedTicket->ticket_id,
            'ticket_uuid' => $boardedTicket->ticket_uuid,
            'passenger_name' => $boardedTicket->passenger?->user?->name ?? 'Guest',
            'origin' => $originLabel,
            'destination' => $destinationLabel,
            'destination_lat' => $boardedTicket->destination_lat,
            'destination_lng' => $boardedTicket->destination_lng,
            'seat_type' => $boardedTicket->seat_type,
            'amount' => (float) $boardedTicket->amount,
            'status' => $boardedTicket->status,
            'boarded_at' => $boardedTicket->boarded_at,
        ];
    }

    public function getPassengerTickets(int $passengerId): object
    {
        $tickets = $this->ticketRepository->findByPassenger($passengerId);
        \App\Support\TripRunInfo::attach($tickets->pluck('trip')->filter()->unique('trip_id'));

        return $tickets->map(fn (Ticket $ticket) => $this->appendValidityWindow($ticket));
    }

    /**
     * Retrieve a ticket by UUID for ownership/authorization checks.
     * Throws ModelNotFoundException (auto-resolved as 404) if not found.
     */
    public function getTicketByUuidOrFail(string $uuid): Ticket
    {
        $ticket = $this->ticketRepository->findByUuid($uuid);

        if (! $ticket) {
            throw (new ModelNotFoundException)->setModel(Ticket::class);
        }

        return $ticket;
    }

    /**
     * Trip earnings summary — total fare collected, broken down by payment method.
     * Computed server-side from verified payment/ticket records only.
     */
    public function getTripEarnings(int $tripId): array
    {
        $tickets = $this->ticketRepository->findPaidTicketsWithPaymentForTrip($tripId);

        $totalFare = 0.0;
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
            'trip_id' => $tripId,
            'total_fare' => round($totalFare, 2),
            'onsite_amount' => round($onsiteAmount, 2),
            'online_amount' => round($onlineAmount, 2),
            'passenger_count' => $passengerCount,
            'average_fare' => $passengerCount > 0 ? round($totalFare / $passengerCount, 2) : 0.0,
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
            $activeTrip = $this->tripService->getCurrentTripForConductor($conductorCompanyUserId);
            $activeTripId = $activeTrip?->trip_id;

            if (! $activeTripId) {
                throw ValidationException::withMessages([
                    'transaction_reference' => ['No active trip found. You must have an active trip to scan group tickets.'],
                ]);
            }
        }

        $todayStr = now()->toDateString();
        $boardedCount = 0;
        $results = [];

        foreach ($tickets as $ticket) {
            $base = [
                'ticket_uuid' => $ticket->ticket_uuid,
                'passenger_name' => $ticket->passenger?->user?->name ?? 'Guest',
                'destination' => $ticket->destinationStop?->stop_name,
                'seat_type' => $ticket->seat_type,
                'amount' => (float) $ticket->amount,
            ];

            if ($ticket->status !== 'valid') {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'This ticket is invalid/used.']);

                continue;
            }

            if (! $ticket->payment || $ticket->payment->status !== 'paid' || ! $ticket->payment->is_valid) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'This ticket is invalid.']);

                continue;
            }

            // Trip ownership check — same logic as single-ticket scan.
            if ($activeTripId !== null && (int) $ticket->trip_id !== (int) $activeTripId) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'This ticket is invalid.']);

                continue;
            }

            // Date check — string comparison, avoids Carbon null/timezone issues.
            $tripDateStr = $ticket->trip?->trip_date?->toDateString();

            if ($tripDateStr === null) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'This ticket is invalid.']);

                continue;
            }

            if ($tripDateStr > $todayStr) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'Ticket not yet active (scheduled for '.$tripDateStr.')']);

                continue;
            }

            if ($tripDateStr < $todayStr) {
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'This ticket is invalid.']);

                continue;
            }

            $this->ticketRepository->markBoarded($ticket->ticket_id);
            $boardedCount++;
            $results[] = array_merge($base, ['status' => 'boarded', 'skipped' => false]);
        }

        return [
            'transaction_reference' => $payload['transaction_reference'],
            'total_tickets' => count($results),
            'boarded_count' => $boardedCount,
            'tickets' => $results,
        ];
    }

    public function findByTransactionAndEmail(string $transactionReference, ?string $email = null, ?int $paymentId = null): object
    {
        $tickets = $this->ticketRepository->findByTransactionAndEmail($transactionReference, $email, $paymentId);
        \App\Support\TripRunInfo::attach($tickets->pluck('trip')->filter()->unique('trip_id'));

        return $tickets->map(fn (Ticket $ticket) => $this->appendValidityWindow($ticket));
    }

    private function appendValidityWindow(Ticket $ticket): Ticket
    {
        $tripDate = $ticket->trip?->trip_date;
        if ($tripDate) {
            $tz = config('app.timezone', 'UTC');
            $dateStr = $tripDate->toDateString(); // "YYYY-MM-DD"

            // valid_from — use the fleet route's scheduled departure time so
            // passengers see the actual boarding time, not midnight UTC which
            // renders incorrectly when the browser converts to local time.
            $startTime = $ticket->trip?->fleetRoute?->start_time ?? '00:00:00';
            $validFrom = Carbon::parse($dateStr.' '.$startTime, $tz);

            // expires_at — end of the trip day in the app's local timezone.
            // A ticket is only usable on its scheduled trip date (23:59:59 local).
            $expiresAt = Carbon::parse($dateStr.' 23:59:59', $tz);

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

        if (! $ticket->originStop?->stop_name || ! $ticket->destinationStop?->stop_name) {
            ['origin' => $originLabel, 'destination' => $destinationLabel] = $this->resolveCustomLocationLabelsFromPayload($ticket);

            if (! $ticket->originStop?->stop_name && $originLabel) {
                $ticket->setAttribute('origin', $originLabel);
            }

            if (! $ticket->destinationStop?->stop_name && $destinationLabel) {
                $ticket->setAttribute('destination', $destinationLabel);
            }
        }

        // A boarding taken between stops has no origin stop: say so, and give the stop to default a return trip to
        // (the nearest preceding stop, so "book the return" works without asking).
        if (! $ticket->originStop?->stop_name && \App\Support\TicketBoarding::isSnapped($ticket)) {
            $ticket->setAttribute('origin', \App\Support\TicketBoarding::BETWEEN_STOPS);
        }
        $ticket->setAttribute('return_journey', $this->returnJourneyFor($ticket));

        // Owners only (this runs for a passenger's own tickets and for a guest who proved the
        // payment reference): the link that follows this ticket's bus. Virtual, never stored here.
        $ticket->setAttribute('tracking_token', $this->trackingService->tokenFor($ticket));

        // Passenger-facing: the bus is identified by its company label, never by the plate.
        $fleet = $ticket->trip?->fleetRoute?->fleet;
        $ticket->setAttribute('bus_label', BusLabel::for($fleet));
        // Batch 28 Stage 3, item 8: the run pattern ("One-way run" / "Round-trip run") and the service (Regular, Express,
        // Express - Non-stop with the stops it serves) of this ticket's trip, from data.
        if ($ticket->trip) {
            $ticket->setAttribute('run_info', $ticket->trip->getAttribute('run_info') ?? \App\Support\TripRunInfo::forTrip($ticket->trip));
        }
        $fleet?->makeHidden(['plate_number']);

        // Sync so these virtual attributes are never treated as dirty
        // if something calls save() again downstream.
        $ticket->syncOriginal();

        return $ticket;
    }

    private function resolveCustomLocationLabelsFromPayload(Ticket $ticket): array
    {
        $fallback = [
            'origin' => null,
            'destination' => null,
        ];

        $itemsPayload = $ticket->payment?->items_payload;
        if (! is_array($itemsPayload) || empty($itemsPayload)) {
            return $fallback;
        }

        $matchesCoordinates = static function ($itemValue, $ticketValue): bool {
            if (! is_numeric($itemValue) || ! is_numeric($ticketValue)) {
                return false;
            }

            return abs((float) $itemValue - (float) $ticketValue) <= 0.00002;
        };

        foreach ($itemsPayload as $item) {
            if (! is_array($item)) {
                continue;
            }

            if ((int) ($item['trip_id'] ?? 0) !== (int) $ticket->trip_id) {
                continue;
            }

            if (($item['seat_type'] ?? null) !== $ticket->seat_type) {
                continue;
            }

            if (! $matchesCoordinates($item['origin_lat'] ?? null, $ticket->origin_lat)
                || ! $matchesCoordinates($item['origin_lng'] ?? null, $ticket->origin_lng)
                || ! $matchesCoordinates($item['destination_lat'] ?? null, $ticket->destination_lat)
                || ! $matchesCoordinates($item['destination_lng'] ?? null, $ticket->destination_lng)) {
                continue;
            }

            $origin = trim((string) ($item['origin_label'] ?? '')) ?: null;
            $destination = trim((string) ($item['destination_label'] ?? '')) ?: null;

            if ($origin || $destination) {
                return [
                    'origin' => $origin,
                    'destination' => $destination,
                ];
            }
        }

        foreach ($itemsPayload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $origin = trim((string) ($item['origin_label'] ?? '')) ?: null;
            $destination = trim((string) ($item['destination_label'] ?? '')) ?: null;
            if ($origin || $destination) {
                return [
                    'origin' => $origin,
                    'destination' => $destination,
                ];
            }
        }

        return $fallback;
    }
}
