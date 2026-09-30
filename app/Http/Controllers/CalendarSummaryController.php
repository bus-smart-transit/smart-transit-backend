<?php

namespace App\Http\Controllers;

use App\Services\CalendarSummaryService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * C8: one aggregate endpoint for Driver, Chauffeur and Operator. The scope is
 * always the authenticated user's own role and staff id; nothing is taken
 * from the request.
 */
class CalendarSummaryController extends Controller
{
    use ApiResponse;

    public function __construct(private CalendarSummaryService $service) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $companyUser = $user?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success(
            $this->service->summaryFor($user->role, $companyUser->company_user_id),
            'Calendar summary retrieved successfully'
        );
    }
}
