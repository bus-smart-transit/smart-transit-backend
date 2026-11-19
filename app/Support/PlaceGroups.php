<?php

namespace App\Support;

use App\Models\Stop;
use App\Support\Geo\GeoLine;
use Illuminate\Support\Str;

/**
 * Batch 49 item 6 (user, 2026-11-07): ONE owner for "these stops are the same place". Each company adds its own stops,
 * so one real place (for example a terminal two companies use) is several stop rows. The passenger sees it once.
 *
 * Two stops are the same place when their names match after normalising (case, spaces, accents, punctuation) AND they
 * are within `booking.place_group_radius_m` of each other (pinned coordinates). Two different places that share a name
 * but lie far apart stay separate. The place is named by the most common name among its stops (a tie: the oldest
 * stop's name) and is identified by its oldest stop (the lowest stop id), so old ids and links keep working.
 *
 * A booking always uses the exact stop of the chosen bus: `onRoute()` turns a place into the stop that route has.
 * Scoped to one request or job (bound as `scoped` in AppServiceProvider): the stops are read once.
 */
final class PlaceGroups
{
    /** @var array<int, int>|null stop id => the place's id (its oldest stop) */
    private ?array $placeOf = null;

    /** @var array<int, list<int>> place id => its stop ids, oldest first */
    private array $members = [];

    /** @var array<int, string> place id => the name passengers see */
    private array $names = [];

    /** @var array<int, string|null> stop id => its stop code */
    private array $codes = [];

    /** Lower case, accents and punctuation removed, single spaces. */
    public static function normalize(string $name): string
    {
        $ascii = Str::lower(Str::ascii($name));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $ascii)));
    }

    /** The place a stop belongs to, named by its oldest stop. A stop that is not known is its own place. */
    public function placeOf(int $stopId): int
    {
        $this->load();

        return $this->placeOf[$stopId] ?? $stopId;
    }

    /** @return list<int> every stop of the place this stop belongs to (just this stop when it shares with none) */
    public function membersOf(int $stopId): array
    {
        return $this->members[$this->placeOf($stopId)] ?? [$stopId];
    }

    public function displayName(int $stopId): ?string
    {
        return $this->names[$this->placeOf($stopId)] ?? null;
    }

    /** The stop code of the place (its oldest stop's code), used in booking links. */
    public function placeCode(int $stopId): ?string
    {
        $this->load();

        return $this->codes[$this->placeOf($stopId)] ?? null;
    }

    /**
     * The stop of this place that a route actually has: the given stop when the route has it, otherwise the first stop
     * of the same place on the route, otherwise the given stop unchanged (the caller's own check then refuses it).
     *
     * @param  iterable<int|string>  $routeStopIds
     */
    public function onRoute(?int $stopId, iterable $routeStopIds): ?int
    {
        if ($stopId === null) {
            return null;
        }
        $onRoute = [];
        foreach ($routeStopIds as $id) {
            $onRoute[(int) $id] = true;
        }
        if (isset($onRoute[$stopId])) {
            return $stopId;
        }
        foreach ($this->membersOf($stopId) as $member) {
            if (isset($onRoute[$member])) {
                return $member;
            }
        }

        return $stopId;
    }

    private function load(): void
    {
        if ($this->placeOf !== null) {
            return;
        }
        $this->placeOf = [];
        $radius = (float) config('booking.place_group_radius_m');

        /** @var list<array{key: string, lat: float|null, lng: float|null, ids: list<int>, names: list<string>}> $groups */
        $groups = [];
        $stops = Stop::query()->orderBy('stop_id')->get(['stop_id', 'stop_name', 'stop_code', 'latitude', 'longitude']);
        foreach ($stops as $stop) {
            $this->codes[(int) $stop->stop_id] = $stop->stop_code;
            $key = self::normalize((string) $stop->stop_name);
            $lat = $stop->latitude !== null ? (float) $stop->latitude : null;
            $lng = $stop->longitude !== null ? (float) $stop->longitude : null;
            $joined = false;
            if ($key !== '' && $lat !== null && $lng !== null) {
                foreach ($groups as $index => $group) {
                    if ($group['key'] === $key && $group['lat'] !== null
                        && GeoLine::haversineM($group['lat'], $group['lng'], $lat, $lng) <= $radius) {
                        $groups[$index]['ids'][] = (int) $stop->stop_id;
                        $groups[$index]['names'][] = (string) $stop->stop_name;
                        $joined = true;
                        break;
                    }
                }
            }
            if (! $joined) {
                $groups[] = ['key' => $key, 'lat' => $lat, 'lng' => $lng, 'ids' => [(int) $stop->stop_id], 'names' => [(string) $stop->stop_name]];
            }
        }

        foreach ($groups as $group) {
            $place = $group['ids'][0];
            $this->members[$place] = $group['ids'];
            $this->names[$place] = self::mostCommon($group['names']);
            foreach ($group['ids'] as $id) {
                $this->placeOf[$id] = $place;
            }
        }
    }

    /** @param  list<string>  $names  oldest stop first, so a tie keeps the oldest name */
    private static function mostCommon(array $names): string
    {
        $counts = [];
        foreach ($names as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        $best = $names[0];
        foreach ($counts as $name => $count) {
            if ($count > $counts[$best]) {
                $best = (string) $name;
            }
        }

        return $best;
    }
}
