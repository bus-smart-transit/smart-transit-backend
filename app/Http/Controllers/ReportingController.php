<?php

namespace App\Http\Controllers;

use App\Http\Requests\DailySummaryRequest;
use App\Http\Requests\FleetReportRequest;
use App\Services\FleetService;
use App\Services\ReportingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class ReportingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ReportingService $reportingService,
        private FleetService $fleetService,
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

        $owned = $this->fleetService->isOwnedByOperator($fleetId, $companyUser->company_user_id);

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

    /**
     * Admin-only audit log viewer. Data access moved out of routes/api.php
     * (was a raw DB::table() closure there) into the Controller -> Service
     * -> Repository chain, matching the rest of the app's layering.
     */
    public function auditLogs(Request $request)
    {
        $logs = $this->reportingService->listAuditLogs([
            'action' => $request->query('action'),
            'actor_type' => $request->query('actor_type'),
            'actor_id' => $request->query('actor_id'),
            'subject_type' => $request->query('subject_type'),
            'subject_id' => $request->query('subject_id'),
        ], (int) $request->query('limit', 100));

        return $this->success($logs, 'Audit logs retrieved');
    }

    /**
     * Admin-only viewer for nightly-generated stored reports. Data access
     * moved out of routes/api.php (was a raw DB::table() closure there)
     * into the Controller -> Service -> Repository chain.
     */
    public function storedReports(Request $request)
    {
        $reports = $this->reportingService->listStoredReports([
            'fleet_id' => $request->query('fleet_id'),
            'report_type' => $request->query('report_type'),
            'date' => $request->query('date'),
            'status' => $request->query('status'),
        ], (int) $request->query('limit', 100));

        return $this->success($reports, 'Stored reports retrieved');
    }
}
