<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCrewMemberRequest;
use App\Services\AuditLogger;
use App\Services\StaffDirectoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 46: the Operator's Staff Directory (operator/staff). Operator only (route middleware). The company always
 * comes from the signed-in account, never from the request.
 */
class StaffDirectoryController extends Controller
{
    use ApiResponse;

    public function __construct(private StaffDirectoryService $directory) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'in:driver,conductor,operator'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        return $this->success(
            $this->directory->page($this->companyId($request), $validated['search'] ?? null, $validated['role'] ?? null, (int) ($validated['page'] ?? 1), $this->actorId($request)),
            'Staff retrieved successfully',
        );
    }

    public function update(UpdateCrewMemberRequest $request, int $companyUserId): JsonResponse
    {
        $result = $this->directory->update($this->companyId($request), $companyUserId, $request->validated(), $this->actorId($request));
        if ($result['changed'] !== []) {
            AuditLogger::log('staff.update', 'StaffUser', $companyUserId, StaffDirectoryService::auditContext($result['member'], $result['changed']));
        }

        return $this->success($result['member'], $result['changed'] === [] ? 'Nothing to change.' : 'Staff details saved.');
    }

    public function deactivate(Request $request, int $companyUserId): JsonResponse
    {
        $member = $this->directory->deactivate($this->companyId($request), $companyUserId, $this->actorId($request));
        AuditLogger::log('staff.deactivate', 'StaffUser', $companyUserId);

        return $this->success($member, 'Account turned off. They can no longer sign in.');
    }

    public function reactivate(Request $request, int $companyUserId): JsonResponse
    {
        $member = $this->directory->reactivate($this->companyId($request), $companyUserId, $this->actorId($request));
        AuditLogger::log('staff.reactivate', 'StaffUser', $companyUserId);

        return $this->success($member, 'Account turned on again. They can sign in.');
    }

    /** The signed-in account (never taken from the request body). */
    private function actorId(Request $request): int
    {
        return (int) $request->user()->user_id;
    }

    private function companyId(Request $request): int
    {
        return (int) ($request->user()?->companyProfile?->company_id ?? 0);
    }
}
