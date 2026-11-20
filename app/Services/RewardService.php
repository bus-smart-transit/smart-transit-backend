<?php

namespace App\Services;

use App\Repositories\RewardRepository;
use Illuminate\Support\Facades\DB;

class RewardService
{
    // Public: ReportingRepository needs this to convert redeemed points back
    // into a peso value for financial reporting (see getPaymentChannelBreakdown()).
    public const PESOS_PER_POINT = 20; // earning: DECIMAL_RATE points per ₱20 spent

    // 2026-11-20 (user): points earned per PESOS_PER_POINT paid, so ₱130 earns (130 / 20) * 0.1 = 0.65 points.
    public const DECIMAL_RATE = 0.1;

    // Spending: 1 point takes ₱1 off an online checkout. Checkout and the financial report both read this one value
    // (the report used to value a redeemed point at the earning rate, ₱20, which overstated every discount twentyfold).
    public const REDEMPTION_PESOS_PER_POINT = 1;

    public function __construct(private RewardRepository $rewardRepository) {}

    /**
     * Called automatically inside TicketService::finalizeTicket() after a
     * successful ticket purchase. Never called from a controller directly.
     */
    public function awardPoints(int $passengerId, float $amountSpent, ?int $paymentId = null): void
    {
        $points = self::pointsFor($amountSpent);

        if ($points <= 0) {
            return;
        }

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

    /**
     * 2026-11-20 (user): the ONE earning rule. Points for an amount paid: (amount / PESOS_PER_POINT) * DECIMAL_RATE,
     * kept to two decimals (₱130 -> 0.65, ₱152 -> 0.76). Earning and taking points back on a refunded discount both
     * use it, so a refund never takes back points that were not earned.
     */
    public static function pointsFor(float $amount): float
    {
        return $amount > 0 ? round(($amount / self::PESOS_PER_POINT) * self::DECIMAL_RATE, 2) : 0.0;
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
            $passengerId = (int) ($this->rewardRepository->redemptionRowsForPayment($paymentId)->first()->passenger_id ?? 0);
            if ($passengerId === 0) {
                return 0;
            }

            // 2026-11-04 (Todo 3 finding 5): a failed-payment event and the hold-expiry job can finish at the same
            // moment. The passenger's points row is locked first and the ledger read again after it, so the second
            // caller sees the first one's return and gives nothing back twice.
            $this->rewardRepository->lockPassengerPoints($passengerId);
            $outstanding = -1 * (int) $this->rewardRepository->redemptionRowsForPayment($paymentId)->sum('points');

            if ($outstanding <= 0) {
                return 0;
            }

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
        return DB::transaction(function () use ($paymentId) {
            $passengerId = (int) ($this->rewardRepository->redemptionRowsForPayment($paymentId)->first()->passenger_id ?? 0);
            if ($passengerId === 0) {
                return true; // no points were used
            }

            // Same lock as returnRedeemedPoints, so a reclaim and a return never both act on old numbers.
            $this->rewardRepository->lockPassengerPoints($passengerId);
            $rows = $this->rewardRepository->redemptionRowsForPayment($paymentId);
            $original = abs((int) $rows->min('points'));

            if ($original <= 0 || -1 * (int) $rows->sum('points') > 0) {
                return true; // no points were used, or they are still held
            }

            return $this->redeemPoints($passengerId, $original, 'Redeemed during checkout (payment confirmed late)', $paymentId);
        });
    }

    public function getHistory(int $passengerId, int $page = 1, int $perPage = 50): object
    {
        return $this->rewardRepository->findHistoryForPassenger($passengerId, $perPage, $page);
    }

    public function getPoints(int $passengerId): int
    {
        return (int) floor($this->rewardRepository->getPoints($passengerId));
    }
}
