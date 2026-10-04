<?php

namespace App\Services;

use App\Models\RouteDurationFactor;
use App\Models\Trip;
use App\Repositories\RouteStopRepository;
use App\Support\LegDistance;
use App\Support\ServiceType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Batch 28 Stage 3, item 7: peak-hour duration factors.
 *
 * A route's stop offsets (minutes from departure) describe an ordinary run. For a leg whose SCHEDULED DEPARTURE falls
 * inside a configured window (Manila time, optionally for one direction and one day type), every offset is multiplied
 * by the window's factor: expected leg duration = base offset total x factor, and each stop's ETA offset scales the
 * same way. No matching window means factor 1.0. Factors are configured by a person (or copied from the read-only
 * report); nothing here measures or applies anything automatically.
 *
 * What is never moved: issued tickets keep their times, and a leg keeps the factor it had when it started boarding or
 * departed (trips.duration_factor), so editing a window later never shifts a leg already under way.
 */
class RouteDurationFactorService
{
    public const DAY_TYPES = ['weekday', 'weekend'];
    public const DIRECTIONS = ['outbound', 'reverse'];

    public function __construct(private RouteStopRepository $routeStops) {}

    /** The window containing this moment (Manila) for the route and direction, or null. */
    public function windowFor(int $routeId, ?string $direction, Carbon $at): ?RouteDurationFactor
    {
        $at = $at->copy()->setTimezone(config('app.timezone', 'Asia/Manila'));
        $time = $at->format('H:i:s');
        $dayType = $at->isWeekend() ? 'weekend' : 'weekday';
        $direction = LegDistance::direction($direction);

        return RouteDurationFactor::where('route_id', $routeId)
            ->where(fn ($q) => $q->whereNull('direction')->orWhere('direction', $direction))
            ->where(fn ($q) => $q->whereNull('day_type')->orWhere('day_type', $dayType))
            ->where('start_time', '<=', $time)
            ->where('end_time', '>', $time)
            ->orderBy('start_time')
            ->first();
    }

    /** 1.0 when no window matches. */
    public function factorFor(int $routeId, ?string $direction, Carbon $scheduledDeparture): float
    {
        return $this->windowFor($routeId, $direction, $scheduledDeparture)?->factor ?? 1.0;
    }

    /** The factor a trip's ETAs use: the one it froze when it started, else the window of its scheduled departure. */
    public function factorForTrip(object $trip): float
    {
        if ($trip->duration_factor !== null) {
            return (float) $trip->duration_factor;
        }

        $start = $this->scheduledStart($trip);

        return $start ? $this->factorFor((int) $trip->fleetRoute->route_id, $trip->leg_direction, $start) : 1.0;
    }

    /** Called when a leg starts boarding or departs: remember the factor it is running with. */
    public function freezeFor(int $tripId): void
    {
        $trip = Trip::with('fleetRoute')->find($tripId);
        if (! $trip || $trip->duration_factor !== null) {
            return;
        }

        Trip::where('trip_id', $tripId)->update(['duration_factor' => $this->factorForTrip($trip)]);
    }

    public function scale(?int $offsetMinutes, float $factor): ?int
    {
        return $offsetMinutes === null ? null : (int) round($offsetMinutes * $factor);
    }

    /** Base minutes from first to last stop for this direction and service type, or null when the offsets are not filled in. */
    public function baseTotalMinutes(int $routeId, ?string $direction, ?string $service): ?int
    {
        $stops = $this->routeStops->getOrderedStopsForDirection($routeId, LegDistance::direction($direction))->values();
        $offsets = $stops
            ->filter(fn ($routeStop) => ServiceType::isServed($service, $routeStop))
            ->map(fn ($routeStop) => ServiceType::offsetMinutes($service, $routeStop))
            ->filter(fn ($offset) => $offset !== null);

        return $offsets->isEmpty() ? null : (int) ($offsets->max() - $offsets->min());
    }

    /**
     * Suggested departure times for the legs of a shift block: leg 1 at the time given, and each later leg at the
     * previous leg's expected end plus the configured turnaround buffer. A SUGGESTION only: the operator may override
     * every time, and creating a block still takes exactly the times the operator sends.
     *
     * @return array{buffer_minutes: int, legs: array<int, array<string, mixed>>}
     */
    public function suggestLegs(int $routeId, ?string $service, string $date, string $firstTime, int $legCount): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $buffer = (int) config('shift_blocks.terminal_turnaround_buffer_minutes');
        $legs = [];
        $start = Carbon::parse($date.' '.$firstTime, $timezone);

        for ($i = 0; $i < $legCount; $i++) {
            $direction = $i % 2 === 0 ? 'outbound' : 'reverse';
            $window = $this->windowFor($routeId, $direction, $start);
            $factor = $window?->factor ?? 1.0;
            $base = $this->baseTotalMinutes($routeId, $direction, $service);
            $duration = $base === null ? null : (int) round($base * $factor);
            $end = $duration === null ? null : $start->copy()->addMinutes($duration);

            $legs[] = [
                'leg_index' => $i + 1,
                'direction' => $direction,
                'suggested_departure' => $start->format('H:i'),
                'spills_into_next_day' => ! $start->isSameDay(Carbon::parse($date, $timezone)),
                'base_duration_minutes' => $base,
                'factor' => $factor,
                'factor_label' => $window ? 'configured' : null,
                'factor_window_id' => $window?->id,
                'expected_duration_minutes' => $duration,
                'expected_end' => $end?->format('H:i'),
            ];

            if ($end === null) {
                break; // no stop offsets: nothing can be suggested for the legs that follow
            }
            $start = $end->copy()->addMinutes($buffer);
        }

        return ['buffer_minutes' => $buffer, 'legs' => $legs];
    }

    /**
     * Validates one window (values, range, and that it does not overlap another window of the same route that could
     * match the same leg). Overlap = the time ranges intersect AND the directions can match the same leg AND the day
     * types can match the same day.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>  the cleaned attributes
     *
     * @throws ValidationException
     */
    public function validateWindow(array $attributes, ?int $ignoreId = null): array
    {
        $start = $this->normalizeTime($attributes['start_time'] ?? null, 'start_time');
        $end = $this->normalizeTime($attributes['end_time'] ?? null, 'end_time');
        if ($start >= $end) {
            throw ValidationException::withMessages(['end_time' => ['The end must be after the start (a window stays inside one day; split a window that crosses midnight).']]);
        }

        $factor = (float) ($attributes['factor'] ?? 0);
        $max = (float) config('leg_planning.max_factor');
        if ($factor <= 0 || $factor > $max) {
            throw ValidationException::withMessages(['factor' => ["The factor must be above 0 and at most {$max}."]]);
        }

        $direction = $attributes['direction'] ?? null;
        $dayType = $attributes['day_type'] ?? null;
        $clean = [
            'route_id' => (int) $attributes['route_id'],
            'direction' => $direction === '' ? null : $direction,
            'day_type' => $dayType === '' ? null : $dayType,
            'start_time' => $start,
            'end_time' => $end,
            'factor' => round($factor, 3),
            'source' => in_array($attributes['source'] ?? 'manual', [RouteDurationFactor::SOURCE_MANUAL, RouteDurationFactor::SOURCE_MEASURED], true) ? ($attributes['source'] ?? 'manual') : 'manual',
            'note' => isset($attributes['note']) ? mb_substr((string) $attributes['note'], 0, 255) : null,
        ];

        $conflict = RouteDurationFactor::where('route_id', $clean['route_id'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->where(fn ($q) => $clean['direction'] === null ? $q : $q->whereNull('direction')->orWhere('direction', $clean['direction']))
            ->where(fn ($q) => $clean['day_type'] === null ? $q : $q->whereNull('day_type')->orWhere('day_type', $clean['day_type']))
            ->first();
        if ($conflict) {
            throw ValidationException::withMessages(['start_time' => [
                'This window overlaps another window of the same route ('.substr($conflict->start_time, 0, 5).' to '.substr($conflict->end_time, 0, 5).'). Change or remove that one first.',
            ]]);
        }

        return $clean;
    }

    /**
     * READ-ONLY report: the median actual duration of completed legs per route, direction and hour of departure
     * (Manila), with how many legs each figure rests on, and whether that is enough to propose a measured factor.
     * Actual duration = the leg's departed_at to completed_at (the driver's Depart and End Trip). The app keeps only
     * the LAST acknowledged stop of a trip, not a history of acknowledgements, so stop-by-stop times cannot be used.
     * A proposal is information for a person to copy into a window; nothing is applied.
     *
     * @return array{min_samples: int, source: string, rows: array<int, array<string, mixed>>}
     */
    public function durationReport(?int $routeId = null, ?int $companyUserId = null): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $minSamples = (int) config('leg_planning.report_min_samples');

        $trips = Trip::with('fleetRoute.route')
            ->where('status', 'completed')
            ->whereNotNull('departed_at')
            ->whereNotNull('completed_at')
            ->when($companyUserId, fn ($q) => $q->where('company_user_id', $companyUserId))
            ->when($routeId, fn ($q) => $q->whereHas('fleetRoute', fn ($f) => $f->where('route_id', $routeId)))
            ->get();

        $groups = $trips->groupBy(function (Trip $trip) use ($timezone) {
            $hour = Carbon::parse($trip->departed_at, $timezone)->hour;

            return $trip->fleetRoute->route_id.'|'.LegDistance::direction($trip->leg_direction).'|'.ServiceType::normalize($trip->service_type).'|'.$hour;
        });

        $rows = $groups->map(function (Collection $group, string $key) use ($minSamples, $timezone) {
            [$routeId, $direction, $service, $hour] = explode('|', $key);
            $minutes = $group->map(fn (Trip $t) => Carbon::parse($t->departed_at, $timezone)->diffInSeconds(Carbon::parse($t->completed_at, $timezone), false) / 60)
                ->filter(fn ($m) => $m > 0)
                ->sort()
                ->values();
            if ($minutes->isEmpty()) {
                return null;
            }

            $count = $minutes->count();
            $median = $count % 2 === 1 ? $minutes[intdiv($count, 2)] : ($minutes[$count / 2 - 1] + $minutes[$count / 2]) / 2;
            $base = $this->baseTotalMinutes((int) $routeId, $direction, $service);
            $enough = $count >= $minSamples && $base !== null && $base > 0;

            return [
                'route_id' => (int) $routeId,
                'route_name' => $group->first()->fleetRoute->route?->route_name,
                'direction' => $direction,
                'service_type' => $service,
                'hour' => (int) $hour,
                'samples' => $count,
                'median_minutes' => round($median, 1),
                'base_minutes' => $base,
                'enough_data' => $enough,
                'proposed_factor' => $enough ? round($median / $base, 3) : null,
                'note' => $enough ? 'Proposal only: copy it into a window yourself if you agree.' : ($base === null ? 'This route has no stop offsets, so there is nothing to compare with.' : "Not enough data (needs {$minSamples} completed legs)."),
            ];
        })->filter()->sortBy([['route_id', 'asc'], ['direction', 'asc'], ['hour', 'asc']])->values()->all();

        return ['min_samples' => $minSamples, 'source' => 'trips.departed_at to trips.completed_at of completed legs', 'rows' => $rows];
    }

    private function scheduledStart(object $trip): ?Carbon
    {
        $time = $trip->departure_time ?: $trip->fleetRoute?->start_time;
        if (! $trip->trip_date || ! $time) {
            return null;
        }

        return Carbon::parse($trip->trip_date->toDateString().' '.$time, config('app.timezone', 'Asia/Manila'));
    }

    /** @throws ValidationException */
    private function normalizeTime(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $text, $m)) {
            throw ValidationException::withMessages([$field => ['Use a time like 07:00.']]);
        }

        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }
}
