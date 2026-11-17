<?php

namespace App\Services;

use App\Repositories\StopRepository;
use App\Repositories\TripRepository;
use App\Support\BusLabel;
use App\Support\PlaceGroups;
use Illuminate\Support\Carbon;

/**
 * Batch 77 (user, 2026-11-17): the "Next arrivals" card of a stop pin on Track Bus. For one place (all the stops that are
 * the same place, PlaceGroups), the buses still coming there today, soonest first: company and body number, where the bus
 * is going, minutes until it reaches the stop, whether that time is live (GPS or the Driver's taps) or the timetable, and
 * whether it is behind the timetable by config tracking.arrivals.delayed_after_minutes or more.
 *
 * The times are the trip timeline's (TripTimelineService), the same as the booking page, so no second formula exists.
 * A bus that has passed the stop, does not stop there (express), or is due outside the window is left out.
 */
class StopArrivalsService
{
    public function __construct(
        private StopRepository $stops,
        private TripRepository $trips,
        private TripTimelineService $timeline,
        private PlaceGroups $places,
    ) {}

    /**
     * @return array{stop: array{stop_code: string, name: string|null, municipality: string|null}, arrivals: list<array<string, mixed>>, total: int, updated_at: string}|null
     */
    public function forStopCode(string $code, ?int $limit = null): ?array
    {
        $stop = $this->stops->findByCode($code);
        if (! $stop) {
            return null;
        }
        $members = $this->places->membersOf((int) $stop->stop_id);
        $now = Carbon::now(config('app.timezone', 'Asia/Manila'));
        $until = $now->copy()->addHours(max(1, (int) config('tracking.arrivals.window_hours', 3)));
        $delayedAfter = (int) config('tracking.arrivals.delayed_after_minutes', 5);

        $arrivals = [];
        foreach ($this->trips->findOpenOnDateAtStops($members, $now->toDateString()) as $trip) {
            try {
                $rows = $this->timeline->build((int) $trip->trip_id)['stops'] ?? [];
            } catch (\Throwable) {
                continue;
            }
            $row = collect($rows)->first(fn ($r) => in_array((int) ($r['stop_id'] ?? 0), $members, true)
                && ($r['type'] ?? null) !== 'pass_through'
                && ($r['progress'] ?? null) !== 'passed');
            if (! $row || empty($row['eta'])) {
                continue;
            }
            $eta = Carbon::parse($row['eta'])->setTimezone($now->getTimezone());
            if ($eta->lt($now->copy()->subMinutes(2)) || $eta->gt($until)) {
                continue;
            }
            $scheduled = ! empty($row['scheduled_at']) ? Carbon::parse($row['scheduled_at']) : null;
            $destination = collect($rows)->reverse()->first(fn ($r) => ($r['type'] ?? null) !== 'pass_through' && empty($r['custom']));
            $fleet = $trip->fleetRoute?->fleet;
            $arrivals[] = [
                'trip_id' => (int) $trip->trip_id,
                'fleet_id' => $fleet ? (int) $fleet->fleet_id : null,
                'bus_company' => $fleet?->bus_company_name,
                'body_no' => $fleet?->body_no,
                'bus_label' => BusLabel::for($fleet),
                'destination' => $destination['name'] ?? null,
                'eta' => $eta->toIso8601String(),
                'eta_time' => $eta->format('H:i'),
                'eta_minutes' => max(0, (int) ceil($now->diffInSeconds($eta, false) / 60)),
                'live' => ($row['eta_source'] ?? null) === 'live',
                'delayed' => $scheduled !== null && $scheduled->diffInMinutes($eta, false) >= $delayedAfter,
                'status' => (string) $trip->status,
            ];
        }
        usort($arrivals, fn ($a, $b) => strcmp($a['eta'], $b['eta']));
        $max = max(1, (int) config('tracking.arrivals.max_limit', 20));
        $take = min($max, max(1, $limit ?? (int) config('tracking.arrivals.default_limit', 3)));

        return [
            'stop' => [
                'stop_code' => (string) ($this->places->placeCode((int) $stop->stop_id) ?? $stop->stop_code),
                'name' => $this->places->displayName((int) $stop->stop_id) ?? $stop->stop_name,
                'municipality' => $stop->municipality,
            ],
            'arrivals' => array_slice($arrivals, 0, $take),
            'total' => count($arrivals),
            'updated_at' => $now->toIso8601String(),
        ];
    }
}
