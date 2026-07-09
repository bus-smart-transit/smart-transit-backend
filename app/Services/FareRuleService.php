<?php
namespace App\Services;
use App\Repositories\FareMatrixRepository;
use App\Repositories\FareRuleRepository;
use App\Repositories\StopRepository;

class FareRuleService
{
    public function __construct(
        private FareMatrixRepository $fareMatrixRepository,
        private FareRuleRepository $fareRuleRepository,
        private StopRepository $stopRepository,
    ) {
    }

    // $payload = ['origin_stop_id','destination_stop_id','seat_type']
    // Fast indexed lookup — the only fare method TicketService calls at booking time
    public function getFareRecord(array $payload): object
    {
        $fare = $this->fareMatrixRepository->findFare(
            $payload['origin_stop_id'],
            $payload['destination_stop_id'],
            $payload['seat_type'],
        );

        if (!$fare) {
            throw new \RuntimeException('No fare configured for this route segment and seat type.');
        }

        return $fare; // returns fare_id + amount — both needed by TicketService
    }

    // $payload = ['origin_stop_id','destination_stop_id','seat_type']
    // Passenger-facing quote — returns amount only
    public function getQuote(array $payload): float
    {
        return $this->getFareRecord($payload)->amount;
    }

    /**
     * GPS-based quote. Snaps passenger's raw coordinates to the nearest
     * known stop, then delegates to the SAME fare_matrix lookup used by
     * onsite conductor payments and stop-based browsing. This guarantees
     * a passenger never gets a different fare than the conductor would
     * charge for boarding at that same physical location.
     *
     * $payload = [
     *   'origin_lat', 'origin_lng',
     *   'destination_lat', 'destination_lng',
     *   'seat_type',
     *   'route_id' (optional but recommended — scopes matching to your
     *               Davao<->Tagum route so GPS noise can't snap to a stop
     *               on an unrelated route)
     * ]
     */
    public function getQuoteFromCoordinates(array $payload): float
    {
        $originStop = $this->stopRepository->findNearestStop(
            $payload['origin_lat'],
            $payload['origin_lng'],
            $payload['route_id'] ?? null,
        );

        $destinationStop = $this->stopRepository->findNearestStop(
            $payload['destination_lat'],
            $payload['destination_lng'],
            $payload['route_id'] ?? null,
        );

        if (!$originStop) {
            throw new \RuntimeException('Could not match your pickup location to a known stop on this route.');
        }

        if (!$destinationStop) {
            throw new \RuntimeException('Could not match your drop-off location to a known stop on this route.');
        }

        if ($originStop->stop_id === $destinationStop->stop_id) {
            throw new \RuntimeException('Pickup and drop-off resolved to the same stop.');
        }

        // Reuses the exact same read path as conductor/browsing lookups —
        // no separate calculation, no new fare_matrix writes.
        return $this->getQuote([
            'origin_stop_id' => $originStop->stop_id,
            'destination_stop_id' => $destinationStop->stop_id,
            'seat_type' => $payload['seat_type'],
        ]);
    }

    // $payload = ['fleet_id','base_fare','fare_per_km','seat_type']
    public function createFareRule(array $payload): object
    {
        return $this->fareRuleRepository->create(array_merge(
            $payload,
            ['status' => 'active']
        ));
    }
}
