<?php

namespace App\Http\Controllers;

use App\Services\AdminOverviewService;
use App\Services\SystemHealthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/** Batch 48 Phase 2: GET /api/admin/overview (Super Admin only, by the route group). */
class AdminOverviewController extends Controller
{
    use ApiResponse;

    public function __construct(private AdminOverviewService $overview) {}

    public function show(): JsonResponse
    {
        return $this->success($this->overview->summary(), 'Overview retrieved');
    }

    /** Batch 48 Phase 6: GET /api/admin/health (read only, no secrets). */
    public function health(SystemHealthService $health): JsonResponse
    {
        return $this->success($health->summary(), 'System health retrieved');
    }
}
