<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailySummaryRequest;
use App\Http\Requests\FleetReportRequest;
use App\Services\ReportingService;
use App\Traits\ApiResponse;

class ReportingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ReportingService $reportingService,
    ) {}

    public function financialAudit(FleetReportRequest $request, int $fleetId)
    {
        $report = $this->reportingService->getFinancialAudit(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Financial audit retrieved successfully');
    }

    public function revenueByRoute(FleetReportRequest $request, int $fleetId)
    {
        $report = $this->reportingService->getRevenueByRoute(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Revenue by route retrieved successfully');
    }

    public function routeAdherence(FleetReportRequest $request, int $fleetId)
    {
        $report = $this->reportingService->getRouteAdherence(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Route adherence report retrieved successfully');
    }

    public function occupancyTrends(FleetReportRequest $request, int $fleetId)
    {
        $report = $this->reportingService->getOccupancyTrends(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Occupancy trends retrieved successfully');
    }

    public function dailySummary(DailySummaryRequest $request, int $fleetId)
    {
        $report = $this->reportingService->getDailySummary(
            $fleetId,
            $request->validated('date')
        );

        return $this->success($report, 'Daily summary retrieved successfully');
    }

    public function paymentChannels(FleetReportRequest $request, int $fleetId)
    {
        $report = $this->reportingService->getPaymentChannelBreakdown(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Payment channel breakdown retrieved successfully');
    }
}
