<?php

namespace App\Services;

use App\Repositories\RouteStopRepository;

/**
 * Where a passenger's requested stop (custom drop-off) falls along one leg, so the
 * Driver's route details and the Chauffeur's manifest can show it in sequence and
 * never silently drop it.
 *
 * `after_stop_sequence` is the leg sequence of the last stop the pin follows:
 *   - N >= 1  the pin is after stop N of this leg
 *   - 0       the pin is before the first stop of this leg (the route's stops or geometry
 *             changed after the ticket was issued; it cannot happen at checkout, which
 *             requires the pin to be ahead of the boarding stop)
 *   - null    the position cannot be computed (the route has no generated path, so there
 *             is nothing to measure along). It is still shown, marked as position unknown.
 */
class RequestedStopPlacementService
{
    public function __construct(
        private RouteSnapService $routeSnapService,
        private RouteStopRepository $routeStopRepository,
    ) {}

    /**
     * Leg context, computed once per request.
     *
     * @return array{route_id: int, direction: string, sequence_by_stop_id: array<int, int>, along_by_sequence: array<int, float|null>}
     */
    public function contextFor(object $trip): array
    {
        $routeId = (int) $trip->fleetRoute->route_id;
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $stops = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction);

        $sequenceByStopId = [];
        $alongBySequence = [];
        foreach ($stops as $routeStop) {
            $sequenceByStopId[(int) $routeStop->stop_id] = (int) $routeStop->stop_order;
            $snap = $routeStop->stop->latitude === null
                ? null
                : $this->routeSnapService->snap($routeId, (float) $routeStop->stop->latitude, (float) $routeStop->stop->longitude, $direction);
            $alongBySequence[(int) $routeStop->stop_order] = $snap['distance_along_route_m'] ?? null;
        }

        return [
            'route_id' => $routeId,
            'direction' => $direction,
            'sequence_by_stop_id' => $sequenceByStopId,
            'along_by_sequence' => $alongBySequence,
        ];
    }

    /** @return array{after_stop_sequence: int|null, position_known: bool} */
    public function place(array $context, float $lat, float $lng): array
    {
        $pin = $this->routeSnapService->snap($context['route_id'], $lat, $lng, $context['direction']);
        $hasAnyStopPosition = collect($context['along_by_sequence'])->contains(fn ($along) => $along !== null);

        if (! $pin || ! $hasAnyStopPosition) {
            return ['after_stop_sequence' => null, 'position_known' => false];
        }

        $after = 0;
        foreach ($context['along_by_sequence'] as $sequence => $along) {
            if ($along !== null && $along <= $pin['distance_along_route_m']) {
                $after = max($after, (int) $sequence);
            }
        }

        return ['after_stop_sequence' => $after, 'position_known' => true];
    }

    /**
     * A number that sorts drop-offs in travel order: a stop is its leg sequence, a requested
     * stop sits half a step after the stop it follows, and an unknown position goes last.
     */
    public function sortKey(?int $afterStopSequence, bool $positionKnown): float
    {
        return $positionKnown ? ($afterStopSequence ?? 0) + 0.5 : 100000.0;
    }
}
