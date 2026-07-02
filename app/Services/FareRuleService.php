<?php

namespace App\Services;

use App\Repositories\FareMatrixRepository;
use App\Repositories\FareRuleRepository;

class FareRuleService
{
    private FareMatrixRepository $fareMatrixRepository;
    private FareRuleRepository $fareRuleRepository;

    public function __construct(
        FareMatrixRepository $fareMatrixRepository,
        FareRuleRepository $fareRuleRepository,
    ) {
    }

    // Fast read path — the only fare method TicketService should call.
    public function getFareForSegment(int $originStopId, int $destinationStopId, string $seatType): float
    {
        $fare = $this->fareMatrixRepository->findFare($originStopId, $destinationStopId, $seatType);

        if (!$fare) {
            throw new \RuntimeException('No fare configured for this route segment and seat type.');
        }

        return $fare->amount;
    }

    public function getFareRecordForSegment(int $originStopId, int $destinationStopId, string $seatType)
    {
        $fare = $this->fareMatrixRepository->findFare($originStopId, $destinationStopId, $seatType);

        if (!$fare) {
            throw new \RuntimeException('No fare configured for this route segment and seat type.');
        }

        return $fare; // gives TicketService the fare_id too, not just the amount
    }

    public function createFareRule(array $payload)
    {
        return $this->fareRuleRepository->create($payload);
    }
}
