<?php
namespace App\Services;

use App\Repositories\RewardRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RewardService
{
    // Public: ReportingRepository needs this to convert redeemed points back
    // into a peso value for financial reporting (see getPaymentChannelBreakdown()).
    public const PESOS_PER_POINT = 20; // earning: 1 point per ₱20 spent

    // Spending: 1 point takes ₱1 off an online checkout. Checkout and the financial report both read this one value
    // (the report used to value a redeemed point at the earning rate, ₱20, which overstated every discount twentyfold).
    public const REDEMPTION_PESOS_PER_POINT = 1;

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
        return DB::transaction(function () use ($passengerId, $points, $description, $paymentId) {
            // Atomic: false when the passenger no longer has the points (another checkout got there first).
            if (! $this->rewardRepository->decrementPointsIfEnough($passengerId, $points)) {
                return false;
            }

            $this->rewardRepository->createTransaction([
                'passenger_id' => $passengerId,
                'payment_id' => $paymentId,
                'points' => -$points,
                'type' => 'redeemed',
                'description' => $description ?? 'Points redeemed',
            ]);

            return true;
        });
    }

    /**
     * Gives back the points a checkout took when its payment did not go through (failed, or the hold expired).
     * The ledger stays signed: the return is a positive 'redeemed' row, so the payment's rows add up to zero. Safe to
     * call twice: the points come back once. Returns how many points were given back (0 when none were outstanding).
     */
    public function returnRedeemedPoints(int $paymentId): int
    {
        return DB::transaction(function () use ($paymentId) {
            $rows = $this->rewardRepository->redemptionRowsForPayment($paymentId);
            $outstanding = -1 * (int) $rows->sum('points');

            if ($outstanding <= 0) {
                return 0;
            }

            $passengerId = (int) $rows->first()->passenger_id;
            $this->rewardRepository->incrementPoints($passengerId, $outstanding);
            $this->rewardRepository->createTransaction([
                'passenger_id' => $passengerId,
                'payment_id' => $paymentId,
                'points' => $outstanding,
                'type' => 'redeemed',
                'description' => 'Points returned: the payment did not go through',
            ]);

            return $outstanding;
        });
    }

    /**
     * A payment that arrives after its hold expired gets its tickets only if the points it used can be taken again.
     * True when there is nothing to take or they were taken; false when the passenger no longer has them.
     */
    public function reclaimRedeemedPoints(int $paymentId): bool
    {
        $rows = $this->rewardRepository->redemptionRowsForPayment($paymentId);
        $original = abs((int) $rows->min('points'));

        if ($rows->isEmpty() || $original <= 0 || -1 * (int) $rows->sum('points') > 0) {
            return true; // no points were used, or they are still held
        }

        return $this->redeemPoints((int) $rows->first()->passenger_id, $original, 'Redeemed during checkout (payment confirmed late)', $paymentId);
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