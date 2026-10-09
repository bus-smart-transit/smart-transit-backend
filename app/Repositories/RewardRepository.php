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

    /**
     * One atomic statement: the points are taken only if the passenger still has them at this moment, so two
     * checkouts at the same time cannot both spend the same points. False when there were not enough.
     */
    public function decrementPointsIfEnough(int $passengerId, int $points): bool
    {
        return PassengerUser::where('passenger_id', $passengerId)
            ->where('reward_points', '>=', $points)
            ->decrement('reward_points', $points) > 0;
    }

    /** Every redeemed row of one payment: negative = points taken at checkout, positive = points given back. */
    public function redemptionRowsForPayment(int $paymentId): Collection
    {
        return RewardTransaction::where('type', 'redeemed')
            ->where('payment_id', $paymentId)
            ->get();
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