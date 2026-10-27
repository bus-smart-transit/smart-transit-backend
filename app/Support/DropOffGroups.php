<?php

namespace App\Support;

/**
 * 2026-10-27 (smarttransit_prompt.md §7): the one place passengers are grouped by where they get off. A group is one
 * destination: a stop (stop:<id>), a designated drop-off point (point:<id>) or a requested pin (pin:<lat>,<lng>).
 * Computed on the server from the trip's current passenger rows, so it changes as tickets are sold and passengers get
 * off. Only boarded passengers are counted as getting off; tickets not yet scanned are shown apart.
 */
final class DropOffGroups
{
    public static function key(?int $destinationStopId, ?int $alightingPointId, ?float $lat, ?float $lng): ?string
    {
        if ($destinationStopId) {
            return 'stop:'.$destinationStopId;
        }
        if ($alightingPointId) {
            return 'point:'.$alightingPointId;
        }
        if ($lat !== null && $lng !== null) {
            return sprintf('pin:%.5f,%.5f', $lat, $lng);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  OccupancyService::getCurrentPassengers rows, in drop-off order
     * @return list<array{key: string, destination: string, destination_stop_id: ?int, requested_stop: bool, drop_off_order: float, alighting_count: int, not_boarded_count: int, ticket_ids: list<int>}>
     */
    public static function fromPassengers(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['destination_key'] ?? null;
            if ($key === null) {
                continue;
            }
            $groups[$key] ??= [
                'key' => $key,
                'destination' => (string) ($row['destination_stop'] ?? 'Not provided'),
                'destination_stop_id' => $row['destination_stop_id'] ?? null,
                'requested_stop' => (bool) ($row['requested_stop'] ?? false),
                'drop_off_order' => (float) ($row['drop_off_order'] ?? 100001),
                'alighting_count' => 0,
                'not_boarded_count' => 0,
                'ticket_ids' => [],
            ];
            if ($row['is_boarded'] ?? false) {
                $groups[$key]['alighting_count']++;
                $groups[$key]['ticket_ids'][] = (int) $row['ticket_id'];
            } else {
                $groups[$key]['not_boarded_count']++;
            }
        }

        usort($groups, fn (array $a, array $b) => $a['drop_off_order'] <=> $b['drop_off_order']);

        return array_values($groups);
    }
}
