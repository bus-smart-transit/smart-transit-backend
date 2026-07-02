<?php

namespace App\Repositories;

use App\Models\PassengerUser;

class RewardRepository
{
    private const PESOS_PER_POINT = 20; // 1 point per ₱20 spent — adjust as needed

    public function awardPoints(int $passengerId, float $amountSpent): void
    {
        $points = floor($amountSpent / self::PESOS_PER_POINT);
        if ($points <= 0) {
            return;
        }

        $passenger = PassengerUser::where('passenger_id', $passengerId)->first();
        if ($passenger) {
            $passenger->increment('reward_points', $points);
        }
    }

    public function redeemPoints(int $passengerId, int $points): bool
    {
        $passenger = PassengerUser::where('passenger_id', $passengerId)->first();
        if (!$passenger || $passenger->reward_points < $points) {
            return false;
        }

        $passenger->decrement('reward_points', $points);
        return true;
    }
}
