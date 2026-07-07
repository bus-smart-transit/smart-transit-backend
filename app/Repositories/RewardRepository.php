<?php
namespace App\Repositories;

use App\Models\PassengerUser;

class RewardRepository
{
    public function incrementPoints(int $passengerId, int $points): void
    {
        PassengerUser::where('passenger_id', $passengerId)
            ->increment('reward_points', $points);
    }

    public function decrementPoints(int $passengerId, int $points): void
    {
        PassengerUser::where('passenger_id', $passengerId)
            ->decrement('reward_points', $points);
    }

    public function getPoints(int $passengerId): float
    {
        return PassengerUser::where('passenger_id', $passengerId)
            ->value('reward_points') ?? 0;
    }
}