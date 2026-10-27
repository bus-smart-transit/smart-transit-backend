<?php

namespace App\Services;

use App\Exceptions\FareUnavailableException;
use App\Models\FareRule;
use App\Models\RouteStop;
use App\Models\Stop;
use App\Models\Ticket;
use App\Repositories\RouteStopRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use App\Support\BusLabel;
use App\Support\LegDistance;
use App\Support\ServiceType;
use App\Support\TicketBoarding;
use App\Support\TripCapacity;
use App\Support\TripRunInfo;
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
        private PendingBoardingService $pendingBoardings,
        private OnsiteDiscountService $onsiteDiscounts,
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
        $reserved = $this->priceItem($item);

        // 2026-10-26: a cash sale carries the fare the Chauffeur was shown; if the server's fare is different now (a fare
        // rule changed since the quote), nothing is sold and the new fare is given back to confirm.
        if (isset($item['quoted_amount']) && abs((float) $reserved['amount'] - (float) $item['quoted_amount']) >= 0.005) {
            throw ValidationException::withMessages(['quoted_amount' => [sprintf(
                'The fare is now PHP %s, not PHP %s. Tell the passenger the new fare and make the sale again.',
                number_format((float) $reserved['amount'], 2), number_format((float) $item['quoted_amount'], 2),
            )]]);
        }

        // Reserve seat/standing capacity now: throws if full. Same hold semantics whichever pricing path was used.
        // A sale re-checks "seats full first" under the row lock (priceItem checked it without one).
        $this->tripService->recordBoarding((int) $item['trip_id'], $item['seat_type'], seatsFirst: true);

        return $reserved;
    }

    /**
     * The ticket this item would be, with its fare, checked against every sale rule. Reserves nothing, so a quote can
     * show the exact fare of a sale before it is made (the sale prices through this same method).
     */
    public function priceItem(array $item): array
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

        // Online tickets are for seats. Standing is sold by the Chauffeur on the bus, and only once the seats are full.
        if ($seatType === 'standing' && ! $onsite) {
            throw ValidationException::withMessages([
                'seat_type' => ['Online tickets are for seats only. Standing places are sold on the bus.'],
            ]);
        }

        if ($seatType === 'standing' && ! TripCapacity::standingOpen($trip)) {
            throw ValidationException::withMessages([
                'seat_type' => ['Seats are still available. Sell a seated ticket first.'],
            ]);
        }

        if ($seatType === 'standing' && $tripDate !== null && $tripDate > $today) {
            throw ValidationException::withMessages([
                'seat_type' => ['Standing bookings are available only on the trip date. Please choose seated for advance bookings.'],
            ]);
        }

        // 2026-10-26, Passenger In and snap back: a check-in, or the bus position for a sale without one, becomes the last
        // stop the leg has passed, and the ticket is priced from that stop like any stop sale (cash only).
        if ($onsite && (isset($item['pending_boarding_id']) || ($item['boarding_mode'] ?? null) === 'gps')) {
            $item = $this->pendingBoardings->forSale($trip, $item);
        }

        // Batch 28 Stage 3, item 10: a designated drop-off point is named by id only; its coordinates and label are the
        // stored point's, never the request's.
        $item = $this->customDropoffService->normaliseItem($item, (int) $trip->fleetRoute->route_id);

        // 2026-10-27 (§6): on a bus already on the road, a cash ticket cannot go to a stop the Driver has already
        // acknowledged (passed). The boarding stop may be behind the bus: a passenger who got on there pays now.
        if ($onsite && isset($item['destination_stop_id']) && in_array($trip->status, ['departed', 'in-progress'], true)) {
            $this->assertDestinationNotPassed($trip, (int) $item['destination_stop_id']);
        }

        if (isset($item['origin_stop_id'], $item['destination_stop_id'])) {
            $reserved = $this->priceByStops($trip, $item);
        } elseif (isset($item['origin_stop_id'], $item['destination_lat'], $item['destination_lng'])) {
            $reserved = $this->priceByCustomDropoff($trip, $item);
        } else {
            throw ValidationException::withMessages([
                'item' => ['Provide origin_stop_id with either destination_stop_id or a custom drop-off (destination_lat/lng).'],
            ]);
        }

        // 2026-10-27: an onsite discount (cash only, ID checked), applied here so the quote and the sale agree.
        $reserved = $this->onsiteDiscounts->apply($reserved, $item, $onsite, (int) $trip->company_user_id);

        // Passenger In: which check-in this sale settles, and how the boarding stop was found (stop | check_in | bus_position).
        $reserved['pending_boarding_id'] = isset($item['pending_boarding_id']) ? (int) $item['pending_boarding_id'] : null;
        $reserved['boarding_source'] = $item['boarding_source'] ?? 'stop';

        // Roundtrip: which of the two tickets this is (outbound | return). Null for a one-way ticket.
        $reserved['journey_leg'] = in_array($item['journey_leg'] ?? null, ['outbound', 'return'], true) ? $item['journey_leg'] : null;

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

    /**
     * §6: the destination must still be ahead of the last stop the Driver acknowledged on this leg (outbound: further
     * from the route's first stop; reverse: nearer to it). No acknowledgement yet means nothing has been passed.
     *
     * @throws ValidationException
     */
    private function assertDestinationNotPassed(object $trip, int $destinationStopId): void
    {
        if (! $trip->last_acknowledged_stop_id) {
            return;
        }

        $routeId = (int) $trip->fleetRoute->route_id;
        $acknowledged = $this->routeStopRepository->findByRouteAndStop($routeId, (int) $trip->last_acknowledged_stop_id);
        $destination = $this->routeStopRepository->findByRouteAndStop($routeId, $destinationStopId);
        if (! $acknowledged || ! $destination) {
            return; // an unknown stop is refused by the stop checks that follow
        }

        $ackKm = (float) $acknowledged->distance_from_origin_km;
        $destinationKm = (float) $destination->distance_from_origin_km;
        $passed = LegDistance::direction($trip->leg_direction) === 'reverse'
            ? $destinationKm >= $ackKm - 0.001
            : $destinationKm <= $ackKm + 0.001;

        if ($passed) {
            $name = $destination->stop?->stop_name ?? 'that stop';
            throw ValidationException::withMessages(['destination_stop_id' => ["The bus has already passed {$name}. Choose a stop ahead."]]);
        }
    }

    /** Both stops must be places this trip's bus stops at: pass-through points never are (Task 2). */
    private function assertStopsServed(object $trip, RouteStop $origin, RouteStop $destination): void
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
    private function assertAheadAndMeasure(object $trip, float $boardingKm, RouteStop $destination): float
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
    private function ruleFor(object $trip, string $seatType): FareRule
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

        $snapped = TicketBoarding::isSnapped($ticket);
        $destinationId = $ticket->origin_stop_id;
        if (! $destinationId && $snapped) {
            $destinationId = TicketBoarding::precedingStopId(
                $this->routeStopRepository->getOrderedStops((int) $trip->fleetRoute->route_id),
                $ticket->boarding_distance_m,
                $trip->leg_direction,
            );
        }
        if (! $destinationId || (int) $destinationId === (int) $ticket->destination_stop_id) {
            return null;
        }

        $stops = Stop::whereIn('stop_id', [(int) $ticket->destination_stop_id, (int) $destinationId])->get()->keyBy('stop_id');

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

    private function routeStopMeters(RouteStop $routeStop): int
    {
        return $routeStop->distance_from_origin_m !== null
            ? (int) $routeStop->distance_from_origin_m
            : LegDistance::toMeters((float) $routeStop->distance_from_origin_km);
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

        $dropoff = isset($item['alighting_point_id'])
            ? $this->customDropoffService->evaluatePoint($routeId, $direction, (int) $item['origin_stop_id'], null, (int) $item['alighting_point_id'])
            : $this->customDropoffService->evaluate(
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
            'alighting_point_id' => $dropoff['alighting_point']['id'] ?? null,
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
    public function finalizeTicket(array $reserved, ?int $passengerId, int $paymentId, bool $boarded = false): Ticket
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
            // A cash sale is made on the bus, so the passenger is already aboard: boarded, not waiting to be scanned.
            'status' => $boarded ? 'boarded' : 'valid',
            'boarded_at' => $boarded ? now() : null,
            'amount' => $reserved['amount'],
            'seat_type' => $reserved['seat_type'],
            'origin_stop_id' => $reserved['origin_stop_id'] ?? null,
            'destination_stop_id' => $reserved['destination_stop_id'] ?? null,
            'origin_lat' => $reserved['origin_lat'] ?? null,
            'origin_lng' => $reserved['origin_lng'] ?? null,
            'destination_lat' => $reserved['destination_lat'] ?? null,
            'destination_lng' => $reserved['destination_lng'] ?? null,
            'destination_label' => $reserved['destination_label'] ?? null,
            'alighting_point_id' => $reserved['alighting_point_id'] ?? null,
            'boarding_distance_m' => $reserved['boarding_distance_m'] ?? null,
            'alighting_distance_m' => $reserved['alighting_distance_m'] ?? null,
            'discount_type_key' => $reserved['discount_type_key'] ?? null,
            'discount_label' => $reserved['discount_label'] ?? null,
            'discount_rate' => $reserved['discount_rate'] ?? null,
            'original_amount' => $reserved['original_amount'] ?? null,
            'discount_amount' => $reserved['discount_amount'] ?? null,
            'discount_id_checked_at' => $reserved['discount_id_checked_at'] ?? null,
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
            $onsite = ($item['channel'] ?? null) === 'onsite';
            // Passenger In: one check-in pays for one ticket. Locked first, so a second sale of it waits and is refused.
            $checkIn = $onsite && isset($item['pending_boarding_id'])
                ? $this->pendingBoardings->claim((int) $item['pending_boarding_id'], (int) $item['trip_id'])
                : null;

            $reserved = $this->reserveAndPrice($item);
            $ticket = $this->finalizeTicket($reserved, $passengerId, $paymentId, $onsite);

            if ($checkIn) {
                $this->pendingBoardings->markSold($checkIn, (int) $ticket->ticket_id);
            }

            return $ticket;
        });
    }

    /**
     * What a conductor typed or scanned -> the ticket UUID. The QR carries the full UUID. A ticket also prints a short
     * "Ticket ID" (its first 8 characters) that a person can read out and type; that is looked up ONLY among the tickets
     * of the conductor's own running trip, never across the system, so it cannot be used to probe other trips.
     */
    public function resolveScanCode(string $code, ?int $conductorCompanyUserId): string
    {
        $code = trim($code);

        if (! preg_match('/^[0-9a-f]{8}$/i', $code)) {
            return $code;
        }

        $activeTrip = $conductorCompanyUserId !== null
            ? $this->tripService->getCurrentTripForConductor($conductorCompanyUserId)
            : null;

        if (! $activeTrip) {
            throw ValidationException::withMessages([
                'ticket' => ['No active trip found. You must have an active trip to scan tickets.'],
            ]);
        }

        return $this->resolveShortCode($code, (int) $activeTrip->trip_id);
    }

    /** The one ticket of this trip whose UUID starts with the 8 characters; refuses none and refuses more than one. */
    public function resolveShortCode(string $code, int $tripId): string
    {
        $matches = Ticket::where('trip_id', $tripId)
            ->where('ticket_uuid', 'like', strtolower($code).'-%')
            ->pluck('ticket_uuid');

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['ticket' => ['Ticket not found on this trip.']]);
        }

        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['ticket' => ['More than one ticket matches this ID. Scan the QR code instead.']]);
        }

        return (string) $matches->first();
    }

    // $payload = ['ticket_uuid'] (the QR content, or the 8-character Ticket ID printed on the ticket)
    // $conductorCompanyUserId — when provided, the ticket must belong to the
    // conductor's currently active trip (prevents scanning tickets for wrong
    // or future trips even if the date check somehow passes).
    public function validateScan(array $payload, ?int $conductorCompanyUserId = null): object
    {
        $uuid = $this->resolveScanCode((string) $payload['ticket_uuid'], $conductorCompanyUserId);
        $ticket = $this->ticketRepository->findByUuid($uuid);

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

        // Claimed atomically: if another scan boarded this ticket a moment ago, this one is refused.
        if (! $this->ticketRepository->markBoarded($ticket->ticket_id)) {
            throw ValidationException::withMessages([
                'ticket' => ['Ticket is already boarded.'],
            ]);
        }
        $boardedTicket = $this->ticketRepository->findByUuid($uuid);

        // Destination label — prefer a named stop; fall back to GPS coords for
        // custom-pinpoint tickets so the conductor sees WHERE the passenger alights.
        $destinationLabel = $boardedTicket->destinationStop?->stop_name
            ?? ($boardedTicket->alightingPoint ? $boardedTicket->alightingPoint->name.' ('.$boardedTicket->alightingPoint->kind.')' : null)
            ?? ($boardedTicket->destination_lat !== null
                ? 'GPS: '.round((float) $boardedTicket->destination_lat, 5).', '.round((float) $boardedTicket->destination_lng, 5)
                : null);

        $originLabel = $boardedTicket->originStop?->stop_name
            ?? (TicketBoarding::isSnapped($boardedTicket) ? TicketBoarding::BETWEEN_STOPS : null);

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

    public function getPassengerTickets(int $passengerId, int $page = 1, int $perPage = 50): object
    {
        $tickets = $this->ticketRepository->findByPassenger($passengerId, $perPage, $page);
        $collection = $tickets->getCollection();
        TripRunInfo::attach($collection->pluck('trip')->filter()->unique('trip_id'));

        $tickets->setCollection($collection->map(fn (Ticket $ticket) => $this->appendValidityWindow($ticket)));

        return $tickets;
    }

    public function getPassengerDashboardFacts(int $passengerId): array
    {
        $facts = $this->ticketRepository->findPassengerDashboardFacts($passengerId);
        $featured = $facts['featured_ticket'];
        if ($featured) {
            TripRunInfo::attach([$featured->trip]);
            $facts['featured_ticket'] = $this->appendValidityWindow($featured);
        }

        return $facts;
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
        // Batch 29: money = every ticket of a PAID, valid payment at its stored amount (a ticket that expired unused or is
        // waiting for a refund choice was still paid), minus the refunds that SUCCEEDED. The old figure only summed tickets
        // that were still valid, boarded or alighted, so a partial refund removed the whole fare and an expired ticket
        // vanished. A rebooked ticket is counted once, through its replacement.
        $paid = DB::table('tickets')
            ->join('payments', 'payments.payment_id', '=', 'tickets.payment_id')
            ->where('tickets.trip_id', $tripId)
            ->where('payments.status', 'paid')
            ->where('payments.is_valid', true)
            ->where('tickets.status', '!=', 'rebooked')
            ->get(['tickets.amount', 'payments.payment_method']);

        $isCash = fn ($row) => in_array($row->payment_method, ['cash', 'onsite'], true);
        $onsiteAmount = (float) $paid->filter($isCash)->sum('amount');
        $onlineAmount = (float) $paid->reject($isCash)->sum('amount');
        $totalFare = $onsiteAmount + $onlineAmount;
        $paidTickets = $paid->count();

        // "Passengers" stays the tickets that are still good (valid, boarded, alighted).
        $passengerCount = $this->ticketRepository->findPaidTicketsWithPaymentForTrip($tripId)->count();

        $refunds = DB::table('ticket_resolutions as res')
            ->join('tickets', 'tickets.ticket_id', '=', 'res.ticket_id')
            ->join('payments', 'payments.payment_id', '=', 'tickets.payment_id')
            ->where('tickets.trip_id', $tripId)
            ->where('res.resolution', 'refund')
            ->where('res.status', 'succeeded')
            ->whereNotNull('res.amount')
            ->get(['res.amount', 'payments.payment_method']);
        $cashRefunds = (float) $refunds->filter($isCash)->sum('amount');
        $onlineRefunds = (float) $refunds->reject($isCash)->sum('amount');

        return [
            'trip_id' => $tripId,
            'total_fare' => round($totalFare, 2),
            'onsite_amount' => round($onsiteAmount, 2),
            'online_amount' => round($onlineAmount, 2),
            'passenger_count' => $passengerCount,
            'paid_tickets' => $paidTickets,
            'average_fare' => $paidTickets > 0 ? round($totalFare / $paidTickets, 2) : 0.0,
            'onsite_refunds' => round($cashRefunds, 2),
            'online_refunds' => round($onlineRefunds, 2),
            'refunded_amount' => round($cashRefunds + $onlineRefunds, 2),
            'net_total' => round($totalFare - $cashRefunds - $onlineRefunds, 2),
            'onsite_net' => round($onsiteAmount - $cashRefunds, 2),
            'online_net' => round($onlineAmount - $onlineRefunds, 2),
        ];
    }

    /**
     * Shift Summary (smarttransit_prompt.md §4.12 / §10): the Chauffeur's whole shift, not only the leg under way. It
     * adds getTripEarnings() over every leg (a standalone trip is a one-leg shift), so the money rules are the same,
     * and gives the per-leg rows the Trip Breakdown table reads. Times are the Manila wall clock ("H:i").
     *
     * @param  iterable<Trip>  $legs  the shift's legs in order
     * @return array<string, mixed>
     */
    public function getShiftEarnings(iterable $legs): array
    {
        $sumKeys = ['total_fare', 'onsite_amount', 'online_amount', 'passenger_count', 'paid_tickets', 'onsite_refunds', 'online_refunds', 'refunded_amount', 'net_total', 'onsite_net', 'online_net'];
        $totals = array_fill_keys($sumKeys, 0);
        $rows = [];

        foreach ($legs as $leg) {
            $leg->loadMissing('fleetRoute.route');
            $earnings = $this->getTripEarnings((int) $leg->trip_id);
            foreach ($sumKeys as $key) {
                $totals[$key] += $earnings[$key];
            }

            $route = $leg->fleetRoute?->route;
            $reverse = $leg->leg_direction === 'reverse';
            $rows[] = [
                'trip_id' => (int) $leg->trip_id,
                'status' => $leg->status,
                'origin' => $reverse ? $route?->destination : $route?->origin,
                'destination' => $reverse ? $route?->origin : $route?->destination,
                'scheduled_time' => substr((string) $leg->departure_time, 0, 5),
                'entry_time' => $leg->departed_at?->format('H:i'),
                'exit_time' => $leg->completed_at?->format('H:i'),
                'fare' => $earnings['net_total'],
                'passenger_count' => $earnings['passenger_count'],
            ];
        }

        foreach (['total_fare', 'onsite_amount', 'online_amount', 'onsite_refunds', 'online_refunds', 'refunded_amount', 'net_total', 'onsite_net', 'online_net'] as $money) {
            $totals[$money] = round($totals[$money], 2);
        }
        $totals['average_fare'] = $totals['paid_tickets'] > 0 ? round($totals['total_fare'] / $totals['paid_tickets'], 2) : 0.0;

        return $totals + ['trips' => $rows];
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
                $results[] = array_merge($base, ['status' => $ticket->status, 'skipped' => true, 'skip_reason' => 'This ticket is not valid or has already been used.']);

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

            if (! $this->ticketRepository->markBoarded($ticket->ticket_id)) {
                // Boarded by another scan between the read above and this claim.
                $results[] = array_merge($base, ['status' => 'boarded', 'skipped' => true, 'skip_reason' => 'This ticket is not valid or has already been used.']);

                continue;
            }
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
        TripRunInfo::attach($tickets->pluck('trip')->filter()->unique('trip_id'));

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
        if (! $ticket->originStop?->stop_name && TicketBoarding::isSnapped($ticket)) {
            $ticket->setAttribute('origin', TicketBoarding::BETWEEN_STOPS);
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
            $ticket->setAttribute('run_info', $ticket->trip->getAttribute('run_info') ?? TripRunInfo::forTrip($ticket->trip));
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
