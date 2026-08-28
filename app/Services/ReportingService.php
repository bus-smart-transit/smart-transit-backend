<?php

namespace App\Services;

use App\Repositories\FleetRepository;
use App\Repositories\ReportingRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ReportingService
{
    public function __construct(
        private ReportingRepository $reportingRepository,
        private FleetRepository $fleetRepository,
    ) {}

    /**
     * Verify fleet exists. Throws ModelNotFoundException (auto-resolved as 404) if not.
     */
    private function ensureFleetExists(int $fleetId): void
    {
        if (!$this->fleetRepository->findById($fleetId)) {
            throw (new ModelNotFoundException())->setModel('Fleet', $fleetId);
        }
    }

    /**
     * Get complete financial audit for a fleet
     */
    public function getFinancialAudit(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->ensureFleetExists($fleetId);
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return $this->reportingRepository->getFleetFinancialSummary($fleetId, $startDate, $endDate);
    }

    /**
     * Get revenue breakdown by route
     */
    public function getRevenueByRoute(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->ensureFleetExists($fleetId);
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->toDateString();

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
        $this->ensureFleetExists($fleetId);
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return $this->reportingRepository->getRouteAdherence($fleetId, $startDate, $endDate);
    }

    /**
     * Get occupancy trends
     */
    public function getOccupancyTrends(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->ensureFleetExists($fleetId);
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->toDateString();

        $this->validateDateRange($startDate, $endDate);

        return $this->reportingRepository->getOccupancyTrends($fleetId, $startDate, $endDate);
    }

    /**
     * Get daily summary
     */
    public function getDailySummary(int $fleetId, ?string $date = null): array
    {
        $this->ensureFleetExists($fleetId);
        $date = $date ?? Carbon::now()->toDateString();

        return $this->reportingRepository->getDailySummary($fleetId, $date);
    }

    /**
     * Get payment channel breakdown
     */
    public function getPaymentChannelBreakdown(int $fleetId, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->ensureFleetExists($fleetId);
        $startDate = $startDate ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate = $endDate ?? Carbon::now()->toDateString();

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
