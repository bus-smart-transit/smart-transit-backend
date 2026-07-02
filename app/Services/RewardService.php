<?php

namespace App\Services;

use App\Models\PassengerUser;
use App\Repositories\RewardRepository;

class RewardService
{
    private RewardRepository $rewardRepository;
    public function __construct(RewardRepository $rewardRepository)
    {
        $this->rewardRepository = $rewardRepository;
    }

    public function awardPoints(int $passengerId, float $amountSpent): void
    {
        $this->rewardRepository->awardPoints($passengerId, $amountSpent);
    }

    public function redeemPoints(int $passengerId, int $points): bool
    {
        return $this->rewardRepository->redeemPoints($passengerId, $points);
        return true;
    }
}
