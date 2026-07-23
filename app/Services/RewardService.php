<?php
namespace App\Services;

use App\Repositories\RewardRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RewardService
{
    private const PESOS_PER_POINT = 20; // 1 point per ₱20 spent

    public function __construct(private RewardRepository $rewardRepository)
    {
    }

    /**
     * Called automatically inside TicketService::finalizeTicket() after a
     * successful ticket purchase. Never called from a controller directly.
     */
    public function awardPoints(int $passengerId, float $amountSpent, ?int $paymentId = null): void
    {
        $points = (int) floor($amountSpent / self::PESOS_PER_POINT);

        if ($points <= 0)
            return;

        DB::transaction(function () use ($passengerId, $points, $paymentId) {
            $this->rewardRepository->incrementPoints($passengerId, $points);

            $this->rewardRepository->createTransaction([
                'passenger_id' => $passengerId,
                'payment_id' => $paymentId,
                'points' => $points,
                'type' => 'earned',
                'description' => 'Earned from ticket purchase',
            ]);
        });
    }

    public function redeemPoints(int $passengerId, int $points, ?string $description = null, ?int $paymentId = null): bool
    {
        $currentPoints = $this->rewardRepository->getPoints($passengerId);

        if ($currentPoints < $points) {
            return false;
        }

        DB::transaction(function () use ($passengerId, $points, $description, $paymentId) {
            $this->rewardRepository->decrementPoints($passengerId, $points);

            $this->rewardRepository->createTransaction([
                'passenger_id' => $passengerId,
                'payment_id' => $paymentId,
                'points' => -$points,
                'type' => 'redeemed',
                'description' => $description ?? 'Points redeemed',
            ]);
        });

        return true;
    }

    public function getHistory(int $passengerId): Collection
    {
        return $this->rewardRepository->findHistoryForPassenger($passengerId);
    }

    public function getPoints(int $passengerId): int
    {
        return (int) floor($this->rewardRepository->getPoints($passengerId));
    }
}