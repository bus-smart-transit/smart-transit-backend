<?php

namespace App\Http\Controllers;

use App\Models\Fleet;
use App\Services\ReportingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ReportingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ReportingService $reportingService,
    ) {}

    /**
     * Ensure fleet exists or abort with 404
     */
    private function ensureFleetExists(int $fleetId): Fleet
    {
        $fleet = Fleet::find($fleetId);
        
        if (!$fleet) {
            abort(404, "Fleet with ID {$fleetId} not found");
        }
        
        return $fleet;
    }

    /**
     * Get financial audit for fleet (operator/admin only)
     * GET /api/operator/fleets/{fleetId}/reports/financial
     * Query params: start_date, end_date (YYYY-MM-DD, optional)
     */
    public function financialAudit(Request $request, int $fleetId)
    {
        $this->ensureFleetExists($fleetId);
        
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->reportingService->getFinancialAudit(
            $fleetId,
            $request->input('start_date'),
            $request->input('end_date')
        );

        return $this->success($report, 'Financial audit retrieved successfully');
    }

    /**
     * Get revenue breakdown by route
     * GET /api/operator/fleets/{fleetId}/reports/revenue-by-route
     * Query params: start_date, end_date (YYYY-MM-DD, optional)
     */
    public function revenueByRoute(Request $request, int $fleetId)
    {
        $this->ensureFleetExists($fleetId);
        
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->reportingService->getRevenueByRoute(
            $fleetId,
            $request->input('start_date'),
            $request->input('end_date')
        );

        return $this->success($report, 'Revenue by route retrieved successfully');
    }

    /**
     * Get route adherence (on-time performance)
     * GET /api/operator/fleets/{fleetId}/reports/route-adherence
     * Query params: start_date, end_date (YYYY-MM-DD, optional)
     */
    public function routeAdherence(Request $request, int $fleetId)
    {
        $this->ensureFleetExists($fleetId);
        
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->reportingService->getRouteAdherence(
            $fleetId,
            $request->input('start_date'),
            $request->input('end_date')
        );

        return $this->success($report, 'Route adherence report retrieved successfully');
    }

    /**
     * Get occupancy trends
     * GET /api/operator/fleets/{fleetId}/reports/occupancy-trends
     * Query params: start_date, end_date (YYYY-MM-DD, optional)
     */
    public function occupancyTrends(Request $request, int $fleetId)
    {
        $this->ensureFleetExists($fleetId);
        
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->reportingService->getOccupancyTrends(
            $fleetId,
            $request->input('start_date'),
            $request->input('end_date')
        );

        return $this->success($report, 'Occupancy trends retrieved successfully');
    }

    /**
     * Get daily summary
     * GET /api/operator/fleets/{fleetId}/reports/daily-summary
     * Query params: date (YYYY-MM-DD, optional, defaults to today)
     */
    public function dailySummary(Request $request, int $fleetId)
    {
        $this->ensureFleetExists($fleetId);
        
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->reportingService->getDailySummary(
            $fleetId,
            $request->input('date')
        );

        return $this->success($report, 'Daily summary retrieved successfully');
    }

    /**
     * Get payment channel breakdown
     * GET /api/operator/fleets/{fleetId}/reports/payment-channels
     * Query params: start_date, end_date (YYYY-MM-DD, optional)
     */
    public function paymentChannels(Request $request, int $fleetId)
    {
        $this->ensureFleetExists($fleetId);
        
        $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $report = $this->reportingService->getPaymentChannelBreakdown(
            $fleetId,
            $request->input('start_date'),
            $request->input('end_date')
        );

        return $this->success($report, 'Payment channel breakdown retrieved successfully');
    }
}
