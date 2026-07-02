<?php

namespace App\Repositories;

use App\Models\FareMatrix;

class FareMatrixRepository
{
    public function findFare(int $originStopId, int $destinationStopId, string $seatType): ?FareMatrix
    {
        return FareMatrix::where('origin_stop_id', $originStopId)
            ->where('destination_stop_id', $destinationStopId)
            ->where('seat_type', $seatType)
            ->where('status', 'active')
            ->first();
    }

    public function upsert(array $payload): FareMatrix
    {
        return FareMatrix::updateOrCreate(
            [
                'origin_stop_id' => $payload['origin_stop_id'],
                'destination_stop_id' => $payload['destination_stop_id'],
                'seat_type' => $payload['seat_type'],
                'fleet_id' => $payload['fleet_id'],
            ],
            $payload
        );
    }
}
