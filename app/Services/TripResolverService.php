<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\RouteStopRepository;
use App\Support\BusLabel;
use App\Support\ServiceType;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * D3: there is no free trip picker. A passenger chooses where they board and
 * where they get off, and when; the trip is resolved here on the server.
 *
 * - Book Now = the next eligible departure from the current Manila time.
 * - Book Later = the earliest eligible departure on or after the chosen
 *   Manila date and time (future only, within the configured advance window).
 *
 * Eligible: the trip is still open for booking, its route serves the boarding
 * stop before the alighting stop in the trip's direction (both stopping
 * types), it leaves the boarding stop at or after the target time, and the
 * fleet still has a seat of the requested type. Availability is the existing
 * trip-level count (not per segment).
 */
class TripResolverService
{
    public function __construct(
        private RouteStopRepository $routeStopRepository,
        private FareRuleService $fareRuleService,
        private FareRuleResolver $fareRuleResolver,
    ) {}

    /**
     * The available trips for a Manila date, optionally narrowed to a journey (both stops, one
     * of them or neither), earliest departure first and paginated on the server. Today lists
     * only departures that have not left yet. When the chosen date has nothing, the first later
     * date inside the booking window that has departures comes back as `next_available_date`.
     * Each item carries the times, the seats left and the fare quoted by the same
     * FareRuleService the ticket is priced with. "Available" is `evaluate()`, the same
     * eligibility Book Now / Book Later use.
     *
     * A missing stop defaults per trip to the first (origin) or last (destination) stopping
     * point of the trip's direction, so an unfiltered list is the trips end to end.
     *
     * @param  array{origin_stop_id?: int|null, destination_stop_id?: int|null, date?: string|null, seat_type?: string|null}  $query
     * @return array{date: string, trips: array<int, array>, page: int, per_page: int, total: int, last_page: int, next_available_date: string|null, message: string|null}
     *
     * @throws ValidationException
     */
    public function available(array $query, int $page = 1, ?int $perPage = null): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $originId = isset($query['origin_stop_id']) ? (int) $query['origin_stop_id'] : null;
        $destinationId = isset($query['destination_stop_id']) ? (int) $query['destination_stop_id'] : null;
        $date = $this->assertDateInWindow($query['date'] ?? $now->toDateString(), $now, $timezone);

        $perPage = max(1, min($perPage ?? (int) config('booking.trips_per_page'), (int) config('booking.trips_max_per_page')));
        $found = $this->eligibleOnDate($date, $originId, $destinationId, $seatType, $now, $timezone);
        $total = count($found);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        $fares = [];
        $items = array_map(function ($candidate) use ($seatType, &$fares) {
            $trip = $candidate['trip'];
            // The fare depends on the fleet's rule and the journey, not on the individual trip.
            $key = $trip->fleetRoute->fleet_id.'|'.$candidate['origin']['stop_id'].'|'.$candidate['destination']['stop_id'].'|'.ServiceType::normalize($trip->service_type);
            $fares[$key] ??= $this->fareFor($trip, $candidate['origin']['stop_id'], $candidate['destination']['stop_id'], $seatType);

            return $this->present($candidate, $seatType) + ['fare' => $fares[$key]];
        }, array_slice($found, ($page - 1) * $perPage, $perPage));

        return [
            'date' => $date->toDateString(),
            'trips' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'next_available_date' => $total === 0 ? $this->nextAvailableDate($date, $originId, $destinationId, $seatType, $now, $timezone) : null,
            'message' => $total === 0 ? 'No departures match your journey on that date.' : null,
        ];
    }

    /**
     * Every eligible departure on one Manila date, earliest first. Today only those still to come.
     *
     * @return array<int, array>
     */
    private function eligibleOnDate(Carbon $date, ?int $originId, ?int $destinationId, string $seatType, Carbon $now, string $timezone): array
    {
        $from = $date->isSameDay($now) ? $now->copy() : $date->copy()->startOfDay();
        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', $date->toDateString())
            ->get();

        $found = [];
        foreach ($trips as $trip) {
            $candidate = $this->evaluate($trip, $originId, $destinationId, $seatType, $from, $timezone);
            if ($candidate) {
                $found[] = $candidate;
            }
        }
        usort($found, fn ($a, $b) => $a['boards_at']->getTimestamp() <=> $b['boards_at']->getTimestamp());

        return $found;
    }

    /** The first date after `$date`, inside the booking window, that has an eligible departure. */
    private function nextAvailableDate(Carbon $date, ?int $originId, ?int $destinationId, string $seatType, Carbon $now, string $timezone): ?string
    {
        $last = $now->copy()->startOfDay()->addDays((int) config('booking.max_advance_days'));
        $first = $date->copy()->startOfDay()->addDay();
        if ($first->gt($last)) {
            return null;
        }

        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', '>=', $first->toDateString())
            ->whereDate('trip_date', '<=', $last->toDateString())
            ->orderBy('trip_date')
            ->get();

        $next = null;
        foreach ($trips as $trip) {
            $tripDate = $trip->trip_date->toDateString();
            if ($next !== null && $tripDate >= $next) {
                continue;
            }
            if ($this->evaluate($trip, $originId, $destinationId, $seatType, $first, $timezone)) {
                $next = $tripDate;
            }
        }

        return $next;
    }

    /** True when a fare rule prices this trip (its bus, route, service type and seat type). Express is bookable only where an express rule exists. */
    private function hasFare(Trip $trip, string $seatType): bool
    {
        return $this->fareRuleResolver->isAvailable(
            $trip->fleetRoute?->fleet,
            (int) $trip->fleetRoute?->route_id,
            $trip->service_type,
            $seatType,
            $trip->trip_date?->toDateString(),
        );
    }

    private function fareFor(Trip $trip, int $originId, int $destinationId, string $seatType): ?float
    {
        try {
            return (float) $this->fareRuleService->getQuote([
                'route_id' => (int) $trip->fleetRoute->route_id,
                'fleet_id' => (int) $trip->fleetRoute->fleet_id,
                'service_type' => $trip->service_type,
                'origin_stop_id' => $originId,
                'destination_stop_id' => $destinationId,
                'seat_type' => $seatType,
                'leg_direction' => $trip->leg_direction,
                'on' => $trip->trip_date?->toDateString(),
            ]);
        } catch (\Throwable) {
            return null; // shown as "Fare unavailable"; never guessed
        }
    }

    /** @throws ValidationException */
    private function assertDateInWindow(string $value, Carbon $now, string $timezone): Carbon
    {
        $date = Carbon::createFromFormat('!Y-m-d', $value, $timezone);
        if ($date->lt($now->copy()->startOfDay())) {
            throw ValidationException::withMessages(['date' => ['That date has already passed (Manila time).']]);
        }
        $maxDays = (int) config('booking.max_advance_days');
        if ($maxDays > 0 && $date->gt($now->copy()->startOfDay()->addDays($maxDays))) {
            throw ValidationException::withMessages(['date' => ["You can book up to $maxDays days ahead."]]);
        }

        return $date;
    }

    /**
     * @param  array{mode: string, origin_stop_id: int, destination_stop_id: int, date?: string|null, time?: string|null, seat_type?: string|null}  $query
     * @return array{trip: array|null, message: string|null, searched_from: string}
     *
     * @throws ValidationException
     */
    public function resolve(array $query): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $target = $this->targetTime($query, $now, $timezone);

        $windowEnd = $target->copy()->startOfDay()->addDays((int) config('booking.search_window_days'));
        $trips = Trip::with(['fleetRoute.fleet', 'fleetRoute.route'])
            ->whereIn('status', ['scheduled', 'delayed', 'boarding'])
            ->whereDate('trip_date', '>=', $target->toDateString())
            ->whereDate('trip_date', '<=', $windowEnd->toDateString())
            ->get();

        $best = null;
        foreach ($trips as $trip) {
            $candidate = $this->evaluate($trip, (int) $query['origin_stop_id'], (int) $query['destination_stop_id'], $seatType, $target, $timezone);
            // Book Now / Book Later pick only a bookable trip: one that has a fare rule (an express trip needs an express rule).
            if ($candidate && ! $this->hasFare($trip, $seatType)) {
                continue;
            }
            if ($candidate && ($best === null || $candidate['boards_at']->lt($best['boards_at']))) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            return ['trip' => null, 'message' => 'No departures match your journey around that time.', 'searched_from' => $target->toIso8601String()];
        }

        return [
            'trip' => $this->present($best, $seatType),
            'message' => null,
            'searched_from' => $target->toIso8601String(),
        ];
    }

    /**
     * Roundtrip (Batch 28, item 4): resolves BOTH legs for one booking and says whether it can be paid.
     * The outbound leg is resolved like Book Now / Book Later; the return leg goes the other way from the
     * outbound destination back to the outbound origin, on or after the chosen return date and time, and must
     * depart after the outbound trip. Nothing is held or charged here. If either leg has no departure (or no
     * fare) can_pay is false and the passenger is told which, so no checkout is started and nothing is charged.
     *
     * @param  array{origin_stop_id: int, destination_stop_id: int, outbound_mode: string, outbound_date?: string|null, outbound_time?: string|null, return_date: string, return_time: string, seat_type?: string|null}  $query
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function resolveRoundtrip(array $query): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $originId = (int) $query['origin_stop_id'];
        $destinationId = (int) $query['destination_stop_id'];

        $outboundQuery = [
            'mode' => $query['outbound_mode'],
            'origin_stop_id' => $originId,
            'destination_stop_id' => $destinationId,
            'date' => $query['outbound_date'] ?? null,
            'time' => $query['outbound_time'] ?? null,
            'seat_type' => $seatType,
        ];
        $outboundTarget = $this->targetTime($outboundQuery, $now, $timezone);

        // Future-only, inside the booking window, and after the outbound departure: all by Manila time.
        $returnTarget = $this->targetTime(['mode' => 'later', 'date' => $query['return_date'], 'time' => $query['return_time']], $now, $timezone);
        if ($returnTarget->lte($outboundTarget)) {
            throw ValidationException::withMessages(['return_date' => ['The return must be after your departure.']]);
        }

        $outbound = $this->resolve($outboundQuery);
        $return = $this->resolve([
            'mode' => 'later',
            'origin_stop_id' => $destinationId,
            'destination_stop_id' => $originId,
            'date' => $query['return_date'],
            'time' => $query['return_time'],
            'seat_type' => $seatType,
        ]);

        $outboundTrip = $outbound['trip'];
        $returnTrip = $return['trip'];
        if ($outboundTrip && $returnTrip && Carbon::parse($returnTrip['boarding_at'])->lte(Carbon::parse($outboundTrip['boarding_at']))) {
            $returnTrip = null;
            $return['message'] = 'No return departure leaves after your outbound trip at that time.';
        }

        $outboundLeg = $outboundTrip ? $this->roundtripLeg($outboundTrip, 'outbound', $originId, $destinationId, $seatType) : null;
        $returnLeg = $returnTrip ? $this->roundtripLeg($returnTrip, 'return', $destinationId, $originId, $seatType) : null;

        $message = null;
        if (! $outboundLeg) {
            $message = 'No outbound departure matches your journey at that date and time.';
        } elseif (! $returnLeg) {
            $message = $returnTrip === null && $return['trip'] !== null
                ? 'No return departure leaves after your outbound trip at that time.'
                : 'No return departure matches your journey at that date and time.';
        } elseif ($outboundLeg['fare'] === null || $returnLeg['fare'] === null) {
            $message = 'Fare unavailable for '.($outboundLeg['fare'] === null ? 'the outbound' : 'the return').' trip.';
        }

        $canPay = $message === null;

        return [
            'journey_type' => 'roundtrip',
            'outbound' => $outboundLeg,
            'return' => $returnLeg,
            'can_pay' => $canPay,
            'total_fare' => $canPay ? round($outboundLeg['fare'] + $returnLeg['fare'], 2) : null,
            'message' => $message,
            // The return ticket's stops default to the outbound ones, swapped; both stay editable.
            'return_defaults' => ['origin_stop_id' => $destinationId, 'destination_stop_id' => $originId],
            'cancellation_notice' => (string) config('booking.cancellation_notice'),
        ];
    }

    /** @return array<string, mixed> one leg of a roundtrip summary: its own trip, fare and bus label */
    private function roundtripLeg(array $presented, string $leg, int $originId, int $destinationId, string $seatType): array
    {
        $trip = Trip::with(['fleetRoute.fleet'])->find($presented['trip_id']);
        $fare = $trip ? $this->fareFor($trip, $originId, $destinationId, $seatType) : null;

        return $presented + [
            'journey_leg' => $leg,
            'origin_stop_id' => $originId,
            'destination_stop_id' => $destinationId,
            'fare' => $fare,
            // Each ticket carries the label of its OWN trip's bus (never assumed to be the outbound bus).
            // null label = the bus has no passenger label yet: the screen says so, never the plate.
            'bus_state' => $presented['bus_label'] === null ? 'label_unavailable' : 'ready',
        ];
    }

    /**
     * Book Later: every eligible departure on one Manila date, earliest first, for the
     * passenger to choose from. Today lists only departures that have not yet left.
     *
     * @param  array{origin_stop_id: int, destination_stop_id: int, date: string, seat_type?: string|null}  $query
     * @return array{date: string, departures: array<int, array>, message: string|null}
     *
     * @throws ValidationException
     */
    public function departures(array $query): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $seatType = $query['seat_type'] ?? 'seated';
        $date = $this->assertDateInWindow($query['date'], $now, $timezone);

        $found = $this->eligibleOnDate($date, (int) $query['origin_stop_id'], (int) $query['destination_stop_id'], $seatType, $now, $timezone);

        return [
            'date' => $date->toDateString(),
            'departures' => array_map(fn ($candidate) => $this->present($candidate, $seatType), $found),
            'message' => $found === [] ? 'No departures match your journey on that date.' : null,
        ];
    }

    /** @param array{trip: Trip, boards_at: Carbon, has_offset: bool, seats_left: int, arrives_at?: Carbon|null, duration_min?: int|null, origin?: array, destination?: array} $best */
    private function present(array $best, string $seatType = 'seated'): array
    {
        return [
            'trip_id' => (int) $best['trip']->trip_id,
            'trip_date' => $best['trip']->trip_date->toDateString(),
            'boarding_time' => $best['boards_at']->format('H:i'),
            'boarding_at' => $best['boards_at']->toIso8601String(),
            'boarding_time_is_scheduled_offset' => $best['has_offset'],
            'arrival_time' => ($best['arrives_at'] ?? null)?->format('H:i'),
            'duration_minutes' => $best['duration_min'] ?? null,
            'origin' => $best['origin'] ?? null,
            'destination' => $best['destination'] ?? null,
            'route_name' => $best['trip']->fleetRoute->route?->route_name,
            'bus_label' => BusLabel::for($best['trip']->fleetRoute->fleet),
            'leg_direction' => $best['trip']->leg_direction === 'reverse' ? 'reverse' : 'outbound',
            'service_type' => ServiceType::normalize($best['trip']->service_type),
            // Bookable only where a fare rule prices this trip (an express trip needs an express rule); otherwise it is
            // listed with "Fare unavailable" and cannot be booked.
            'bookable' => $this->hasFare($best['trip'], $seatType),
            'seats_left' => $best['seats_left'],
        ];
    }

    /** @throws ValidationException */
    private function targetTime(array $query, Carbon $now, string $timezone): Carbon
    {
        if (($query['mode'] ?? 'now') === 'now') {
            return $now->copy();
        }

        if (empty($query['date']) || empty($query['time'])) {
            throw ValidationException::withMessages(['date' => ['Choose a date and time for Book Later.']]);
        }

        $target = Carbon::parse($query['date'].' '.$query['time'], $timezone);
        if ($target->lte($now)) {
            throw ValidationException::withMessages(['date' => ['Choose a date and time in the future (Manila time).']]);
        }

        $latest = $now->copy()->startOfDay()->addDays((int) config('booking.max_advance_days'))->endOfDay();
        if ($target->gt($latest)) {
            throw ValidationException::withMessages(['date' => ['You can book up to '.(int) config('booking.max_advance_days').' days ahead.']]);
        }

        return $target;
    }

    /**
     * @param  int|null  $originId  null = the first stopping point of the trip's direction
     * @param  int|null  $destinationId  null = the last stopping point of the trip's direction
     */
    private function evaluate(Trip $trip, ?int $originId, ?int $destinationId, string $seatType, Carbon $target, string $timezone): ?array
    {
        $fleetRoute = $trip->fleetRoute;
        $fleet = $fleetRoute?->fleet;
        if (! $fleetRoute || ! $fleet || $fleetRoute->status !== 'active') {
            return null;
        }

        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $stops = $this->routeStopRepository->getOrderedStopsForDirection((int) $fleetRoute->route_id, $direction)->values();
        $service = ServiceType::normalize($trip->service_type);
        // On an express trip a stop it does not serve is a pass-through for that trip.
        $isStopping = fn ($index) => ServiceType::isServed($service, $stops[$index]);
        $stoppingIndexes = $stops->keys()->filter($isStopping)->values();

        $originIndex = $originId === null ? $stoppingIndexes->first() : $stops->search(fn ($s) => (int) $s->stop_id === $originId);
        $destinationIndex = $destinationId === null ? $stoppingIndexes->last() : $stops->search(fn ($s) => (int) $s->stop_id === $destinationId);

        if ($originIndex === false || $originIndex === null || $destinationIndex === false || $destinationIndex === null
            || $originIndex >= $destinationIndex || ! $isStopping($originIndex) || ! $isStopping($destinationIndex)) {
            return null;
        }

        $time = $trip->departure_time ?: $fleetRoute->start_time;
        if (! $time) {
            return null;
        }

        $offset = ServiceType::offsetMinutes($service, $stops[$originIndex]);
        $destinationOffset = ServiceType::offsetMinutes($service, $stops[$destinationIndex]);
        $start = Carbon::parse($trip->trip_date->toDateString().' '.$time, $timezone);
        $boardsAt = $start->copy()->addMinutes((int) ($offset ?? 0));
        if ($boardsAt->lt($target)) {
            return null;
        }

        $seatsLeft = $seatType === 'standing'
            ? (int) $fleet->standing_capacity - (int) $trip->current_standing_capacity
            : (int) $fleet->seated_capacity - (int) $trip->current_seated_capacity;
        if ($seatsLeft <= 0) {
            return null;
        }
        if (strtolower((string) $fleet->fleet_type) === 'private' && $seatType === 'standing') {
            return null;
        }

        // Arrival at the alighting stop and the journey time, only when both stops have a scheduled offset.
        $hasTimes = $offset !== null && $destinationOffset !== null;
        $arrivesAt = $hasTimes ? $start->copy()->addMinutes((int) $destinationOffset) : null;
        $duration = $hasTimes ? max(0, (int) round((float) $destinationOffset - (float) $offset)) : null;

        return [
            'trip' => $trip, 'boards_at' => $boardsAt, 'has_offset' => $offset !== null, 'seats_left' => $seatsLeft, 'arrives_at' => $arrivesAt, 'duration_min' => $duration,
            'origin' => $this->stopSummary($stops[$originIndex]),
            'destination' => $this->stopSummary($stops[$destinationIndex]),
        ];
    }

    /** @return array{stop_id: int, stop_code: string|null, name: string|null} */
    private function stopSummary($routeStop): array
    {
        return [
            'stop_id' => (int) $routeStop->stop_id,
            'stop_code' => $routeStop->stop?->stop_code,
            'name' => $routeStop->stop?->stop_name,
        ];
    }
}