<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\TripAssignmentRequestService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Operator side of C6: review driver/Chauffeur requests to be released from a
 * trip assignment ("For Approval").
 */
class TripAssignmentRequestController extends Controller
{
    use ApiResponse;

    public function __construct(private TripAssignmentRequestService $service) {}

    public function index(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $requests = $this->service->pendingForOperator($companyUser->company_user_id);

        return $this->success([
            'requests' => $requests,
            'pending_count' => $requests->count(),
        ], 'Requests retrieved successfully');
    }

    public function approve(Request $request, int $requestId)
    {
        return $this->decide($request, $requestId, 'approve', 'Request approved. The staff member was released and the trip needs a replacement.');
    }

    public function reject(Request $request, int $requestId)
    {
        return $this->decide($request, $requestId, 'reject', 'Request rejected. The staff member stays assigned and was notified.');
    }

    private function decide(Request $request, int $requestId, string $decision, string $message)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $result = $this->service->decide($requestId, $decision, $companyUser->company_user_id);
        AuditLogger::log('trip.decline_'.$decision, 'Trip', $result->trip_id, ['request_id' => $requestId]);

        return $this->success($result, $message);
    }
}
