<?php

namespace App\Support;

use App\Models\RouteStop;
use App\Repositories\RouteStopRepository;

/**
 * 2026-11-20 (user): the default stops of an express trip. A route on which no stop is marked "served by express" runs
 * its express trips NON-STOP between its own origin and destination: the route's first and last stopping stop are
 * served, every stop between them is a pass-through. A route that marks stops keeps exactly the stops it marks
 * (ServiceType). On such a default route the two ends take the regular timetable times as their estimate, since no
 * express times are set; a route with marked stops still never borrows regular times.
 *
 * Read once per route per request or queue job (bound as scoped in AppServiceProvider) and forgotten when a route stop
 * is saved or deleted (RouteStop::booted), so a change shows on the next read.
 */
final class ExpressStops
{
    /** @var array<int, array{marked: bool, first: int|null, last: int|null}> */
    private array $summaries = [];

    public function __construct(private RouteStopRepository $routeStops) {}

    /**
     * True when express trips on this route run non-stop between its first and last stop. 2026-11-21 (user): express is
     * shown as "Non-stop" and always runs non-stop, so stops marked "served by express" no longer change it.
     */
    public function isDefaultNonStopRoute(int $routeId): bool
    {
        return $this->summary($routeId)['first'] !== null;
    }

    /** True when this route stop is one of the two ends an express trip serves by default. */
    public function isDefaultStop(RouteStop $routeStop): bool
    {
        $routeId = (int) $routeStop->route_id;
        if (! $routeId || ! $this->isDefaultNonStopRoute($routeId)) {
            return false;
        }
        $summary = $this->summary($routeId);

        return in_array((int) $routeStop->stop_order, [$summary['first'], $summary['last']], true);
    }

    public function forget(int $routeId): void
    {
        unset($this->summaries[$routeId]);
    }

    /** @return array{marked: bool, first: int|null, last: int|null} */
    private function summary(int $routeId): array
    {
        return $this->summaries[$routeId] ??= $this->routeStops->expressSummary($routeId);
    }
}
