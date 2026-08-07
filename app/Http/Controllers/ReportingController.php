<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailySummaryRequest;
use App\Http\Requests\FleetReportRequest;
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
     * Verify the requesting operator owns the fleet.
     * Prevents IDOR — an operator cannot read another operator's financial data
     * by changing the fleet_id parameter in the request.
     */
    private function assertFleetOwnership(Request $request, int $fleetId): void
    {
        $companyUser = $request->user()?->companyProfile;

        if (!$companyUser) {
            abort(404, 'Staff profile not found.');
        }

        // Admin role bypasses fleet ownership check — they have full visibility.
        if ($request->user()?->role === 'admin') {
            return;
        }

        $owned = Fleet::where('fleet_id', $fleetId)
            ->where('company_user_id', $companyUser->company_user_id)
            ->exists();

        if (!$owned) {
            abort(403, 'You do not have access to reports for this fleet.');
        }
    }

    public function financialAudit(FleetReportRequest $request, int $fleetId)
    {
        $this->assertFleetOwnership($request, $fleetId);
        $report = $this->reportingService->getFinancialAudit(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Financial audit retrieved successfully');
    }

    public function revenueByRoute(FleetReportRequest $request, int $fleetId)
    {
        $this->assertFleetOwnership($request, $fleetId);
        $report = $this->reportingService->getRevenueByRoute(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Revenue by route retrieved successfully');
    }

    public function routeAdherence(FleetReportRequest $request, int $fleetId)
    {
        $this->assertFleetOwnership($request, $fleetId);
        $report = $this->reportingService->getRouteAdherence(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Route adherence report retrieved successfully');
    }

    public function occupancyTrends(FleetReportRequest $request, int $fleetId)
    {
        $this->assertFleetOwnership($request, $fleetId);
        $report = $this->reportingService->getOccupancyTrends(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Occupancy trends retrieved successfully');
    }

    public function dailySummary(DailySummaryRequest $request, int $fleetId)
    {
        $this->assertFleetOwnership($request, $fleetId);
        $report = $this->reportingService->getDailySummary(
            $fleetId,
            $request->validated('date')
        );

        return $this->success($report, 'Daily summary retrieved successfully');
    }

    public function paymentChannels(FleetReportRequest $request, int $fleetId)
    {
        $this->assertFleetOwnership($request, $fleetId);
        $report = $this->reportingService->getPaymentChannelBreakdown(
            $fleetId,
            $request->validated('start_date'),
            $request->validated('end_date')
        );

        return $this->success($report, 'Payment channel breakdown retrieved successfully');
    }
}
