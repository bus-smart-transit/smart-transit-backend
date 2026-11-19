<?php

namespace App\Services;

use App\Repositories\StopRepository;

/**
 * Batch 52 item 1 (user, 2026-11-09): the one owner of "the stops of the passenger's area" for the homepage list after
 * "Are you in {place}?": a town of the region list, or (when the town has no trip today) its province. The stops come
 * nearest first in the order the passenger's device sent (`prefer`, measured on the device; the position itself is never
 * sent), then the rest. TripResolverService boards each trip at the first of them it can still be booked from.
 */
class TripAreaService
{
    public function __construct(
        private StopRepository $stops,
        private RegionService $region,
    ) {}

    /**
     * @param  list<int>  $prefer
     * @return array{level: string, name: string, stop_ids: list<int>}|null null when the name is not in the region list
     */
    public function resolve(?string $town, ?string $province, array $prefer = []): ?array
    {
        if ($town !== null && $town !== '') {
            $name = $this->region->canonicalMunicipality($town);
            if ($name === null) {
                return null;
            }
            $level = 'town';
            $towns = [$name];
        } else {
            $match = collect(config('region.provinces'))->keys()->first(fn ($key) => mb_strtolower((string) $key) === mb_strtolower(trim((string) $province)));
            if ($match === null) {
                return null;
            }
            $level = 'province';
            $name = (string) $match;
            $towns = array_values((array) (config('region.provinces')[$match] ?? []));
        }

        $ids = $this->stops->idsInTowns($towns);
        $first = array_values(array_filter(array_map('intval', $prefer), fn ($id) => in_array($id, $ids, true)));

        return ['level' => $level, 'name' => $name, 'stop_ids' => array_values(array_unique([...$first, ...$ids]))];
    }
}
