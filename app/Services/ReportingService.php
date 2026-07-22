<?php

namespace App\Services;

use App\Repositories\ReportingRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReportingService
{
    public function __construct(
        private ReportingRepository $reportingRepository,
    ) {}

    /**
     * Get complete financial audit for a fleet
     */
    public function getFinancialAudit(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->endOfMonth()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return $this->reportingRepository->getFleetFinancialSummary($fleetId, $startDate, $endDate);
    }

    /**
     * Get revenue breakdown by route
     */
    public function getRevenueByRoute(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->endOfMonth()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return [
            'fleet_id' => $fleetId,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'routes' => $this->reportingRepository->getRevenueByRoute($fleetId, $startDate, $endDate)->toArray(),
        ];
    }

    /**
     * Get route adherence (on-time performance)
     */
    public function getRouteAdherence(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->endOfMonth()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return $this->reportingRepository->getRouteAdherence($fleetId, $startDate, $endDate);
    }

    /**
     * Get occupancy trends
     */
    public function getOccupancyTrends(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->endOfMonth()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return $this->reportingRepository->getOccupancyTrends($fleetId, $startDate, $endDate);
    }

    /**
     * Get daily summary
     */
    public function getDailySummary(int $fleetId, ?string $date = null): array
    {
        $date = $date ?? Carbon::now()->toDateString();

        if (!$this->isValidDate($date)) {
            throw ValidationException::withMessages([
                'date' => ['Invalid date format. Use YYYY-MM-DD.'],
            ]);
        }

        return $this->reportingRepository->getDailySummary($fleetId, $date);
    }

    /**
     * Get payment channel breakdown
     */
    public function getPaymentChannelBreakdown(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->endOfMonth()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return $this->reportingRepository->getPaymentChannelBreakdown($fleetId, $startDate, $endDate);
    }

    private function validateDateRange(string $startDate, string $endDate): void
    {
        if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
            throw ValidationException::withMessages([
                'dates' => ['Invalid date format. Use YYYY-MM-DD.'],
            ]);
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            throw ValidationException::withMessages([
                'dates' => ['Start date must be before end date.'],
            ]);
        }

        if (strtotime($endDate) > strtotime(Carbon::now()->toDateString())) {
            throw ValidationException::withMessages([
                'dates' => ['End date cannot be in the future.'],
            ]);
        }
    }

    private function isValidDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false;
    }
}
