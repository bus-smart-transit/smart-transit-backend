<?php
namespace App\Repositories;

use App\Models\PassengerUser;
use App\Models\RewardTransaction;
use Illuminate\Support\Collection;

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

    public function createTransaction(array $payload): RewardTransaction
    {
        return RewardTransaction::create($payload);
    }

    public function findHistoryForPassenger(int $passengerId): Collection
    {
        return RewardTransaction::with('payment')
            ->where('passenger_id', $passengerId)
            ->latest()
            ->get();
    }
}