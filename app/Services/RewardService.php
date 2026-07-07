<?php
namespace App\Services;

use App\Repositories\RewardRepository;

class RewardService
{
    private const PESOS_PER_POINT = 20; // 1 point per ₱20 spent

    public function __construct(private RewardRepository $rewardRepository)
    {
    }

    /**
     * Called automatically inside TicketService::issueTicket()
     * after a successful ticket purchase. Never called from a controller directly.
     */
    public function awardPoints(int $passengerId, float $amountSpent): void
    {
        $points = (int) floor($amountSpent / self::PESOS_PER_POINT);

        if ($points <= 0)
            return;

        $this->rewardRepository->incrementPoints($passengerId, $points);
    }

    public function redeemPoints(int $passengerId, int $points): bool
    {
        $currentPoints = $this->rewardRepository->getPoints($passengerId);

        if ($currentPoints < $points) {
            return false;
        }

        $this->rewardRepository->decrementPoints($passengerId, $points);
        return true;
    }
}