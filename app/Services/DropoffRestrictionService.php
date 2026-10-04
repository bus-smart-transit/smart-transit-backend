<?php

namespace App\Services;

use App\Models\CustomDropoffRestriction;
use App\Models\RouteStop;
use App\Repositories\RouteGeometryRepository;
use App\Support\Geo\GeoLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Batch 28 Stage 3, item 10: where custom drop-off is NOT available.
 *
 * A restriction is a ROUTE SEGMENT: the stretch of one route between two of its route stops. It is resolved to a
 * distance range with the two stops' stored distances (route_stop.distance_from_origin_m, metres from the route's first
 * stop along the stored line), and a point is inside it when its distance along the route falls in that range. The range
 * is kept in the route's own (outbound) frame, so the same row works for either leg direction: a point on a reverse
 * leg is turned into the outbound frame with the route line's length first.
 *
 * Nothing here names a place or holds a coordinate; every name in a message comes from the restriction row.
 *
 * A restriction whose end stops are gone, out of order, without a stored distance, or moved since it was saved is
 * "needs review". It is never applied to the wrong stretch and never silently dropped: while it is active it blocks
 * custom drop-off on its whole route (fail CLOSED, config dropoff.needs_review_fails) until someone reviews it.
 */
class DropoffRestrictionService
{
    /** @var array<int, array|null> the route lines already read by this instance (one read per route per request) */
    private array $lines = [];

    public function __construct(private RouteGeometryRepository $geometry) {}

    private function line(int $routeId): ?array
    {
        if (! array_key_exists($routeId, $this->lines)) {
            $this->lines[$routeId] = $this->geometry->get($routeId);
        }

        return $this->lines[$routeId];
    }

    /** Length of the route's stored line in metres, or null when the route has no generated path. */
    public function routeLengthM(int $routeId): ?float
    {
        $geometry = $this->line($routeId);

        return $geometry ? GeoLine::lengthM($geometry['coordinates']) : null;
    }

    /** Where a route stop sits along the route line (metres from its start), measured on the line itself. */
    private function alongTheLine(int $routeId, RouteStop $routeStop): ?float
    {
        $geometry = $this->line($routeId);
        if (! $geometry) {
            return null;
        }

        $stop = $routeStop->stop;
        $lat = $routeStop->snapped_latitude !== null ? (float) $routeStop->snapped_latitude : ($stop ? (float) $stop->latitude : null);
        $lng = $routeStop->snapped_longitude !== null ? (float) $routeStop->snapped_longitude : ($stop ? (float) $stop->longitude : null);
        if ($lat === null || $lng === null) {
            return null;
        }

        return GeoLine::project($geometry['coordinates'], $lat, $lng)['distance_along_m'] ?? null;
    }

    /**
     * @return array{valid: bool, from_m: int|null, to_m: int|null, reason: string|null}
     */
    public function resolve(CustomDropoffRestriction $restriction, ?Collection $routeStops = null, bool $checkSavedDistances = true): array
    {
        $invalid = fn (string $reason, ?int $from = null, ?int $to = null) => ['valid' => false, 'from_m' => $from, 'to_m' => $to, 'reason' => $reason];

        if ($restriction->area_type !== CustomDropoffRestriction::AREA_ROUTE_SEGMENT) {
            return $invalid('This kind of area is not supported yet.');
        }
        if ($restriction->from_route_stop_id === null || $restriction->to_route_stop_id === null) {
            return $invalid('An end stop of this area was removed from the route.');
        }

        $stops = $routeStops ?? RouteStop::with('stop')->where('route_id', $restriction->route_id)->get();
        $from = $stops->firstWhere('id', $restriction->from_route_stop_id);
        $to = $stops->firstWhere('id', $restriction->to_route_stop_id);
        if (! $from || ! $to) {
            return $invalid('An end stop of this area is no longer on the route.');
        }
        if ((int) $from->stop_order >= (int) $to->stop_order) {
            return $invalid('The two end stops are out of order: the route stops were reordered.');
        }
        if ($from->distance_from_origin_m === null || $to->distance_from_origin_m === null) {
            return $invalid('An end stop has no stored distance along the route.');
        }

        $fromM = (int) $from->distance_from_origin_m;
        $toM = (int) $to->distance_from_origin_m;
        if ($fromM >= $toM) {
            return $invalid('The end stops do not give a stretch of route (the distances are not increasing).', $fromM, $toM);
        }

        $tolerance = (float) config('dropoff.restriction_distance_tolerance_m');

        // The stored distance must still agree with where the stop really is on the route line (the range is compared with
        // distances measured on that line). A stop edited by hand or a line rebuilt without updating the distance is flagged.
        foreach ([[$from, $fromM], [$to, $toM]] as [$routeStop, $storedM]) {
            $along = $this->alongTheLine((int) $restriction->route_id, $routeStop);
            if ($along !== null && abs($along - $storedM) > $tolerance) {
                return $invalid('The stored distance of an end stop no longer matches the route line.', $fromM, $toM);
            }
        }
        foreach ($checkSavedDistances ? [[$restriction->from_distance_m, $fromM], [$restriction->to_distance_m, $toM]] : [] as [$saved, $now]) {
            if ($saved !== null && abs($now - (int) $saved) > $tolerance) {
                return $invalid('An end stop moved since this area was saved.', $fromM, $toM);
            }
        }

        return ['valid' => true, 'from_m' => $fromM, 'to_m' => $toM, 'reason' => null];
    }

    /**
     * The route's active restrictions, each with its resolved range. The stored needs_review flag is brought in step
     * with what the stops say now (one small write, only when it changed).
     *
     * @return Collection<int, array{restriction: CustomDropoffRestriction, resolved: array}>
     */
    public function activeForRoute(int $routeId): Collection
    {
        return $this->forRoute($routeId, true);
    }

    /** @return Collection<int, array{restriction: CustomDropoffRestriction, resolved: array}> */
    public function forRoute(int $routeId, bool $onlyActive = false): Collection
    {
        $rows = CustomDropoffRestriction::where('route_id', $routeId)
            ->where('applies_to', CustomDropoffRestriction::APPLIES_CUSTOM_DROPOFF)
            ->when($onlyActive, fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return collect();
        }

        $stops = RouteStop::with('stop')->where('route_id', $routeId)->get();

        return $rows->map(function (CustomDropoffRestriction $row) use ($stops) {
            $resolved = $this->resolve($row, $stops);
            $this->syncReviewFlag($row, $resolved);

            return ['restriction' => $row, 'resolved' => $resolved];
        })->values();
    }

    /**
     * Whether a point may NOT take a custom drop-off. Null = allowed.
     *
     * @return array{restriction: CustomDropoffRestriction, kind: string, message: string}|null
     */
    public function blockingFor(int $routeId, string $direction, float $alongLegM, float $lengthM): ?array
    {
        $active = $this->activeForRoute($routeId);

        foreach ($active as $row) {
            if (! $row['resolved']['valid'] && config('dropoff.needs_review_fails') !== 'open') {
                return [
                    'restriction' => $row['restriction'],
                    'kind' => 'needs_review',
                    'message' => 'Custom drop-off is not available on this route right now: the restricted area "'.$row['restriction']->name.'" has to be reviewed. Please choose an official stop.',
                ];
            }
        }

        $alongOutbound = $direction === 'reverse' ? $lengthM - $alongLegM : $alongLegM;
        foreach ($active as $row) {
            $resolved = $row['resolved'];
            if ($resolved['valid'] && $alongOutbound >= $resolved['from_m'] && $alongOutbound <= $resolved['to_m']) {
                return [
                    'restriction' => $row['restriction'],
                    'kind' => 'inside',
                    'message' => 'Custom drop-off is not available inside '.$row['restriction']->name.'. Please choose an official stop.',
                ];
            }
        }

        return null;
    }

    /**
     * The valid active areas as ranges along the leg (metres from the leg's start), in travel order.
     *
     * @return array<int, array{id: int, name: string, note: string|null, from_m: float, to_m: float}>
     */
    public function legRanges(int $routeId, string $direction, float $lengthM): array
    {
        $ranges = [];
        foreach ($this->activeForRoute($routeId) as $row) {
            if (! $row['resolved']['valid']) {
                continue;
            }
            $from = (float) $row['resolved']['from_m'];
            $to = (float) $row['resolved']['to_m'];
            if ($direction === 'reverse') {
                [$from, $to] = [$lengthM - $to, $lengthM - $from];
            }
            $ranges[] = ['id' => (int) $row['restriction']->id, 'name' => $row['restriction']->name, 'note' => $row['restriction']->note, 'from_m' => $from, 'to_m' => $to];
        }
        usort($ranges, fn ($a, $b) => $a['from_m'] <=> $b['from_m']);

        return $ranges;
    }

    /**
     * Whether everything from the boarding position to the end of the leg is inside restricted areas (so a custom
     * drop-off could never be placed). Returns the names of the areas, or null when some of the stretch is free.
     *
     * @return array<int, string>|null
     */
    public function coversRemainder(int $routeId, string $direction, float $boardingAlongLegM, float $lengthM): ?array
    {
        $ranges = $this->legRanges($routeId, $direction, $lengthM);
        if ($ranges === []) {
            return null;
        }

        // Stored stop distances are whole metres, so an area that ends at the last stop falls a metre or so short of the
        // line's end: that is "to the end".
        $slack = (float) config('dropoff.remainder_tolerance_m');
        $cursor = $boardingAlongLegM;
        $names = [];
        foreach ($ranges as $range) {
            if ($range['to_m'] < $cursor) {
                continue;
            }
            if ($range['from_m'] > $cursor + $slack) {
                return null; // a free gap before this area
            }
            $names[] = $range['name'];
            $cursor = max($cursor, $range['to_m']);
            if ($cursor >= $lengthM - $slack) {
                break;
            }
        }

        return $cursor >= $lengthM - $slack && $names !== [] ? array_values(array_unique($names)) : null;
    }

    /**
     * The line of a restricted area for shading on a map ([lat, lng] pairs), cut from the route line.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public function shadePath(int $routeId, int $fromOutboundM, int $toOutboundM): array
    {
        $geometry = $this->line($routeId);
        if (! $geometry) {
            return [];
        }

        return array_map(fn ($c) => [round($c[1], 6), round($c[0], 6)], GeoLine::slice($geometry['coordinates'], (float) $fromOutboundM, (float) $toOutboundM));
    }

    /**
     * Create or update a restricted area from two STOP ids on one route (never by name). The stops must be on the
     * route, in order, with stored distances; the distances are saved with the row so a later change is noticed.
     *
     * @param  array{route_id: int, name: string, from_stop_id: int, to_stop_id: int, note?: string|null, source?: string|null, is_active?: bool}  $data
     *
     * @throws ValidationException
     */
    public function save(array $data, ?CustomDropoffRestriction $existing = null, ?int $userId = null): CustomDropoffRestriction
    {
        $routeId = (int) $data['route_id'];
        $stops = RouteStop::with('stop')->where('route_id', $routeId)->get();
        $from = $stops->firstWhere('stop_id', (int) $data['from_stop_id']);
        $to = $stops->firstWhere('stop_id', (int) $data['to_stop_id']);
        if (! $from) {
            throw ValidationException::withMessages(['from_stop_id' => ['That stop is not on this route.']]);
        }
        if (! $to) {
            throw ValidationException::withMessages(['to_stop_id' => ['That stop is not on this route.']]);
        }

        $candidate = new CustomDropoffRestriction([
            'route_id' => $routeId,
            'area_type' => CustomDropoffRestriction::AREA_ROUTE_SEGMENT,
            'from_route_stop_id' => $from->id,
            'to_route_stop_id' => $to->id,
        ]);
        $resolved = $this->resolve($candidate, $stops);
        if (! $resolved['valid']) {
            throw ValidationException::withMessages(['to_stop_id' => [$resolved['reason']]]);
        }

        $attributes = [
            'name' => mb_substr(trim((string) $data['name']), 0, 120),
            'area_type' => CustomDropoffRestriction::AREA_ROUTE_SEGMENT,
            'route_id' => $routeId,
            'from_route_stop_id' => $from->id,
            'to_route_stop_id' => $to->id,
            'from_distance_m' => $resolved['from_m'],
            'to_distance_m' => $resolved['to_m'],
            'applies_to' => CustomDropoffRestriction::APPLIES_CUSTOM_DROPOFF,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'needs_review' => false,
            'review_reason' => null,
            'source' => isset($data['source']) ? (mb_substr(trim((string) $data['source']), 0, 120) ?: null) : null,
            'note' => isset($data['note']) ? (trim((string) $data['note']) ?: null) : null,
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return CustomDropoffRestriction::create($attributes + ['created_by_user_id' => $userId]);
    }

    /** Reset the saved end-stop distances to what the stops say now (an operator has reviewed the area). */
    public function markReviewed(CustomDropoffRestriction $restriction): array
    {
        // The structure must still make sense (both stops on the route, in order, with distances); the saved distances are
        // exactly what the reviewer is confirming, so they are not compared here.
        $resolved = $this->resolve($restriction->fresh(), null, false);
        if (! $resolved['valid']) {
            return $resolved;
        }

        $restriction->update([
            'from_distance_m' => $resolved['from_m'],
            'to_distance_m' => $resolved['to_m'],
            'needs_review' => false,
            'review_reason' => null,
        ]);

        return $resolved;
    }

    private function syncReviewFlag(CustomDropoffRestriction $row, array $resolved): void
    {
        $needs = ! $resolved['valid'];
        $reason = $needs ? mb_substr((string) $resolved['reason'], 0, 255) : null;
        if ((bool) $row->needs_review !== $needs || $row->review_reason !== $reason) {
            $row->forceFill(['needs_review' => $needs, 'review_reason' => $reason])->save();
        }
    }
}
