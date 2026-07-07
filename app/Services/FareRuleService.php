<?php
namespace App\Services;
use App\Repositories\FareMatrixRepository;
use App\Repositories\FareRuleRepository;

class FareRuleService
{
    public function __construct(
        private FareMatrixRepository $fareMatrixRepository,
        private FareRuleRepository $fareRuleRepository,
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

    // $payload = ['fleet_id','base_fare','fare_per_km','seat_type']
    public function createFareRule(array $payload): object
    {
        return $this->fareRuleRepository->create(array_merge(
            $payload,
            ['status' => 'active']
        ));
    }
}
