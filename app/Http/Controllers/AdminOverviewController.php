<?php

namespace App\Http\Controllers;

use App\Services\AdminOverviewService;
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
}
