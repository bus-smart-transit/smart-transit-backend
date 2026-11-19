<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetricsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/** Batch 81 (user, 2026-11-19): the Operator dashboard's On-time trips (today and the same weekday last week). */
class DashboardMetricsController extends Controller
{
    use ApiResponse;

    public function __construct(private DashboardMetricsService $metrics) {}

    public function onTime(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->metrics->onTime((int) $companyUser->company_user_id), 'On-time trips retrieved successfully');
    }
}
