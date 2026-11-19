<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\ShiftBlockAssignmentService;
use App\Services\TripAssignmentRequestService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * Batch 28 (item 1): the shift block as one card. Driver / Chauffeur list their cards and
 * accept or decline a whole block once; the Operator lists the block cards with both roles' state.
 * Everything is scoped to the signed-in staff member's own id: the role comes from the route
 * group, never from the request.
 */
class AssignmentController extends Controller
{
    use ApiResponse;

    public function __construct(private ShiftBlockAssignmentService $assignments) {}

    public function indexAsDriver(Request $request)
    {
        return $this->index($request, 'driver');
    }

    public function indexAsConductor(Request $request)
    {
        return $this->index($request, 'conductor');
    }

    public function acceptAsDriver(Request $request, string $key)
    {
        return $this->accept($request, $key, 'driver');
    }

    public function acceptAsConductor(Request $request, string $key)
    {
        return $this->accept($request, $key, 'conductor');
    }

    public function declineAsDriver(Request $request, string $key)
    {
        return $this->decline($request, $key, 'driver');
    }

    public function declineAsConductor(Request $request, string $key)
    {
        return $this->decline($request, $key, 'conductor');
    }

    public function operatorBlocks(Request $request)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->assignments->operatorBlockCards($companyUser->company_user_id), 'Shift blocks retrieved successfully');
    }

    private function index(Request $request, string $role)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        return $this->success($this->assignments->cardsFor($role, $companyUser->company_user_id), 'Assignments retrieved successfully');
    }

    private function accept(Request $request, string $key, string $role)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $card = $this->assignments->accept($key, $role, $companyUser->company_user_id);
        AuditLogger::log('assignment.accepted', 'Assignment', null, ['key' => $key, 'role' => $role]);

        return $this->success($card, 'Assignment accepted.');
    }

    private function decline(Request $request, string $key, string $role)
    {
        $companyUser = $request->user()?->companyProfile;
        if (! $companyUser) {
            return $this->error('Staff profile not found.', 404);
        }

        $validated = $request->validate([
            'reason_code' => ['nullable', 'string', 'in:'.implode(',', TripAssignmentRequestService::REASON_CODES)],
            'reason_text' => ['nullable', 'string', 'max:'.TripAssignmentRequestService::REASON_TEXT_MAX, 'required_if:reason_code,other'],
        ]);

        $card = $this->assignments->decline($key, $role, $companyUser->company_user_id, $validated['reason_code'] ?? null, $validated['reason_text'] ?? null);
        AuditLogger::log('assignment.decline_requested', 'Assignment', null, ['key' => $key, 'role' => $role, 'reason_code' => $validated['reason_code'] ?? null]);

        return $this->success($card, 'Request sent. It is now waiting for Operator approval.');
    }
}
